# bridge-api

A small, read-only JSON API serving facts and photos for ~50 famous bridges.
Built for the Jump Spots page but plain enough to hit from anywhere — no
key, no auth, CORS open to any origin.

## Endpoints

### `GET /bridge/api/random.php`

Returns a random bridge. Always a JSON array, even for a single result, so
callers don't need two code paths.

Query params:

- `count` — how many to return, 1–10 (default 1).

```
GET /bridge/api/random.php?count=2
```

```json
[
  {
    "id": "golden-gate-bridge",
    "name": "Golden Gate Bridge",
    "location": "San Francisco, California",
    "country": "United States",
    "coordinates": { "lat": 37.8199, "lon": -122.4783 },
    "facts": ["...", "...", "..."],
    "image": {
      "url": "https://thumb.wikimedia.org/...",
      "width": 1920,
      "height": 1200,
      "attribution": "Photographer name — CC BY-SA 4.0",
      "sourceUrl": "https://commons.wikimedia.org/wiki/File:..."
    },
    "wikipediaUrl": "https://en.wikipedia.org/wiki/Golden_Gate_Bridge"
  }
]
```

### `GET /bridge/api/bridge.php?id=<slug>`

A single bridge by id. `404` with `{"error": "..."}` if the id doesn't
exist.

### `GET /bridge/api/bridges.php`

The full catalog as a lightweight index — `id`, `name`, `location`,
`country` only. Fetch a specific one with `bridge.php` once you have an id.

### `GET /bridge/api/visitor.php`

Used by the Jump Spots page itself, not part of the public catalog API.
Returns the caller's approximate location (from Cloudflare's visitor
location headers, city-level and IP-based) and the Google Maps Embed key:

```json
{
  "location": { "lat": 41.88, "lon": -87.63, "city": "Chicago", "region": "Illinois", "country": "US" },
  "mapsKey": "AIza..."
}
```

Either field is `null` when unavailable — no Cloudflare headers, or no key
in `/var/www/justjump.lol/data/google-maps-key` (kept out of the repo and
webroot so deploys can't wipe it). Never cached; nothing is stored.

## Notes for anyone else using this

- Everything is served from a local JSON file (`data/bridges.json`) built
  ahead of time — the API never calls out to Wikipedia on a live request,
  so it stays fast and doesn't depend on Wikipedia being reachable.
- Images are Wikimedia Commons photos, hotlinked at a 1600px-wide
  thumbnail. Most licenses require attribution — the `image.attribution`
  string and `image.sourceUrl` are there for that; show them next to the
  photo rather than dropping them.
- Responses are cached for an hour (`Cache-Control: public, max-age=3600`)
  since the dataset only changes when someone reruns the fetch script.

## Regenerating the dataset

```
php fetch-data.php
```

Pulls a fresh intro/photo/coordinates for each bridge in the curated list
at the top of that file and overwrites `data/bridges.json`. Takes a couple
of minutes — it deliberately paces its requests to stay well under
Wikipedia's API rate limits. Add a bridge by adding a row to `bridgeList()`
(slug, Wikipedia title, location, country) and rerunning.
