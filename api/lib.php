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

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
        json_out(['error' => 'Server storage is not set up yet.'], 500);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        username        TEXT NOT NULL UNIQUE COLLATE NOCASE,
        pin_hash        TEXT NOT NULL,
        jump_count      INTEGER NOT NULL DEFAULT 0,
        failed_attempts INTEGER NOT NULL DEFAULT 0,
        locked_until    INTEGER NOT NULL DEFAULT 0,
        created_at      INTEGER NOT NULL
    )');
    return $pdo;
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
