<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();

$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $after = (int) ($_GET['after'] ?? 0);
    if ($after > 0) {
        $stmt = $pdo->prepare(
            'SELECT id, username, message, kind, created_at FROM chat_messages
             WHERE id > ? ORDER BY id ASC LIMIT 100'
        );
        $stmt->execute([$after]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->query(
            'SELECT id, username, message, kind, created_at FROM chat_messages
             ORDER BY id DESC LIMIT 50'
        );
        $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    json_out(['messages' => array_map(fn(array $r) => [
        'id' => (int) $r['id'],
        'username' => $r['username'],
        'message' => $r['message'],
        'kind' => $r['kind'],
        'createdAt' => (int) $r['created_at'],
    ], $rows)]);
}

require_method('POST');

$user = require_session($pdo);
$uid = (int) $user['id'];

$in = json_in();
$message = trim((string) ($in['message'] ?? ''));

if ($message === '' || mb_strlen($message) > 200) {
    json_out(['error' => 'Message must be 1-200 characters.'], 400);
}

$now = time();
if ($now - (int) $user['last_chat_at'] < 2) {
    json_out(['error' => 'Slow down a bit.'], 429);
}

$pdo->prepare('UPDATE users SET last_chat_at = ? WHERE id = ?')->execute([$now, $uid]);
$pdo->prepare('INSERT INTO chat_messages (username, message, kind, created_at) VALUES (?, ?, ?, ?)')
    ->execute([$user['username'], $message, 'user', $now]);
maybe_prune_chat($pdo);

json_out(['ok' => true]);
