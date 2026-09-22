<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();
require_method('POST');

$in = json_in();
$username = trim((string) ($in['username'] ?? ''));
$pin = (string) ($in['pin'] ?? '');

if (!valid_username($username)) {
    json_out(['error' => 'Names are 2-20 characters: letters, numbers, _ or -.'], 400);
}
if (!valid_pin($pin)) {
    json_out(['error' => 'PIN must be 4-8 digits.'], 400);
}

$pdo = db();

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? COLLATE NOCASE');
$stmt->execute([$username]);
if ($stmt->fetch()) {
    json_out(['error' => 'That name is taken. Try another, or log in with its PIN.'], 409);
}

$hash = password_hash($pin, PASSWORD_DEFAULT);
$stmt = $pdo->prepare('INSERT INTO users (username, pin_hash, created_at) VALUES (?, ?, ?)');
$stmt->execute([$username, $hash, time()]);

session_regenerate_id(true);
$_SESSION['uid'] = (int) $pdo->lastInsertId();
$_SESSION['username'] = $username;

json_out(['ok' => true, 'username' => $username, 'jumpCount' => 0]);
