<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();

// Clear the DB-side token too, not just the local cookie — otherwise a
// copied/stolen session cookie would still pass session_user()'s check
// after the real user "logged out".
if (!empty($_SESSION['uid'])) {
    db()->prepare("UPDATE users SET session_token = '' WHERE id = ?")->execute([(int) $_SESSION['uid']]);
}

$_SESSION = [];
session_destroy();

json_out(['ok' => true]);
