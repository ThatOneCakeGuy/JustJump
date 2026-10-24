(() => {
  const API_URL = 'api/random.php';

  const photo = document.getElementById('photo');
  const credit = document.getElementById('credit');
  const name = document.getElementById('name');
  const location = document.getElementById('location');
  const facts = document.getElementById('facts');
  const wikiLink = document.getElementById('wikiLink');
  const errorMsg = document.getElementById('errorMsg');
  const button = document.getElementById('newJump');

  const fromYou = document.getElementById('fromYou');
  const youAre = document.getElementById('youAre');
  const exactButton = document.getElementById('exactLocation');
  const map = document.getElementById('map');

  // Driving directions only exist over land and get silly across a
  // continent; past this straight-line distance the map shows a flight path.
  const MAX_DRIVING_KM = 2500;

  let current = null;  // the bridge on screen
  let visitor = null;  // { lat, lon, label }
  let mapsKey = null;

  async function loadBridge() {
    button.disabled = true;
    errorMsg.hidden = true;

    try {
      const res = await fetch(`${API_URL}?count=1`, { cache: 'no-store' });
      if (!res.ok) throw new Error(`API returned ${res.status}`);

      const [bridge] = await res.json();
      if (!bridge) throw new Error('API returned no bridge');

      render(bridge);
    } catch (err) {
      errorMsg.textContent = "Couldn't reach the bridge-api. Try again in a moment.";
      errorMsg.hidden = false;
      console.error('bridge fetch failed:', err);
    } finally {
      button.disabled = false;
    }
  }

  function render(bridge) {
    photo.classList.remove('loaded');
    photo.alt = `${bridge.name}, ${bridge.location}`;
    photo.onload = () => photo.classList.add('loaded');
    photo.src = bridge.image.url;

    credit.textContent = bridge.image.attribution || '';
    credit.hidden = !bridge.image.attribution;

    name.textContent = bridge.name;
    location.textContent = [bridge.location, bridge.country].filter(Boolean).join(', ');

    facts.replaceChildren(
      ...bridge.facts.map((fact) => {
        const li = document.createElement('li');
        li.textContent = fact;
        return li;
      })
    );

    wikiLink.replaceChildren();
    if (bridge.wikipediaUrl) {
      const a = document.createElement('a');
      a.href = bridge.wikipediaUrl;
      a.target = '_blank';
      a.rel = 'noopener';
      a.textContent = 'Read more on Wikipedia →';
      wikiLink.appendChild(a);
    }

    current = bridge;
    renderMap();
  }

  // ---- "from you" map --------------------------------------------------

  async function loadVisitor() {
    try {
      const res = await fetch('api/visitor.php', { cache: 'no-store' });
      if (!res.ok) throw new Error(`API returned ${res.status}`);
      const data = await res.json();
      mapsKey = data.mapsKey;
      if (data.location) {
        const { lat, lon, city, region, country } = data.location;
        const place = [city, region, country].filter(Boolean).join(', ');
        visitor = { lat, lon, label: place ? `near ${place} (approximate, from your IP)` : 'at your approximate location' };
      }
    } catch (err) {
      console.error('visitor lookup failed:', err);
    }
    renderMap();
  }

  function useExactLocation() {
    exactButton.disabled = true;
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        visitor = { lat: pos.coords.latitude, lon: pos.coords.longitude, label: 'at your exact location' };
        exactButton.hidden = true;
        renderMap();
      },
      (err) => {
        exactButton.disabled = false;
        console.error('geolocation failed:', err);
      },
      { timeout: 10000, maximumAge: 600000 }
    );
  }

  function renderMap() {
    const coords = current?.coordinates;
    if (!mapsKey || !visitor || !coords) {
      fromYou.hidden = true;
      return;
    }

    youAre.textContent = `Route from you, ${visitor.label}`;

    const origin = `${visitor.lat},${visitor.lon}`;
    const destination = `${coords.lat},${coords.lon}`;
    const mode = distanceKm(visitor, coords) > MAX_DRIVING_KM ? 'flying' : 'driving';
    const params = new URLSearchParams({ key: mapsKey, origin, destination, mode });
    const src = `https://www.google.com/maps/embed/v1/directions?${params}`;
    if (map.src !== src) map.src = src;

    fromYou.hidden = false;
  }

  // Great-circle (haversine) distance — only used to pick the travel mode.
  function distanceKm(a, b) {
    const rad = (deg) => (deg * Math.PI) / 180;
    const dLat = rad(b.lat - a.lat);
    const dLon = rad(b.lon - a.lon);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLon / 2) ** 2;
    return 2 * 6371 * Math.asin(Math.sqrt(h));
  }

  button.addEventListener('click', loadBridge);
  exactButton.hidden = !navigator.geolocation;
  exactButton.addEventListener('click', useExactLocation);
  loadBridge();
  loadVisitor();
})();
