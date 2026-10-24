<?php
/**
 * GET /bridge/api/bridges.php
 *
 * Returns the full catalog as a lightweight index (id, name, location,
 * country) — enough to build a picker or a list view without pulling
 * every image URL and fact down at once.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

bridge_require_get();

$index = array_map(
    fn($b) => ['id' => $b['id'], 'name' => $b['name'], 'location' => $b['location'], 'country' => $b['country']],
    bridge_dataset()
);

bridge_json($index);
