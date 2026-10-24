<?php
/**
 * Shared plumbing for the bridge-api endpoints: loading the dataset and
 * sending consistent, CORS-friendly JSON responses.
 */

declare(strict_types=1);

function bridge_dataset(): array {
    static $bridges = null;
    if ($bridges !== null) {
        return $bridges;
    }
    $raw = file_get_contents(__DIR__ . '/data/bridges.json');
    $bridges = $raw !== false ? (json_decode($raw, true) ?: []) : [];
    return $bridges;
}

function bridge_by_id(string $id): ?array {
    foreach (bridge_dataset() as $bridge) {
        if ($bridge['id'] === $id) {
            return $bridge;
        }
    }
    return null;
}

/** @return array[] $count distinct random bridges */
function bridge_random(int $count): array {
    $all = bridge_dataset();
    $count = max(1, min($count, count($all)));
    $keys = array_rand($all, $count);
    if (!is_array($keys)) {
        $keys = [$keys];
    }
    return array_map(fn($k) => $all[$k], $keys);
}

/** Only GET (and CORS preflight) is meaningful for a read-only public API. */
function bridge_require_get(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') {
        header('Access-Control-Max-Age: 86400');
        http_response_code(204);
        exit;
    }
    if ($method !== 'GET') {
        bridge_json(['error' => 'This endpoint only accepts GET.'], 405, cacheable: false);
    }
}

function bridge_json(array $data, int $status = 200, bool $cacheable = true): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header($cacheable
        // Deterministic lookups (by id, the full index) are safe for
        // browsers and Cloudflare to cache.
        ? 'Cache-Control: public, max-age=3600'
        // A random pick must hit the server every time — otherwise the
        // browser (or Cloudflare) just replays the first result it saw
        // for this exact URL until the cache expires.
        : 'Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Clamps a query-string int param into [$min, $max], falling back to $default. */
function bridge_int_param(string $name, int $default, int $min, int $max): int {
    if (!isset($_GET[$name]) || !ctype_digit($_GET[$name])) {
        return $default;
    }
    return max($min, min($max, (int) $_GET[$name]));
}
