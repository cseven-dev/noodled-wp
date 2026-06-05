const CACHE_NAME = 'noodled-v7';
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

// ── Reminders / notifications ──
// Tapping a reminder focuses an open noodled tab (and tells it which note to
// open) or opens a fresh one deep-linked to the note.
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const noteId = event.notification.data && event.notification.data.noteId;
  event.waitUntil((async () => {
    const all = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of all) {
      if ('focus' in c) {
        await c.focus();
        if (noteId) c.postMessage({ type: 'noodled-open-note', noteId });
        return;
      }
    }
    if (clients.openWindow) {
      await clients.openWindow(noteId ? ('/?noodled_note=' + noteId) : '/');
    }
  })());
});

// ── Offline queue flush (Background Sync, #4) ─────────────────────────────────
// Mirrors the app's IndexedDB ('noodled' DB, 'meta' store). When the device
// reconnects, replay the signed-in user's queued note saves so edits made with
// the app closed still reach the server. Chromium only; iOS has no Background
// Sync and falls back to the app's in-page retry on next open.
function swIdbOpen() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open('noodled', 1);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains('bodies')) db.createObjectStore('bodies', { keyPath: 'key' });
      if (!db.objectStoreNames.contains('meta'))   db.createObjectStore('meta',   { keyPath: 'key' });
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}
function swIdbGet(db, store, key) {
  return new Promise((resolve, reject) => {
    const r = db.transaction(store, 'readonly').objectStore(store).get(key);
    r.onsuccess = () => resolve(r.result);
    r.onerror = () => reject(r.error);
  });
}
function swIdbPut(db, store, value) {
  return new Promise((resolve, reject) => {
    const tx = db.transaction(store, 'readwrite');
    tx.objectStore(store).put(value);
    tx.oncomplete = () => resolve(true);
    tx.onerror = () => reject(tx.error);
  });
}
async function flushQueue() {
  const db = await swIdbOpen();
  const active = await swIdbGet(db, 'meta', 'activeUser');
  if (!active || !active.email || !active.apiBase) return;
  const qKey = 'queue::' + active.email;
  const rec = await swIdbGet(db, 'meta', qKey);
  const items = (rec && Array.isArray(rec.items)) ? rec.items : [];
  if (!items.length) return;

  const remaining = [];
  let flushed = 0, networkFail = false;
  for (const item of items) {
    const payload = { title: item.title, body: item.body };
    if (item.base) payload.base_modified = item.base; // conflict-safe, never blind force
    try {
      const res = await fetch(active.apiBase + '/notes/' + item.noteId, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': active.nonce || '' },
        credentials: 'include',
        body: JSON.stringify(payload),
      });
      if (!res.ok) { remaining.push(item); continue; } // auth/server issue → resolve in-app
      const data = await res.json().catch(() => ({}));
      if (data && data.conflict) { remaining.push(item); continue; } // surfaces in the app's resolver
      flushed++;
    } catch (e) {
      remaining.push(item); networkFail = true; // still offline → keep for the next sync
    }
  }
  await swIdbPut(db, 'meta', { key: qKey, items: remaining });
  const cs = await self.clients.matchAll({ includeUncontrolled: true });
  for (const c of cs) c.postMessage({ type: 'noodled-synced', count: flushed });
  // Reject so the browser reschedules the sync if we're still offline; a conflict
  // or auth failure is terminal here (the app resolves it on next open).
  if (networkFail) throw new Error('offline — retry later');
}
self.addEventListener('sync', event => {
  if (event.tag === 'noodled-flush') event.waitUntil(flushQueue());
});

// Server-initiated web push (future: needs VAPID keys + a wp-cron sender). The
// client-side scheduler already fires due reminders while the app is open; this
// handler lets a real push service deliver them when it is closed.
self.addEventListener('push', event => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) {}
  event.waitUntil(
    self.registration.showNotification(data.title || 'noodled', {
      body: data.body || '',
      tag: data.tag || 'noodled-reminder',
      data: { noteId: data.noteId || 0 },
    })
  );
});
