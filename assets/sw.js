// Each release registers the worker at a new ?noodled_sw=<version> URL (see
// templates/app.php), so the cache name changes with the version and activate
// drops the previous release's cache.
const SW_VERSION = new URL(self.location.href).searchParams.get('noodled_sw') || '';
const CACHE_NAME = 'noodled-v9-' + SW_VERSION;
// The only files the fetch handler answers: this plugin's own static assets
// (both source + minified builds, so it matches whichever the page loads),
// compared by exact path on this origin.
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

// Activate: purge every older noodled cache (including the old per-user API
// cache). Caches of other scripts on this origin are left alone.
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k.indexOf('noodled-') === 0 && k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// Fetch handler. The worker's scope is the app path (often the whole site, so
// wp-admin too): it answers ONLY the share target POST and same-origin GETs of
// the files in STATIC_ASSETS. Everything else (page navigations, the REST API,
// wp-admin, wp-login, other plugins' files, other origins) is not touched and
// goes straight to the network.
self.addEventListener('fetch', event => {
  const req = event.request;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Share target (manifest share_target, POST multipart to <app>?noodled_share=1).
  if (req.method === 'POST') {
    if (url.pathname === new URL(self.registration.scope).pathname && url.searchParams.get('noodled_share') === '1') {
      event.respondWith(handleShare(req));
    }
    return;
  }

  if (req.method !== 'GET' || req.mode === 'navigate' || !STATIC_ASSETS.includes(url.pathname)) return;

  // Static assets: stale-while-revalidate keyed by the FULL url (the ?v= query is
  // version.filemtime, so every deploy is a fresh url = guaranteed cache miss =
  // fresh fetch). Same-version repeat loads are served instantly from cache and
  // revalidated in the background. This avoids the old bug where the cache key
  // ignored the query and served a stale bundle after a deploy.
  event.respondWith(
    caches.open(CACHE_NAME).then(cache =>
      cache.match(req).then(cached => {
        const network = fetch(req).then(response => {
          if (response && response.status === 200 && response.type === 'basic') cache.put(req, response.clone()).catch(() => {});
          return response;
        }).catch(() => cached);
        return cached || network;
      })
    )
  );
});

// A shared item (title, text, url and up to 10 files of at most 10MB each) is
// kept in IndexedDB for the app to pick up, then the browser is sent to the app
// with ?noodled_share=1&pending=1 (consumePendingShare in noodled.js).
async function handleShare(request) {
  try {
    const form = await request.formData();
    const str = k => { const v = form.get(k); return typeof v === 'string' ? v : ''; };
    const files = form.getAll('files')
      .filter(f => f && typeof f !== 'string' && f.size > 0 && f.size <= 10 * 1024 * 1024)
      .slice(0, 10);
    const db = await swIdbOpen();
    await swIdbPut(db, 'meta', { key: 'share::pending', title: str('title'), text: str('text'), url: str('url'), files, at: Date.now() });
  } catch (e) {}
  return Response.redirect(new URL('?noodled_share=1&pending=1', self.registration.scope).href, 303);
}

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
// Remove only the queue items this flush saved, re-reading the queue inside the
// same transaction: the app may have queued a newer edit while the requests were
// in flight, and writing back the snapshot taken before them would drop it.
function swIdbDropFromQueue(db, qKey, done) {
  return new Promise((resolve, reject) => {
    const tx = db.transaction('meta', 'readwrite');
    const os = tx.objectStore('meta');
    const r = os.get(qKey);
    r.onsuccess = () => {
      const cur = (r.result && Array.isArray(r.result.items)) ? r.result.items : [];
      const keep = cur.filter(q => !done.some(d => d.noteId === q.noteId && d.time === q.time && d.body === q.body));
      if (keep.length !== cur.length) os.put({ key: qKey, items: keep });
    };
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

  const done = [];
  let networkFail = false;
  const saved = []; // { item, note } so an open app tab can adopt the new mtime
  for (const item of items) {
    // Never a blind write: an item without the version it was made against is
    // left for the app's own retry, which can ask the user on a conflict.
    if (!item.base) continue;
    try {
      const res = await fetch(active.apiBase + '/notes/' + item.noteId, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': active.nonce || '' },
        credentials: 'include',
        body: JSON.stringify({ title: item.title, body: item.body, base_modified: item.base }),
      });
      if (!res.ok) continue; // auth/server issue: stays queued, resolved in-app
      const data = await res.json().catch(() => ({}));
      if (data && data.conflict) {
        // The server already holds exactly this edit (the app's own retry got
        // there first): it is saved. Any other conflict stays queued and
        // surfaces in the app's resolver.
        const s = data.server;
        if (s && s.title === item.title && s.body === item.body) { done.push(item); saved.push({ item, note: s }); }
        continue;
      }
      done.push(item);
      saved.push({ item, note: data });
    } catch (e) {
      networkFail = true; // still offline: stays queued for the next sync
    }
  }
  if (done.length) await swIdbDropFromQueue(db, qKey, done);
  const cs = await self.clients.matchAll({ includeUncontrolled: true });
  for (const c of cs) c.postMessage({ type: 'noodled-synced', count: done.length, saved });
  // Reject so the browser reschedules the sync if we're still offline; a conflict
  // or auth failure is terminal here (the app resolves it on next open).
  if (networkFail) throw new Error('offline, retry later');
}
self.addEventListener('sync', event => {
  if (event.tag === 'noodled-flush') event.waitUntil(flushQueue());
});
