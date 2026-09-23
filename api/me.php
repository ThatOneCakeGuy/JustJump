<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();

if (empty($_SESSION['uid'])) {
    json_out(['loggedIn' => false]);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT username, jump_count FROM users WHERE id = ?');
$stmt->execute([$_SESSION['uid']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION = [];
    session_destroy();
    json_out(['loggedIn' => false]);
}

json_out([
    'loggedIn' => true,
    'username' => $user['username'],
    'jumpCount' => (int) $user['jump_count'],
    'upgrades' => owned_upgrades($pdo, (int) $_SESSION['uid']),
]);
