<?php
/**
 * One-time/offline data builder for the bridge API.
 *
 * Pulls a summary, an image, and image licensing info from Wikipedia for a
 * curated list of bridges and writes the result to data/bridges.json. This
 * is NOT run on every request — the live API only ever reads that JSON
 * file, so a Wikipedia outage or rate limit never touches visitors.
 *
 * Usage: php fetch-data.php
 */

declare(strict_types=1);

const USER_AGENT = 'JustJumpBridgeAPI/1.0 (https://justjump.lol/bridge/; contact via github.com issues) PHP-cURL';
const API = 'https://en.wikipedia.org/w/api.php';
const OUT_FILE = __DIR__ . '/data/bridges.json';

/** @return array{slug:string,title:string,location:string,country:string} */
function bridgeList(): array {
    // slug, Wikipedia title, human location, country
    $rows = [
        ['golden-gate-bridge', 'Golden Gate Bridge', 'San Francisco, California', 'United States'],
        ['brooklyn-bridge', 'Brooklyn Bridge', 'New York City, New York', 'United States'],
        ['tower-bridge', 'Tower Bridge', 'London', 'United Kingdom'],
        ['sydney-harbour-bridge', 'Sydney Harbour Bridge', 'Sydney', 'Australia'],
        ['akashi-kaikyo-bridge', 'Akashi Kaikyō Bridge', 'Kobe–Awaji', 'Japan'],
        ['millau-viaduct', 'Millau Viaduct', 'Millau', 'France'],
        ['ponte-vecchio', 'Ponte Vecchio', 'Florence', 'Italy'],
        ['rialto-bridge', 'Rialto Bridge', 'Venice', 'Italy'],
        ['charles-bridge', 'Charles Bridge', 'Prague', 'Czech Republic'],
        ['confederation-bridge', 'Confederation Bridge', 'Northumberland Strait', 'Canada'],
        ['oresund-bridge', 'Øresund Bridge', 'Øresund Strait', 'Denmark/Sweden'],
        ['hangzhou-bay-bridge', 'Hangzhou Bay Bridge', 'Hangzhou Bay', 'China'],
        ['danyang-kunshan-grand-bridge', 'Danyang–Kunshan Grand Bridge', 'Jiangsu', 'China'],
        ['vasco-da-gama-bridge', 'Vasco da Gama Bridge', 'Lisbon', 'Portugal'],
        ['pont-du-gard', 'Pont du Gard', 'near Vers-Pont-du-Gard', 'France'],
        ['stari-most', 'Stari Most', 'Mostar', 'Bosnia and Herzegovina'],
        ['chapel-bridge', 'Chapel Bridge', 'Lucerne', 'Switzerland'],
        ['si-o-se-pol', 'Si-o-se-pol', 'Isfahan', 'Iran'],
        ['khaju-bridge', 'Khaju Bridge', 'Isfahan', 'Iran'],
        ['forth-bridge', 'Forth Bridge', 'Firth of Forth', 'United Kingdom'],
        ['humber-bridge', 'Humber Bridge', 'Kingston upon Hull', 'United Kingdom'],
        ['verrazzano-narrows-bridge', 'Verrazzano-Narrows Bridge', 'New York City, New York', 'United States'],
        ['george-washington-bridge', 'George Washington Bridge', 'New York City, New York', 'United States'],
        ['mackinac-bridge', 'Mackinac Bridge', 'Straits of Mackinac, Michigan', 'United States'],
        ['sunshine-skyway-bridge', 'Sunshine Skyway Bridge', 'Tampa Bay, Florida', 'United States'],
        ['bixby-creek-bridge', 'Bixby Creek Bridge', 'Big Sur, California', 'United States'],
        ['royal-gorge-bridge', 'Royal Gorge Bridge', 'Cañon City, Colorado', 'United States'],
        ['hell-gate-bridge', 'Hell Gate Bridge', 'New York City, New York', 'United States'],
        ['tsing-ma-bridge', 'Tsing Ma Bridge', 'Hong Kong', 'China'],
        ['rainbow-bridge-tokyo', 'Rainbow Bridge (Tokyo)', 'Tokyo', 'Japan'],
        ['seto-ohashi-bridge', 'Great Seto Bridge', 'Seto Inland Sea', 'Japan'],
        ['storseisundet-bridge', 'Storseisundet Bridge', 'Atlantic Ocean Road', 'Norway'],
        ['puente-nuevo', 'Puente Nuevo', 'Ronda', 'Spain'],
        ['puente-de-la-mujer', 'Puente de la Mujer', 'Buenos Aires', 'Argentina'],
        ['juscelino-kubitschek-bridge', 'Juscelino Kubitschek Bridge', 'Brasília', 'Brazil'],
        ['helix-bridge', 'Helix Bridge', 'Marina Bay', 'Singapore'],
        ['banpo-bridge', 'Banpo Bridge', 'Seoul', 'South Korea'],
        ['benjamin-franklin-bridge', 'Benjamin Franklin Bridge', 'Philadelphia, Pennsylvania', 'United States'],
        ['san-francisco-oakland-bay-bridge', 'San Francisco–Oakland Bay Bridge', 'San Francisco Bay, California', 'United States'],
        ['bosphorus-bridge', '15 July Martyrs Bridge', 'Istanbul', 'Turkey'],
        ['erasmusbrug', 'Erasmusbrug', 'Rotterdam', 'Netherlands'],
        ['pont-alexandre-iii', 'Pont Alexandre III', 'Paris', 'France'],
        ['iron-bridge', 'The Iron Bridge', 'Ironbridge, Shropshire', 'United Kingdom'],
        ['clifton-suspension-bridge', 'Clifton Suspension Bridge', 'Bristol', 'United Kingdom'],
        ['menai-suspension-bridge', 'Menai Suspension Bridge', 'Menai Strait, Wales', 'United Kingdom'],
        ['zhaozhou-bridge', 'Zhaozhou Bridge', 'Zhao County, Hebei', 'China'],
        ['langkawi-sky-bridge', 'Langkawi Sky Bridge', 'Pulau Langkawi', 'Malaysia'],
        ['tower-bridge-of-capital-city', 'Bloukrans Bridge', 'Western Cape', 'South Africa'],
    ];
    $out = [];
    foreach ($rows as [$slug, $title, $location, $country]) {
        $out[] = ['slug' => $slug, 'title' => $title, 'location' => $location, 'country' => $country];
    }
    return $out;
}

function apiGet(array $params): array {
    $params += ['format' => 'json', 'formatversion' => '2'];
    $url = API . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        fwrite(STDERR, "request failed: " . curl_error($ch) . "\n");
        curl_close($ch);
        return [];
    }
    curl_close($ch);
    $data = json_decode($body, true);
    return is_array($data) ? $data : [];
}

/** @param string[] $items */
function chunk50(array $items): array {
    return array_chunk($items, 50);
}

/**
 * Runs a query to completion, following MediaWiki's continuation cursor.
 * A single response to a multi-title query often only fills props (extract,
 * coordinates, imageinfo...) for the first few pages and leaves a
 * "continue" block to fetch the rest — without this, later pages in a
 * batch silently come back missing those fields.
 *
 * Returns pages merged by title, with fields from later continuations
 * merged into whatever the first response already had for that page.
 */
function apiGetAllPages(array $params): array {
    $pages = [];
    $extra = [];
    do {
        $data = apiGet($params + $extra);
        foreach ($data['query']['pages'] ?? [] as $page) {
            $title = $page['title'];
            $pages[$title] = ($pages[$title] ?? []) + $page;
        }
        foreach ($data['query']['redirects'] ?? [] as $r) {
            $pages[$r['from']] = $pages[$r['to']] ?? null;
        }
        foreach ($data['query']['normalized'] ?? [] as $n) {
            $pages[$n['from']] = $pages[$n['to']] ?? null;
        }
        $extra = $data['continue'] ?? null;
        if ($extra) {
            usleep(300_000);
        }
    } while ($extra);
    return array_filter($pages);
}

function extractFacts(string $text, int $max = 5): array {
    // exintro should already keep us inside the lead section, but be
    // defensive in case a short lead spills into the next heading.
    $text = trim(preg_split('/\n==/', $text)[0]);
    if ($text === '') return [];
    // Split on sentence-ending punctuation followed by a space and a capital letter,
    // good enough for plain-text Wikipedia extracts.
    $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9])/', $text) ?: [$text];
    $parts = array_map('trim', $parts);
    $parts = array_filter($parts, fn($p) => $p !== '');
    return array_slice(array_values($parts), 0, $max);
}

echo "Fetching page data for " . count(bridgeList()) . " bridges...\n";

$bridges = bridgeList();
$byTitle = [];
// The extracts prop only returns full text for exlimit pages per request
// (default 1), and the non-bot ceiling is 20 — so chunk smaller here than
// the general 50-title batch size the other queries can use.
foreach (array_chunk(array_column($bridges, 'title'), 20) as $chunk) {
    $pages = apiGetAllPages([
        'action' => 'query',
        'prop' => 'extracts|pageimages|coordinates|info',
        'exlimit' => '20',
        'exintro' => '1',
        'exsentences' => '6',
        'explaintext' => '1',
        'piprop' => 'thumbnail|name',
        'pithumbsize' => '1600',
        'coprop' => 'type',
        'colimit' => '1',
        'inprop' => 'url',
        'redirects' => '1',
        'titles' => implode('|', $chunk),
    ]);
    $byTitle += $pages;
    usleep(300_000); // be polite to the API between batches
}

// Collect image file names so we can fetch licensing info in a second batch pass.
$fileNames = [];
foreach ($byTitle as $page) {
    if (!empty($page['pageimage'])) {
        $fileNames[] = 'File:' . $page['pageimage'];
    }
}
$fileNames = array_values(array_unique($fileNames));

$imageInfo = [];
foreach (chunk50($fileNames) as $chunk) {
    $pages = apiGetAllPages([
        'action' => 'query',
        'prop' => 'imageinfo',
        'iiprop' => 'extmetadata|url',
        'iiextmetadatafilter' => 'Artist|LicenseShortName|AttributionRequired',
        'titles' => implode('|', $chunk),
    ]);
    foreach ($pages as $title => $page) {
        $info = $page['imageinfo'][0] ?? null;
        if ($info) {
            $imageInfo[$title] = $info;
        }
    }
    usleep(300_000);
}

$result = [];
$skipped = [];
foreach ($bridges as $seed) {
    $page = $byTitle[$seed['title']] ?? null;
    if (!$page || empty($page['extract']) || empty($page['thumbnail']['source'])) {
        $skipped[] = $seed['title'];
        continue;
    }
    $imgFile = 'File:' . ($page['pageimage'] ?? '');
    $info = $imageInfo[$imgFile] ?? null;
    $artist = $info['extmetadata']['Artist']['value'] ?? null;
    $license = $info['extmetadata']['LicenseShortName']['value'] ?? null;
    $artist = $artist ? trim(strip_tags($artist)) : null;

    $result[] = [
        'id' => $seed['slug'],
        'name' => $seed['title'],
        'location' => $seed['location'],
        'country' => $seed['country'],
        'coordinates' => isset($page['coordinates'][0])
            ? ['lat' => $page['coordinates'][0]['lat'], 'lon' => $page['coordinates'][0]['lon']]
            : null,
        'facts' => extractFacts($page['extract']),
        'image' => [
            'url' => $page['thumbnail']['source'],
            'width' => $page['thumbnail']['width'] ?? null,
            'height' => $page['thumbnail']['height'] ?? null,
            'attribution' => $artist ? ($artist . ($license ? " — {$license}" : '')) : $license,
            'sourceUrl' => $info['descriptionurl'] ?? null,
        ],
        'wikipediaUrl' => $page['fullurl'] ?? ('https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $seed['title']))),
    ];
}

if ($skipped) {
    fwrite(STDERR, "Skipped (no page/extract/image found): " . implode(', ', $skipped) . "\n");
}

file_put_contents(OUT_FILE, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

echo "Wrote " . count($result) . " bridges to " . OUT_FILE . "\n";
