<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();
require_method('POST');

$pdo = db();
$user = require_session($pdo);
$uid = (int) $user['id'];

$in = json_in();
$key = (string) ($in['key'] ?? '');

if (!array_key_exists($key, UPGRADES)) {
    json_out(['error' => 'unknown upgrade'], 400);
}

$stmt = $pdo->prepare('SELECT enabled FROM user_upgrades WHERE user_id = ? AND upgrade_key = ?');
$stmt->execute([$uid, $key]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row === false) {
    json_out(['error' => 'not unlocked'], 403);
}

$newEnabled = $row['enabled'] ? 0 : 1;
$pdo->prepare('UPDATE user_upgrades SET enabled = ? WHERE user_id = ? AND upgrade_key = ?')
    ->execute([$newEnabled, $uid, $key]);

json_out(['ok' => true, 'key' => $key, 'enabled' => (bool) $newEnabled]);
