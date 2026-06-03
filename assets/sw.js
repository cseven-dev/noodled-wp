const CACHE_NAME = 'noodled-v6';
// Filenames the fetch handler stale-while-revalidates (both source + minified
// builds, so it matches whichever the page actually loads).
const STATIC_ASSETS = [
  '/wp-content/plugins/noodled/assets/css/noodled.css',
  '/wp-content/plugins/noodled/assets/css/noodled.min.css',
  '/wp-content/plugins/noodled/assets/js/noodled.js',
  '/wp-content/plugins/noodled/assets/js/noodled.min.js',
  '/wp-content/plugins/noodled/assets/manifest.json',
  '/wp-content/plugins/noodled/assets/icon-192.png',
];
// Only precache assets guaranteed to exist (the JS/CSS build may be min or source).
const PRECACHE = [
  '/wp-content/plugins/noodled/assets/manifest.json',
  '/wp-content/plugins/noodled/assets/icon-192.png',
];

// Install: precache the stable assets; JS/CSS are cached lazily on first fetch.
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(PRECACHE).catch(() => {}))
  );
  self.skipWaiting();
});

// Activate: purge ALL old caches (including the old per-user API cache)
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// Fetch handler
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // NEVER cache HTML page navigations — they depend on auth state
  if (event.request.mode === 'navigate') return;

  // NEVER cache the API: per-user data must always come fresh from the server,
  // and caching it risks serving one user's notes to another. Network-only.
  if (url.pathname.includes('/wp-json/')) return;

  // Static assets: stale-while-revalidate keyed by the FULL url (the ?v= query is
  // version.filemtime, so every deploy is a fresh url = guaranteed cache miss =
  // fresh fetch). Same-version repeat loads are served instantly from cache and
  // revalidated in the background. This avoids the old bug where the cache key
  // ignored the query and served a stale bundle after a deploy.
  if (STATIC_ASSETS.some(a => url.pathname.endsWith(a.split('/').pop()))) {
    event.respondWith(
      caches.open(CACHE_NAME).then(cache =>
        cache.match(event.request).then(cached => {
          const network = fetch(event.request).then(response => {
            if (response && response.ok) cache.put(event.request, response.clone());
            return response;
          }).catch(() => cached);
          return cached || network;
        })
      )
    );
  }
});
