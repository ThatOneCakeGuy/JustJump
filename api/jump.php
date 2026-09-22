<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();
require_method('POST');

if (empty($_SESSION['uid'])) {
    json_out(['error' => 'not logged in'], 401);
}

$pdo = db();
$pdo->prepare('UPDATE users SET jump_count = jump_count + 1 WHERE id = ?')->execute([$_SESSION['uid']]);

$stmt = $pdo->prepare('SELECT jump_count FROM users WHERE id = ?');
$stmt->execute([$_SESSION['uid']]);

json_out(['ok' => true, 'jumpCount' => (int) $stmt->fetchColumn()]);
