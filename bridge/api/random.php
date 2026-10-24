<?php
/**
 * GET /bridge/api/random.php[?count=1-10]
 *
 * Returns a JSON array of random bridges (always an array, even for
 * count=1, so callers don't need two code paths).
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

bridge_require_get();

$count = bridge_int_param('count', 1, 1, 10);

bridge_json(bridge_random($count), cacheable: false);
