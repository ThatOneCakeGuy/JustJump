<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();
require_method('POST');

$pdo = db();
$user = require_session($pdo);
$uid = (int) $user['id'];

// Same burst guard jump.php's completion uses. A nonce alone earns nothing
// without a correctly-timed completion, but there's no reason to let a
// script hammer this endpoint either.
if (request_rate_exceeded($pdo, $uid)) {
    json_out(['error' => 'Too many requests.'], 429);
}

// Issued the moment a real jump begins (JUMP released, crouch about to
// start) and redeemed by jump.php once the landing animation actually
// fires — see JUMP_ATTEMPT_MIN/MAX_SECONDS. Overwriting any previous nonce
// here means only the most recent jump in flight is ever redeemable.
$nonce = bin2hex(random_bytes(16));
$pdo->prepare('UPDATE users SET pending_jump_nonce = ?, pending_jump_started_at = ? WHERE id = ?')
    ->execute([$nonce, microtime(true), $uid]);

json_out(['ok' => true, 'nonce' => $nonce]);
