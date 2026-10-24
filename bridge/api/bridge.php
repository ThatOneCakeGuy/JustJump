<?php
/**
 * GET /bridge/api/bridge.php?id=golden-gate-bridge
 *
 * Returns a single bridge by its slug, or a 404 JSON error if it doesn't
 * exist.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

bridge_require_get();

$id = is_string($_GET['id'] ?? null) ? trim($_GET['id']) : '';
if ($id === '') {
    bridge_json(['error' => 'Missing required "id" parameter.'], 400);
}

$bridge = bridge_by_id($id);
if ($bridge === null) {
    bridge_json(['error' => "No bridge found for id \"{$id}\"."], 404);
}

bridge_json($bridge);
