const CACHE_NAME = 'noodled-v4';
const STATIC_ASSETS = [
  '/wp-content/plugins/noodled/assets/css/noodled.css',
  '/wp-content/plugins/noodled/assets/js/noodled.js',
  '/wp-content/plugins/noodled/assets/manifest.json',
  '/wp-content/plugins/noodled/assets/icon-192.png',
];

// Install: cache static assets
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS))
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

  // Static assets: stale-while-revalidate
  if (STATIC_ASSETS.some(a => url.pathname.endsWith(a.split('/').pop()))) {
    event.respondWith(
      caches.match(event.request).then(cached => {
        const fetched = fetch(event.request).then(response => {
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, response.clone()));
          return response;
        });
        return cached || fetched;
      })
    );
  }
});
