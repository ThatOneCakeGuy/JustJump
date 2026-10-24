<?php
/**
 * GET /bridge/api/visitor.php
 *
 * The visitor's approximate (city-level, IP-based) location from
 * Cloudflare's visitor location headers, plus the browser key for the
 * Google Maps Embed API. Nothing here is logged or stored.
 *
 * `location` is null when Cloudflare didn't send coordinates (e.g. a
 * request that bypassed Cloudflare); `mapsKey` is null when no key file
 * is present — the page hides the map in either case.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

bridge_require_get();

bridge_json([
    'location' => visitor_location(),
    'mapsKey' => maps_embed_key(),
], cacheable: false);

function visitor_location(): ?array {
    $lat = $_SERVER['HTTP_CF_IPLATITUDE'] ?? '';
    $lon = $_SERVER['HTTP_CF_IPLONGITUDE'] ?? '';
    if (!is_numeric($lat) || !is_numeric($lon)) {
        return null;
    }
    return [
        'lat' => (float) $lat,
        'lon' => (float) $lon,
        'city' => $_SERVER['HTTP_CF_IPCITY'] ?? null,
        'region' => $_SERVER['HTTP_CF_REGION'] ?? null,
        'country' => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null,
    ];
}

/**
 * Kept outside the repo and webroot so deploys (rsync --delete) never
 * touch it. It's a browser key — visible in the iframe URL anyway — so it
 * must be restricted to justjump.lol referrers in Google Cloud.
 */
function maps_embed_key(): ?string {
    $raw = @file_get_contents('/var/www/justjump.lol/data/google-maps-key');
    $key = $raw !== false ? trim($raw) : '';
    return $key !== '' ? $key : null;
}
