<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();
require_method('POST');

$pdo = db();
$user = require_session($pdo);
$uid = (int) $user['id'];
$username = (string) $user['username'];

$in = json_in();

// How many jumps this landing is worth. Client sends 10 for a Spike Pit
// hit (half-width hitbox, see index.html's SPIKE_HIT_MIN/MAX) and 1 for
// everything else; anything outside that set is client error/tampering,
// so it's clamped back to the baseline rather than trusted outright.
$points = (int) ($in['points'] ?? 1);
if (!in_array($points, [1, 10], true)) {
    $points = 1;
}

// A counted jump has to present the nonce /api/jump-start.php issued for
// it, and enough real time has to have passed since — see
// JUMP_ATTEMPT_MIN/MAX_SECONDS in lib.php. Without a matching, correctly-
// timed nonce there's nothing to redeem, which is what stops a bare POST
// here — no browser, no animation, no actual jump — from ever counting.
// Either way the nonce is one-time-use: consumed right away so it can't be
// replayed, valid or not.
$nonce = (string) ($in['nonce'] ?? '');
$pendingNonce = (string) $user['pending_jump_nonce'];
$elapsed = microtime(true) - (float) $user['pending_jump_started_at'];
$validAttempt = $nonce !== '' && $pendingNonce !== ''
    && hash_equals($pendingNonce, $nonce)
    && $elapsed >= JUMP_ATTEMPT_MIN_SECONDS
    && $elapsed <= JUMP_ATTEMPT_MAX_SECONDS;

$pdo->prepare("UPDATE users SET pending_jump_nonce = '', pending_jump_started_at = 0 WHERE id = ?")
    ->execute([$uid]);

if (!$validAttempt) {
    flag_cheating($pdo, $uid, $username);
    json_out(['error' => 'No valid jump in progress.', 'jumpCount' => CHEAT_PENALTY_JUMP_COUNT], 400);
}

if (request_rate_exceeded($pdo, $uid)) {
    flag_cheating($pdo, $uid, $username);
    json_out(['error' => 'Too many requests — flagged as cheating.', 'jumpCount' => CHEAT_PENALTY_JUMP_COUNT], 429);
}

$pdo->prepare('UPDATE users SET jump_count = jump_count + ?, last_jump_completed_at = ? WHERE id = ?')
    ->execute([$points, microtime(true), $uid]);

// Roll every upgrade the user doesn't already own. One in-flight jump can
// only ever add upgrades, never remove them, so this is safe to do inline.
$owned = array_column(owned_upgrades($pdo, $uid), 'key');
$newUpgrades = [];
foreach (UPGRADES as $key => $meta) {
    if (in_array($key, $owned, true)) continue;
    if (random_int(1, 1_000_000) <= (int) round($meta['chance'] * 1_000_000)) {
        $pdo->prepare('INSERT OR IGNORE INTO user_upgrades (user_id, upgrade_key, unlocked_at) VALUES (?, ?, ?)')
            ->execute([$uid, $key, time()]);
        $newUpgrades[] = $key;
        post_system_message($pdo, "{$username} has unlocked " . strtoupper($meta['name']) . "!");
    }
}

$stmt = $pdo->prepare('SELECT jump_count FROM users WHERE id = ?');
$stmt->execute([$uid]);

json_out([
    'ok' => true,
    'jumpCount' => (int) $stmt->fetchColumn(),
    'newUpgrades' => $newUpgrades,
]);
