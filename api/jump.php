<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();
require_method('POST');

if (empty($_SESSION['uid'])) {
    json_out(['error' => 'not logged in'], 401);
}

$pdo = db();
$uid = (int) $_SESSION['uid'];
$username = (string) $_SESSION['username'];

if (request_rate_exceeded($pdo, $uid)) {
    $pdo->prepare('UPDATE users SET jump_count = ? WHERE id = ?')
        ->execute([CHEAT_PENALTY_JUMP_COUNT, $uid]);

    $stmt = $pdo->prepare('SELECT last_cheat_flag_at FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    $now = time();
    if ($now - (int) $stmt->fetchColumn() >= CHEAT_ANNOUNCE_COOLDOWN) {
        $pdo->prepare('UPDATE users SET last_cheat_flag_at = ? WHERE id = ?')->execute([$now, $uid]);
        post_system_message($pdo, "{$username} got caught cheating and lost everything!", 'cheat');
    }

    json_out(['error' => 'Too many requests — flagged as cheating.', 'jumpCount' => CHEAT_PENALTY_JUMP_COUNT], 429);
}

$pdo->prepare('UPDATE users SET jump_count = jump_count + 1 WHERE id = ?')->execute([$uid]);

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
