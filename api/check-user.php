<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
require_method('POST');

$in = json_in();
$username = trim((string) ($in['username'] ?? ''));

if (!valid_username($username)) {
    json_out(['error' => 'Names are 2-20 characters: letters, numbers, _ or -.'], 400);
}

$stmt = db()->prepare('SELECT id FROM users WHERE username = ? COLLATE NOCASE');
$stmt->execute([$username]);

json_out(['exists' => (bool) $stmt->fetch()]);
