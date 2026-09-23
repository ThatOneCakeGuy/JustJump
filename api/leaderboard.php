<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
start_session();
require_method('GET');

$pdo = db();

$stmt = $pdo->query(
    'SELECT username, jump_count FROM users
     WHERE jump_count > 0
     ORDER BY jump_count DESC, username COLLATE NOCASE ASC
     LIMIT 10'
);
$leaders = array_map(
    fn(array $r) => ['username' => $r['username'], 'jumpCount' => (int) $r['jump_count']],
    $stmt->fetchAll(PDO::FETCH_ASSOC)
);

$you = null;
if (!empty($_SESSION['uid'])) {
    $me = $pdo->prepare('SELECT username, jump_count FROM users WHERE id = ?');
    $me->execute([$_SESSION['uid']]);
    $meRow = $me->fetch(PDO::FETCH_ASSOC);
    if ($meRow) {
        $rankStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM users
             WHERE jump_count > ? OR (jump_count = ? AND username < ? COLLATE NOCASE)'
        );
        $rankStmt->execute([$meRow['jump_count'], $meRow['jump_count'], $meRow['username']]);
        $you = [
            'username' => $meRow['username'],
            'jumpCount' => (int) $meRow['jump_count'],
            'rank' => (int) $rankStmt->fetchColumn() + 1,
        ];
    }
}

json_out(['leaders' => $leaders, 'you' => $you]);
