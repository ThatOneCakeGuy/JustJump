<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();
require_method('POST');

$pdo = db();
$ip = client_ip();

// Check the IP ban first, before touching the users table or running
// bcrypt — a banned IP costs almost nothing to reject.
$bannedFor = ip_login_banned($pdo, $ip);
if ($bannedFor !== null) {
    json_out(['error' => "Too many failed logins from your network. Try again in {$bannedFor}s."], 429);
}

$in = json_in();
$username = trim((string) ($in['username'] ?? ''));
$pin = (string) ($in['pin'] ?? '');

if (!valid_username($username) || !valid_pin($pin)) {
    json_out(['error' => 'Invalid name or PIN.'], 400);
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? COLLATE NOCASE');
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    record_ip_login_failure($pdo, $ip);
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
    record_ip_login_failure($pdo, $ip);
    json_out(['error' => 'Wrong PIN.'], 401);
}

clear_ip_login_failures($pdo, $ip);
$pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = 0 WHERE id = ?')->execute([$user['id']]);

session_regenerate_id(true);
$_SESSION['uid'] = (int) $user['id'];
$_SESSION['username'] = $user['username'];

json_out([
    'ok' => true,
    'username' => $user['username'],
    'jumpCount' => (int) $user['jump_count'],
    'upgrades' => owned_upgrades($pdo, (int) $user['id']),
]);
