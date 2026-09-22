<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();
require_method('POST');

$in = json_in();
$username = trim((string) ($in['username'] ?? ''));
$pin = (string) ($in['pin'] ?? '');

if (!valid_username($username) || !valid_pin($pin)) {
    json_out(['error' => 'Invalid name or PIN.'], 400);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? COLLATE NOCASE');
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    json_out(['error' => 'No account with that name yet.'], 404);
}

$now = time();
if ((int) $user['locked_until'] > $now) {
    $wait = (int) $user['locked_until'] - $now;
    json_out(['error' => "Too many wrong PINs. Try again in {$wait}s."], 429);
}

if (!password_verify($pin, $user['pin_hash'])) {
    $fails = (int) $user['failed_attempts'] + 1;
    $lockedUntil = $fails >= 5 ? $now + 300 : 0;
    $upd = $pdo->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?');
    $upd->execute([$fails >= 5 ? 0 : $fails, $lockedUntil, $user['id']]);
    json_out(['error' => 'Wrong PIN.'], 401);
}

$pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = 0 WHERE id = ?')->execute([$user['id']]);

session_regenerate_id(true);
$_SESSION['uid'] = (int) $user['id'];
$_SESSION['username'] = $user['username'];

json_out(['ok' => true, 'username' => $user['username'], 'jumpCount' => (int) $user['jump_count']]);
