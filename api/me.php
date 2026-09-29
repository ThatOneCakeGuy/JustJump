<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();

$pdo = db();
// Soft check (session_user, not require_session): a session invalidated by
// logging in elsewhere just reports loggedIn:false here, like never having
// logged in, rather than erroring — the client already handles that.
$user = session_user($pdo);

if ($user === null) {
    json_out(['loggedIn' => false]);
}

json_out([
    'loggedIn' => true,
    'username' => $user['username'],
    'jumpCount' => (int) $user['jump_count'],
    'upgrades' => owned_upgrades($pdo, (int) $user['id']),
]);
