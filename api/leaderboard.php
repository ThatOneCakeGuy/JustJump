<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_method('GET');

$stmt = db()->query(
    'SELECT username, jump_count FROM users
     WHERE jump_count > 0
     ORDER BY jump_count DESC, username COLLATE NOCASE ASC
     LIMIT 20'
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

json_out(['leaders' => array_map(
    fn(array $r) => ['username' => $r['username'], 'jumpCount' => (int) $r['jump_count']],
    $rows
)]);
