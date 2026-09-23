<?php
declare(strict_types=1);

// Persistent storage lives outside the webroot: deploy-local and deploy-site
// both rsync --delete into public_html, which would wipe a DB kept in the
// git working copy. The directory below must exist ahead of time, owned by
// the php-fpm user (nginx), created once via:
//   sudo install -d -o nginx -g nginx -m 750 /var/www/justjump.lol/data
const DATA_DIR = '/var/www/justjump.lol/data';
const DB_PATH = DATA_DIR . '/jump.sqlite';

function json_out(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function json_in(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw !== false ? $raw : '', true);
    return is_array($data) ? $data : [];
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('jjsess');
    session_start();
}

// SQLite ADD COLUMN has no "IF NOT EXISTS" before 3.35 here we just try and
// swallow "duplicate column" so this is safe to re-run against an existing
// production DB that predates the column.
function add_column_if_missing(PDO $pdo, string $table, string $columnDef): void {
    try {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$columnDef}");
    } catch (PDOException $e) {
        // already there — fine
    }
}

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
        json_out(['error' => 'Server storage is not set up yet.'], 500);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');
    // Without this, a concurrent writer throws "database is locked"
    // immediately instead of waiting briefly for the other write to finish —
    // busy under real concurrent traffic, not just rapid-fire abuse.
    $pdo->exec('PRAGMA busy_timeout = 10000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        username        TEXT NOT NULL UNIQUE COLLATE NOCASE,
        pin_hash        TEXT NOT NULL,
        jump_count      INTEGER NOT NULL DEFAULT 0,
        failed_attempts INTEGER NOT NULL DEFAULT 0,
        locked_until    INTEGER NOT NULL DEFAULT 0,
        created_at      INTEGER NOT NULL
    )');
    add_column_if_missing($pdo, 'users', 'last_chat_at INTEGER NOT NULL DEFAULT 0');
    // Fixed 1s window request counter for /api/jump.php abuse detection.
    add_column_if_missing($pdo, 'users', 'req_window_start REAL NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'users', 'req_window_count INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'users', 'last_cheat_flag_at INTEGER NOT NULL DEFAULT 0');
    ensure_upgrades_table($pdo);
    ensure_chat_table($pdo);
    ensure_ip_bans_table($pdo);
    return $pdo;
}

// ---- per-IP login abuse protection --------------------------------------
// A single client IP that fails a PIN login 20 times gets banned for 5
// minutes; any further attempt while still banned refreshes the ban another
// 5 minutes (so it only truly expires after 5 straight minutes of silence).
// Checked in login.php before any expensive work (DB lookup, bcrypt).
const LOGIN_FAIL_LIMIT = 20;
const LOGIN_BAN_SECONDS = 300;

function ensure_ip_bans_table(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS ip_bans (
        ip           TEXT PRIMARY KEY,
        fail_count   INTEGER NOT NULL DEFAULT 0,
        banned_until INTEGER NOT NULL DEFAULT 0
    )');
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Returns seconds remaining if this IP is currently banned — and refreshes
// the ban another LOGIN_BAN_SECONDS on this attempt — or null if not banned.
function ip_login_banned(PDO $pdo, string $ip): ?int {
    $now = time();
    $stmt = $pdo->prepare('SELECT banned_until FROM ip_bans WHERE ip = ?');
    $stmt->execute([$ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) $row['banned_until'] <= $now) {
        return null;
    }
    $pdo->prepare('UPDATE ip_bans SET banned_until = ? WHERE ip = ?')
        ->execute([$now + LOGIN_BAN_SECONDS, $ip]);
    return LOGIN_BAN_SECONDS;
}

// Call after any failed login attempt from this IP (wrong PIN or unknown
// username). Bans the IP once its failure count reaches LOGIN_FAIL_LIMIT.
function record_ip_login_failure(PDO $pdo, string $ip): void {
    $pdo->prepare('INSERT INTO ip_bans (ip, fail_count) VALUES (?, 1)
                   ON CONFLICT(ip) DO UPDATE SET fail_count = fail_count + 1')
        ->execute([$ip]);
    $stmt = $pdo->prepare('SELECT fail_count FROM ip_bans WHERE ip = ?');
    $stmt->execute([$ip]);
    if ((int) $stmt->fetchColumn() >= LOGIN_FAIL_LIMIT) {
        $pdo->prepare('UPDATE ip_bans SET banned_until = ?, fail_count = 0 WHERE ip = ?')
            ->execute([time() + LOGIN_BAN_SECONDS, $ip]);
    }
}

function clear_ip_login_failures(PDO $pdo, string $ip): void {
    $pdo->prepare('UPDATE ip_bans SET fail_count = 0, banned_until = 0 WHERE ip = ?')->execute([$ip]);
}

// Registry of unlockable upgrades: key => [chance per counted jump, display name].
// Add new upgrades here; jump.php rolls every locked one on each counted jump.
// Terrain reskins (terrain_*) share one combined 1% pool, split evenly across
// all 10 of them (0.001 each) — keep that division in sync if you add/remove one.
const UPGRADES = [
    'mega_ketchup'         => ['chance' => 0.01,  'name' => 'Mega Ketchup'],
    'splatter_control'     => ['chance' => 0.025, 'name' => 'Splatter Control'],
    'gravity_master'       => ['chance' => 0.025, 'name' => 'Gravity Master'],
    'terrain_fire'         => ['chance' => 0.001, 'name' => 'Fire'],
    'terrain_spike_pit'    => ['chance' => 0.001, 'name' => 'Spike Pit'],
    'terrain_lava'         => ['chance' => 0.001, 'name' => 'Lava'],
    'terrain_ice'          => ['chance' => 0.001, 'name' => 'Ice'],
    'terrain_toxic_slime'  => ['chance' => 0.001, 'name' => 'Toxic Slime'],
    'terrain_confetti'     => ['chance' => 0.001, 'name' => 'Confetti'],
    'terrain_bees'         => ['chance' => 0.001, 'name' => 'Bees'],
    'terrain_bubblegum'    => ['chance' => 0.001, 'name' => 'Bubblegum'],
    'terrain_electric'     => ['chance' => 0.001, 'name' => 'Electric'],
    'terrain_glitter'      => ['chance' => 0.001, 'name' => 'Glitter'],
];

// Fixed-window request throttle: more than MAX_REQ_PER_SEC requests to a
// throttled endpoint within any 1-second window gets treated as an API
// script hitting the endpoint directly rather than a human playing the game
// (a full jump animation cycle takes several seconds client-side, so no
// normal amount of clicking can trip this).
const MAX_REQ_PER_SEC = 5;
const CHEAT_PENALTY_JUMP_COUNT = 0;
const CHEAT_ANNOUNCE_COOLDOWN = 300; // only re-announce a repeat offender every 5 minutes

// Bumps the user's request counter and returns true if they've just gone
// over the limit. Callers should stop and penalize on a true return instead
// of doing their normal work for this request.
function request_rate_exceeded(PDO $pdo, int $uid): bool {
    $now = microtime(true);
    $stmt = $pdo->prepare('SELECT req_window_start, req_window_count FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $windowStart = (float) $row['req_window_start'];
    $count = (int) $row['req_window_count'];

    if ($now - $windowStart >= 1.0) {
        $windowStart = $now;
        $count = 1;
    } else {
        $count++;
    }

    $pdo->prepare('UPDATE users SET req_window_start = ?, req_window_count = ? WHERE id = ?')
        ->execute([$windowStart, $count, $uid]);

    return $count > MAX_REQ_PER_SEC;
}

function ensure_upgrades_table(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_upgrades (
        user_id     INTEGER NOT NULL,
        upgrade_key TEXT NOT NULL,
        unlocked_at INTEGER NOT NULL,
        PRIMARY KEY (user_id, upgrade_key)
    )');
    add_column_if_missing($pdo, 'user_upgrades', 'enabled INTEGER NOT NULL DEFAULT 1');
}

// Returns only the upgrades this user owns, as [{key, enabled}, ...].
// A key absent from the result is locked.
function owned_upgrades(PDO $pdo, int $uid): array {
    $stmt = $pdo->prepare('SELECT upgrade_key, enabled FROM user_upgrades WHERE user_id = ?');
    $stmt->execute([$uid]);
    return array_map(
        fn(array $r) => ['key' => $r['upgrade_key'], 'enabled' => (bool) $r['enabled']],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );
}

function ensure_chat_table(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS chat_messages (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        username   TEXT NOT NULL,
        message    TEXT NOT NULL,
        kind       TEXT NOT NULL DEFAULT "user",
        created_at INTEGER NOT NULL
    )');
}

function post_system_message(PDO $pdo, string $text, string $kind = 'system'): void {
    $pdo->prepare('INSERT INTO chat_messages (username, message, kind, created_at) VALUES (?, ?, ?, ?)')
        ->execute(['', $text, $kind, time()]);
}

function valid_username(string $u): bool {
    return (bool) preg_match('/^[A-Za-z0-9_-]{2,20}$/', $u);
}

function valid_pin(string $p): bool {
    return (bool) preg_match('/^[0-9]{4,8}$/', $p);
}

function require_method(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        json_out(['error' => 'method not allowed'], 405);
    }
}
