/*
 * Service worker test: the REAL templates/app.php (rendered by
 * tests/visual-render.php), the REAL assets/sw.js and the REAL noodled.js in
 * headless Chromium, against a small Node http server on a random 127.0.0.1
 * port (a secure context, so service workers run) that mimics how the plugin
 * serves things:
 *   /                        the app page (any query except noodled_sw)
 *   /?noodled_sw=<version>   assets/sw.js with the headers Noodled_App::sw_headers() sends
 *   POST /?noodled_share=1   the no-worker share fallback (see FALLBACK below)
 *   /wp-content/plugins/noodled/assets/...   plugin assets (.min requests get the source)
 *   /wp-json/noodled/v1/...  a fake REST API (notes with the base_modified conflict check)
 *   /wp-admin/, /wp-login.php, /wp-content/plugins/other/...   pages and files the worker must not answer
 * plus a second server on localhost:<other port> for a cross-origin check.
 *
 * Run from the repo root:   node tests/sw.test.js
 *
 * Dependency: Playwright from C:\Users\simon\.claude\skills\gauntlet\node_modules
 * (override with NOODLED_PLAYWRIGHT_DIR); PHP CLI for the template (NOODLED_PHP).
 * Prints "N passed, 0 failed" and exits 1 on any failure, 2 if it cannot start.
 *
 * FALLBACK (no worker yet, e.g. the first visit): the PHP side
 * (Noodled_App::early_route + share_redirect_url, covered by
 * tests/app-share.test.php) answers a POST to ?noodled_share with a 303 to the
 * app URL carrying noodled_share=1 plus title, text and url, each sanitized and
 * cut to 2000 characters; files are dropped; the target is always the app URL
 * on this site. The mimic below implements exactly that contract, and the
 * "share without a worker" case checks the real client turns it into a note.
 */

const http = require('http');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { createRequire } = require('module');

const PW_DIR = process.env.NOODLED_PLAYWRIGHT_DIR || 'C:\\Users\\simon\\.claude\\skills\\gauntlet\\node_modules';
const PHP = process.env.NOODLED_PHP || 'C:\\Users\\simon\\AppData\\Roaming\\Local\\lightning-services\\php-8.2.29+0\\bin\\win64\\php.exe';
const ROOT = path.resolve(__dirname, '..');
const PLUGIN = '/wp-content/plugins/noodled/';

function loadPlaywright() {
  const req = createRequire(path.join(PW_DIR, 'noop.js'));
  for (const name of ['playwright', 'playwright-core']) {
    try { return req(name); } catch (e) { /* try next */ }
  }
  console.error('Could not load Playwright from ' + PW_DIR + ' (set NOODLED_PLAYWRIGHT_DIR).');
  process.exit(2);
}
async function launch(chromium) {
  const tries = [{}, { channel: 'chrome' }, { channel: 'msedge' }];
  let lastErr;
  for (const opt of tries) {
    try { return await chromium.launch({ headless: true, ...opt }); } catch (e) { lastErr = e; }
  }
  throw lastErr;
}

// ── Server state ──

const S = {
  version: '1.0.0',
  release: 'r1',       // marker appended to noodled.css, changes with a release
  apiOffline: false,   // destroy every /wp-json/ request (device offline)
  oldWorker: true,     // serve the pre-P14 worker at the old assets/sw.js URL
  holdPut: null,       // note id whose PUT is held until releasePut() runs
  releasePut: null,
  log: [],             // { method, path, search, fromPage }
  counter: 0,
};
const html = {};
let ORIGIN = '';
let OTHER = '';

// The old (pre-P14) registration in a nutshell: registered at
// /wp-content/plugins/noodled/assets/sw.js (scope .../assets/) with the
// 'noodled-v8' cache. A stand-in, so the test does not depend on git history.
const OLD_WORKER = `
self.addEventListener('install', e => { e.waitUntil(caches.open('noodled-v8').then(c => c.put('/old-entry', new Response('old')))); self.skipWaiting(); });
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
`;

const T0 = Date.UTC(2026, 10, 1, 9, 0, 0);
let clock = 0;
function stamp() { clock += 61; return new Date(T0 + clock * 1000).toISOString().slice(0, 19).replace('T', ' '); }
const notes = new Map();
function addNote(id, title, body) { notes.set(id, { id, notebook: 'General', title, body, mtime: stamp() }); }
for (let i = 1; i <= 6; i++) addNote(i, 'Note ' + i, 'Body ' + i);
let nextId = 100;
const created = [];      // POST /notes payloads
const attachments = [];  // POST /attachments payloads
const puts = [];         // { id, base, fromPage, result: 'saved'|'conflict' }

function noteJson(n, withBody = true) {
  const o = { id: n.id, slug: 'n' + n.id, notebook: n.notebook, title: n.title, source: '', pinned: false,
    created: n.mtime.slice(0, 16), modified: n.mtime.slice(0, 16), mtime: n.mtime, att: 0, thumb: 0,
    preview: n.body.slice(0, 80), tasks: { done: 0, total: 0 }, tags: [] };
  if (withBody) { o.body = n.body; o.attachments = []; }
  return o;
}

function readBody(req) {
  return new Promise(resolve => { const c = []; req.on('data', d => c.push(d)); req.on('end', () => resolve(Buffer.concat(c))); });
}
// Minimal multipart/form-data parser: [{ name, filename, data: Buffer }].
function parseMultipart(buf, type) {
  const m = /boundary=(?:"([^"]+)"|([^;]+))/.exec(type || '');
  if (!m) return [];
  const b = '--' + (m[1] || m[2]);
  const s = buf.toString('latin1');
  return s.split(b).slice(1, -1).map(part => {
    const i = part.indexOf('\r\n\r\n');
    const head = part.slice(0, i);
    const name = (/name="([^"]*)"/.exec(head) || [])[1];
    const filename = (/filename="([^"]*)"/.exec(head) || [])[1];
    return { name, filename, data: Buffer.from(part.slice(i + 4, -2), 'latin1') };
  });
}
function send(res, status, type, body, headers = {}) {
  res.writeHead(status, { 'Content-Type': type, ...headers });
  res.end(body);
}
const json = (res, o, status = 200) => send(res, status, 'application/json', JSON.stringify(o));
const MIME = { '.js': 'application/javascript', '.css': 'text/css', '.png': 'image/png', '.json': 'application/json', '.woff2': 'font/woff2' };

function renderApp(version) {
  if (!html[version]) html[version] = execFileSync(PHP, [path.join(__dirname, 'visual-render.php'), ORIGIN, 'app', version], { encoding: 'utf8' });
  return html[version];
}

async function api(req, res, u) {
  const p = u.pathname.replace('/wp-json/noodled/v1', '');
  let m;
  if (req.method === 'GET') {
    if (p === '/probe') return json(res, { n: ++S.counter });
    if (p === '/config') return json(res, { theme: 'dark', starred: [] });
    if (p === '/notebooks') return json(res, [{ id: 1, name: 'General', label: 'General', count: notes.size, access: 'owner', owner: 1, drop: false, color: '', parent: 0, owner_name: 'Sam Rivera' }]);
    if (p === '/notes') return json(res, [...notes.values()].map(n => noteJson(n, false)));
    if ((m = p.match(/^\/notes\/(\d+)$/))) { const n = notes.get(+m[1]); return n ? json(res, noteJson(n)) : json(res, {}, 404); }
    if (p === '/trash/count') return json(res, 0);
    return json(res, []);
  }
  const body = await readBody(req);
  const data = body.length ? JSON.parse(body.toString('utf8')) : {};
  if (p === '/probe') return json(res, { n: ++S.counter, method: req.method });
  if (req.method === 'POST' && p === '/notes') {
    const id = ++nextId;
    created.push({ id, ...data });
    addNote(id, data.title, data.body || '');
    return json(res, noteJson(notes.get(id)));
  }
  if (req.method === 'POST' && p === '/attachments') {
    attachments.push(data);
    return json(res, { id: 900 + attachments.length, filename: data.filename, name: data.filename, mime: 'text/plain', url: ORIGIN + '/wp-json/noodled/v1/file/1', size: 1 });
  }
  if (req.method === 'PUT' && (m = p.match(/^\/notes\/(\d+)$/))) {
    const n = notes.get(+m[1]);
    if (!n) return json(res, { error: 'not found' }, 404);
    const fromPage = req.headers['cache-control'] === 'no-cache'; // api._fetch sends it; the worker does not
    if (S.holdPut === n.id) await new Promise(r => { S.releasePut = r; });
    // Same rule as update_note: a base that differs from the stored mtime is a conflict.
    if (data.base_modified && !data.force && data.base_modified.slice(0, 19) !== n.mtime) {
      puts.push({ id: n.id, base: data.base_modified, fromPage, result: 'conflict' });
      return json(res, { conflict: true, server: noteJson(n) });
    }
    puts.push({ id: n.id, base: data.base_modified, fromPage, result: 'saved' });
    if (data.title !== undefined) n.title = data.title;
    if (data.body !== undefined) n.body = data.body;
    n.mtime = stamp();
    return json(res, noteJson(n));
  }
  return json(res, {});
}

async function handle(req, res) {
  const u = new URL(req.url, ORIGIN);
  S.log.push({ method: req.method, path: u.pathname, search: u.search });
  if (u.pathname.startsWith('/wp-json/')) {
    if (S.apiOffline) { req.socket.destroy(); return; }
    return api(req, res, u);
  }
  if (u.pathname === '/' && u.searchParams.has('noodled_sw')) {
    return send(res, 200, 'application/javascript; charset=utf-8', fs.readFileSync(path.join(ROOT, 'assets/sw.js')),
      { 'Cache-Control': 'no-cache, no-store, must-revalidate', 'X-Content-Type-Options': 'nosniff' });
  }
  if (u.pathname === '/' && req.method === 'POST' && u.searchParams.has('noodled_share')) {
    // FALLBACK mimic (see the header comment).
    const fields = parseMultipart(await readBody(req), req.headers['content-type']);
    const q = new URLSearchParams({ noodled_share: '1' });
    for (const k of ['title', 'text', 'url']) {
      const f = fields.find(x => x.name === k && x.filename === undefined);
      const v = f ? [...f.data.toString('utf8').replace(/<[^>]*>/g, '').trim()].slice(0, 2000).join('') : '';
      if (v) q.set(k, v);
    }
    return send(res, 303, 'text/plain', '', { Location: ORIGIN + '/?' + q.toString() });
  }
  if (u.pathname === '/') return send(res, 200, 'text/html; charset=utf-8', renderApp(S.version), { 'Cache-Control': 'no-cache, no-store, must-revalidate, private' });
  if (u.pathname === '/sw-test-blank') return send(res, 200, 'text/html', '<!doctype html><title>blank</title>');
  if (u.pathname === '/wp-admin/' || u.pathname === '/wp-login.php') {
    return send(res, 200, 'text/html', `<!doctype html><title>wp</title><p id="n">${u.pathname} ${++S.counter}</p>`);
  }
  if (u.pathname.startsWith('/wp-admin/')) return send(res, 200, 'text/plain', 'admin ' + (++S.counter));
  if (u.pathname.startsWith('/wp-content/plugins/other/')) return send(res, 200, 'application/javascript', '// other ' + (++S.counter));
  if (u.pathname.startsWith('/wp-includes/')) return send(res, 200, 'application/javascript', '');
  if (u.pathname === PLUGIN + 'assets/sw.js' && S.oldWorker) return send(res, 200, 'application/javascript', OLD_WORKER);
  if (u.pathname.startsWith(PLUGIN)) {
    const rel = u.pathname.slice(PLUGIN.length).replace(/\.min\.(js|css)$/, '.$1');
    const file = path.join(ROOT, rel);
    if (!file.startsWith(ROOT) || !fs.existsSync(file) || !fs.statSync(file).isFile()) return send(res, 404, 'text/plain', '');
    let body = fs.readFileSync(file);
    if (rel === 'assets/css/noodled.css') body = Buffer.concat([body, Buffer.from(`\n:root{--sw-test-release:${S.release}}\n`)]);
    if (rel === 'assets/js/noodled.js' && (u.searchParams.get('v') || '').startsWith('probe')) body = Buffer.from('// probe ' + (++S.counter));
    return send(res, 200, MIME[path.extname(file)] || 'application/octet-stream', body);
  }
  return send(res, 404, 'text/plain', '');
}

function listen(server, host) {
  return new Promise(resolve => server.listen(0, host, () => resolve(server.address().port)));
}

// ── Harness ──

const results = [];
async function test(name, fn) {
  try { await fn(); results.push([name, null]); console.log('PASS  ' + name); }
  catch (e) { results.push([name, e]); console.log('FAIL  ' + name + '\n      - ' + (e && e.message)); }
}
function assert(cond, msg) { if (!cond) throw new Error(msg); }
function eq(want, got, msg) { if (JSON.stringify(want) !== JSON.stringify(got)) throw new Error(msg + ' (want ' + JSON.stringify(want) + ', got ' + JSON.stringify(got) + ')'); }
const sleep = ms => new Promise(r => setTimeout(r, ms));
async function until(fn, ms = 8000, msg = 'timed out') {
  const end = Date.now() + ms;
  for (;;) { const v = await fn(); if (v) return v; if (Date.now() > end) throw new Error(msg); await sleep(100); }
}

async function main() {
  const server = http.createServer((req, res) => handle(req, res).catch(e => { console.error(e); try { res.destroy(); } catch (x) {} }));
  const port = await listen(server, '127.0.0.1');
  ORIGIN = 'http://127.0.0.1:' + port;
  let otherCount = 0;
  const other = http.createServer((req, res) => send(res, 200, 'text/plain', 'cross ' + (++otherCount), { 'Access-Control-Allow-Origin': '*' }));
  OTHER = 'http://localhost:' + await listen(other, '127.0.0.1');
  if (!renderApp(S.version).includes('noodled_sw=1.0.0')) { console.error('The rendered template does not register ?noodled_sw.'); process.exit(2); }

  const { chromium } = loadPlaywright();
  const browser = await launch(chromium);
  const pageErrors = [];

  async function newContext(opts = {}) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 860 }, ...opts });
    await context.addInitScript(() => {
      try {
        localStorage.setItem('noodled_seeded', '1');
        localStorage.setItem('noodled_onboarded', '1');
        localStorage.setItem('noodled_install_dismissed', '1');
      } catch (e) {}
    });
    return context;
  }
  function watch(page) {
    page.on('pageerror', e => { if (!S.apiOffline) pageErrors.push(e.message); });
  }
  const booted = page => page.waitForFunction(() => {
    const s = document.getElementById('splash');
    return s && s.classList.contains('hidden') && typeof selectNote === 'function';
  }, null, { timeout: 15000 });
  const swState = page => page.evaluate(async () => {
    const regs = await navigator.serviceWorker.getRegistrations();
    const c = navigator.serviceWorker.controller;
    return { controller: c ? c.scriptURL : null, scopes: regs.map(r => r.scope), caches: (await caches.keys()).sort() };
  });
  const idbGet = (page, key) => page.evaluate(k => idb.get('meta', k), key);

  const context = await newContext();
  const page = await context.newPage();
  watch(page);
  const fromSw = new Map(); // url -> [fromServiceWorker, ...]
  page.on('response', r => { const a = fromSw.get(r.url()) || []; a.push(r.fromServiceWorker()); fromSw.set(r.url(), a); });

  // ── 1. Scope, control, and the old registration ──

  await test('an old registration under /wp-content/plugins/noodled/assets/ exists before the new page loads', async () => {
    await page.goto(ORIGIN + '/sw-test-blank');
    await page.evaluate(async u => { await navigator.serviceWorker.register(u); }, PLUGIN + 'assets/sw.js');
    await until(() => page.evaluate(async () => (await caches.keys()).includes('noodled-v8')), 8000, 'old worker never installed');
    const st = await swState(page);
    eq([ORIGIN + PLUGIN + 'assets/'], st.scopes, 'old scope');
    S.oldWorker = false; // from now on the server holds the new assets/sw.js at that URL, as after an update
  });

  await test('after load and one reload the app is controlled by ?noodled_sw=1.0.0 with scope /', async () => {
    await page.goto(ORIGIN + '/');
    await booted(page);
    await until(() => page.evaluate(() => !!navigator.serviceWorker.controller), 8000, 'never controlled on first load');
    await page.reload();
    await booted(page);
    const st = await swState(page);
    eq(ORIGIN + '/?noodled_sw=1.0.0', st.controller, 'controller');
    const scope = await page.evaluate(async () => (await navigator.serviceWorker.getRegistration()).scope);
    eq(ORIGIN + '/', scope, 'registration scope');
  });

  await test('the old /assets/ registration is unregistered and its noodled-v8 cache dropped', async () => {
    const st = await until(async () => { const s = await swState(page); return s.scopes.length === 1 ? s : null; }, 8000, 'old registration still there');
    eq([ORIGIN + '/'], st.scopes, 'registrations');
    assert(!st.caches.includes('noodled-v8'), 'noodled-v8 still present: ' + st.caches.join(','));
    eq(['noodled-v9-1.0.0'], st.caches.filter(k => k.startsWith('noodled-')), 'noodled caches');
  });

  await test('the worker script was served with the plugin headers', async () => {
    const h = await page.evaluate(async () => { const r = await fetch('/?noodled_sw=1.0.0'); return [r.headers.get('content-type'), r.headers.get('cache-control'), r.headers.get('x-content-type-options')]; });
    eq(['application/javascript; charset=utf-8', 'no-cache, no-store, must-revalidate', 'nosniff'], h, 'headers');
  });

  // ── 2. What the worker answers and what it never touches ──

  await test('plugin assets are answered by the worker (served from its cache)', async () => {
    const css = [...fromSw.keys()].find(u => u.includes(PLUGIN + 'assets/css/noodled'));
    assert(css, 'no stylesheet request seen');
    assert(fromSw.get(css).includes(true), 'stylesheet never came from the worker: ' + JSON.stringify(fromSw.get(css)));
    eq('r1', await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--sw-test-release').trim()), 'release marker');
    // Same URL twice while the server changes its answer: the second read is the cached first one.
    const a = await page.evaluate(async () => (await fetch('/wp-content/plugins/noodled/assets/js/noodled.js?v=probe1')).text());
    await until(async () => page.evaluate(async () => !!(await (await caches.open('noodled-v9-1.0.0')).match('/wp-content/plugins/noodled/assets/js/noodled.js?v=probe1'))), 4000, 'probe never cached');
    const b = await page.evaluate(async () => (await fetch('/wp-content/plugins/noodled/assets/js/noodled.js?v=probe1')).text());
    eq(a, b, 'second read came from the cache');
  });

  async function twice(label, fn) {
    const before = S.log.length;
    const a = await fn();
    const b = await fn();
    assert(a !== b, label + ': same answer twice, so it did not reach the server (' + a + ')');
    return S.log.slice(before);
  }

  await test('REST API requests (GET and POST) always reach the server', async () => {
    const hits = await twice('GET /wp-json', () => page.evaluate(async () => (await (await fetch('/wp-json/noodled/v1/probe')).json()).n));
    eq(2, hits.filter(h => h.path === '/wp-json/noodled/v1/probe').length, 'server saw both GETs');
    const posts = await twice('POST /wp-json', () => page.evaluate(async () => (await (await fetch('/wp-json/noodled/v1/probe', { method: 'POST', credentials: 'include', body: '{}' })).json()).n));
    eq(2, posts.filter(h => h.method === 'POST').length, 'server saw both POSTs');
    assert(!(fromSw.get(ORIGIN + '/wp-json/noodled/v1/probe') || []).includes(true), 'an API response came from the worker');
  });

  await test('wp-admin and wp-login pages always reach the server (and are inside the scope)', async () => {
    const p2 = await context.newPage();
    watch(p2);
    for (const u of ['/wp-admin/', '/wp-login.php']) {
      const r1 = await p2.goto(ORIGIN + u);
      const t1 = await p2.textContent('#n');
      const r2 = await p2.goto(ORIGIN + u);
      const t2 = await p2.textContent('#n');
      assert(t1 !== t2, u + ' answered the same twice');
      assert(!r1.fromServiceWorker() && !r2.fromServiceWorker(), u + ' came from the worker');
      assert(await p2.evaluate(() => !!navigator.serviceWorker.controller), u + ' is not controlled, so this check proves nothing');
    }
    const n = await twice('wp-admin POST with noodled_share', () => p2.evaluate(async () => (await fetch('/wp-admin/admin-post.php?noodled_share=1', { method: 'POST', body: new FormData() })).text()));
    eq(2, n.filter(h => h.method === 'POST' && h.path === '/wp-admin/admin-post.php').length, 'POST outside the app path reached the server');
    await p2.close();
  });

  await test("another plugin's file (even one named noodled.js) always reaches the server", async () => {
    const hits = await twice('other plugin', () => page.evaluate(async () => (await fetch('/wp-content/plugins/other/assets/js/noodled.js')).text()));
    eq(2, hits.length, 'server saw both');
  });

  await test('cross-origin requests always reach the other server', async () => {
    const a = await page.evaluate(async u => (await fetch(u)).text(), OTHER + '/x');
    const b = await page.evaluate(async u => (await fetch(u)).text(), OTHER + '/x');
    assert(a !== b && otherCount >= 2, 'cross-origin answered from somewhere else: ' + a + ' / ' + b);
  });

  // ── 3. Share target ──

  async function submitShare(p, fields, file) {
    await p.evaluate(f => {
      const form = document.createElement('form');
      form.method = 'POST'; form.enctype = 'multipart/form-data'; form.action = '/?noodled_share=1'; form.id = 'swTestShare';
      for (const [k, v] of Object.entries(f)) { const i = document.createElement('textarea'); i.name = k; i.value = v; form.appendChild(i); }
      const fi = document.createElement('input'); fi.type = 'file'; fi.name = 'files'; fi.id = 'swTestFile'; form.appendChild(fi);
      document.body.appendChild(form);
    }, fields);
    if (file) await p.setInputFiles('#swTestFile', file);
    await Promise.all([p.waitForURL(u => u.searchParams.has('noodled_share') || u.pathname === '/', { timeout: 10000 }), p.evaluate(() => document.getElementById('swTestShare').submit())]);
  }

  await test('a multipart share POST is stored by the worker, redirected with pending=1 and becomes a note with its file', async () => {
    const before = S.log.length;
    const nCreated = created.length;
    await submitShare(page, { title: 'Shared title', text: 'Shared text', url: 'https://example.com/shared' },
      { name: 'clip.txt', mimeType: 'text/plain', buffer: Buffer.from('hello from the share sheet') });
    await booted(page);
    await until(() => attachments.length > 0, 10000, 'no attachment uploaded');
    const log = S.log.slice(before);
    assert(!log.some(h => h.method === 'POST' && h.path === '/'), 'the share POST reached the server');
    assert(log.some(h => h.method === 'GET' && h.path === '/' && h.search.includes('noodled_share=1') && h.search.includes('pending=1')), 'no redirect to ?noodled_share=1&pending=1');
    eq(nCreated + 1, created.length, 'one note created');
    const c = created[created.length - 1];
    eq(['Shared title', 'Shared text\n\nhttps://example.com/shared'], [c.title, c.body], 'note title and body');
    const a = attachments[attachments.length - 1];
    eq(['clip.txt', c.id], [a.filename, a.note_id], 'attachment name and note');
    eq('hello from the share sheet', Buffer.from(String(a.data).replace(/^data:[^,]*,/, ''), 'base64').toString('utf8'), 'attachment content');
    eq(undefined, await idbGet(page, 'share::pending'), 'pending share deleted after use');
    eq('', await page.evaluate(() => location.search), 'share query removed from the address bar');
  });

  await test('a stored share older than one hour is discarded', async () => {
    const nCreated = created.length;
    await page.evaluate(() => idb.put('meta', { key: 'share::pending', title: 'Stale share', text: 'old', url: '', files: [], at: Date.now() - 2 * 3600 * 1000 }));
    await page.goto(ORIGIN + '/?noodled_share=1&pending=1');
    await booted(page);
    await sleep(800);
    eq(nCreated, created.length, 'no note created');
    eq(undefined, await idbGet(page, 'share::pending'), 'stale share deleted');
  });

  await test('without a worker the POST share falls back to the GET share (fields kept, cut to 2000, files dropped)', async () => {
    const ctx2 = await newContext({ serviceWorkers: 'block' });
    const p = await ctx2.newPage();
    watch(p);
    await p.goto(ORIGIN + '/');
    await booted(p);
    const before = S.log.length;
    const nCreated = created.length, nAtt = attachments.length;
    const long = 'x'.repeat(2500);
    await submitShare(p, { title: 'No worker', text: long, url: 'https://example.com/nw' },
      { name: 'dropped.txt', mimeType: 'text/plain', buffer: Buffer.from('dropped') });
    await booted(p);
    await until(() => created.length > nCreated, 10000, 'no note created');
    assert(S.log.slice(before).some(h => h.method === 'POST' && h.path === '/'), 'the POST did not reach the server');
    const c = created[created.length - 1];
    eq(['No worker', 'x'.repeat(2000) + '\n\nhttps://example.com/nw'], [c.title, c.body], 'note from the GET fallback');
    await sleep(500);
    eq(nAtt, attachments.length, 'files are dropped on the fallback');
    await ctx2.close();
  });

  // ── 4. Offline save queue replay (Background Sync) ──

  const cdp = await context.newCDPSession(page);
  const regs = new Map();
  cdp.on('ServiceWorker.workerRegistrationUpdated', e => e.registrations.forEach(r => regs.set(r.registrationId, r)));
  await cdp.send('ServiceWorker.enable');
  async function fireSync() {
    const reg = await until(() => [...regs.values()].find(r => r.scopeURL === ORIGIN + '/' && !r.isDeleted), 4000, 'no registration for /');
    await cdp.send('ServiceWorker.dispatchSyncEvent', { origin: ORIGIN, registrationId: reg.registrationId, tag: 'noodled-flush', lastChance: false });
  }
  // A fresh page for each replay case (no open conflict popup or editor state
  // left over from the previous one).
  async function prepPage() {
    await page.goto(ORIGIN + '/');
    await booted(page);
    await page.evaluate(() => {
      window.__swMsgs = [];
      navigator.serviceWorker.addEventListener('message', e => window.__swMsgs.push(e.data));
      // Keep the in-page retry out of it so only the worker replays.
      window.__realRetry = retrySaveQueue;
      window.retrySaveQueue = async () => {};
      clearInterval(saveRetryTimer); saveRetryTimer = null;
    });
  }
  async function offlineEdit(id, text) {
    await page.evaluate(async id => { await selectNote(id); }, id);
    await page.waitForFunction(id => activeNote && activeNote.id === id && document.getElementById('noteBody'), id);
    S.apiOffline = true;
    await page.evaluate(async t => { document.getElementById('noteBody').textContent = t; await doSave(); }, text);
    await page.evaluate(() => { clearInterval(saveRetryTimer); saveRetryTimer = null; });
    const q = await idbGet(page, 'queue::sam@example.com');
    // Chromium fires a just-registered sync at once; let that attempt fail while
    // still offline (the browser then backs off for minutes) before going online.
    await sleep(800);
    S.apiOffline = false;
    return q;
  }
  const queueIds = async () => ((await idbGet(page, 'queue::sam@example.com')) || { items: [] }).items.map(i => i.noteId);

  await test('offline: a save is queued with its conflict base; online: the worker replays it once and tells the page', async () => {
    await prepPage();
    const base = notes.get(1).mtime;
    const q = await offlineEdit(1, 'offline edit one');
    eq([[1, 'offline edit one', base]], q.items.map(i => [i.noteId, i.body, i.base]), 'queued item');
    const nPuts = puts.length;
    await fireSync();
    await until(() => page.evaluate(() => window.__swMsgs.length > 0), 8000, 'page never told');
    const mine = puts.slice(nPuts).filter(x => x.id === 1);
    eq([{ id: 1, base, fromPage: false, result: 'saved' }], mine, 'one worker PUT with the base');
    eq('offline edit one', notes.get(1).body, 'server copy');
    const msg = await page.evaluate(() => window.__swMsgs[0]);
    eq(['noodled-synced', 1], [msg.type, msg.count], 'message');
    eq([], await queueIds(), 'IndexedDB queue empty');
    eq(0, await page.evaluate(() => saveQueue.length), 'page queue empty');
    await until(() => page.evaluate(() => localStorage.getItem('noodled_save_queue') === '[]'), 4000, 'localStorage queue copy not cleared');
    eq(notes.get(1).mtime, await page.evaluate(() => activeNote.mtime), 'page adopted the new mtime');
    await fireSync();
    await sleep(800);
    eq(1, puts.slice(nPuts).length, 'a second sync sends nothing');
    // A reload must not bring the saved edit back from a stale copy and resend it.
    await page.reload();
    await booted(page);
    await sleep(800);
    eq(0, await page.evaluate(() => saveQueue.length), 'queue still empty after a reload');
    eq(1, puts.slice(nPuts).filter(x => x.id === 1 && x.base === base).length, 'the edit was sent once in total');
  });

  await test('the replay never overwrites a newer server copy (conflict kept for the app)', async () => {
    await prepPage();
    const q = await offlineEdit(2, 'my offline edit');
    assert(q.items.length === 1 && q.items[0].base, 'queued with a base');
    const n = notes.get(2); n.body = 'edited on another device'; n.mtime = stamp();
    const nPuts = puts.length;
    await fireSync();
    await until(() => page.evaluate(() => window.__swMsgs.length > 0), 8000, 'page never told');
    eq([{ id: 2, base: q.items[0].base, fromPage: false, result: 'conflict' }], puts.slice(nPuts), 'one PUT, refused');
    eq('edited on another device', notes.get(2).body, 'server copy kept');
    eq([2], await queueIds(), 'item stays queued for the resolver');
    await page.evaluate(() => { saveQueue = []; persistSaveQueue(); });
  });

  await test('worker and in-page retry racing: one write, no conflict popup, queue empty', async () => {
    await prepPage();
    await offlineEdit(3, 'raced edit');
    const nPuts = puts.length;
    await Promise.all([fireSync(), page.evaluate(() => window.__realRetry())]);
    await until(async () => (await queueIds()).length === 0 && (await page.evaluate(() => saveQueue.length)) === 0, 8000, 'queue not emptied');
    await sleep(500);
    const mine = puts.slice(nPuts).filter(x => x.id === 3);
    eq(1, mine.filter(x => x.result === 'saved').length, 'exactly one write');
    eq('raced edit', notes.get(3).body, 'server copy');
    eq(false, await page.evaluate(() => !!document.getElementById('cfMine')), 'no conflict popup');
  });

  await test('an edit queued while the worker replays is not lost', async () => {
    await prepPage();
    await offlineEdit(4, 'first queued');
    S.holdPut = 4;
    const nPuts = puts.length;
    const sync = fireSync();
    await until(() => !!S.releasePut, 8000, 'worker PUT never arrived');
    await page.evaluate(() => {
      saveQueue.push({ notebook: 'General', noteId: 5, title: 'Note 5', body: 'queued meanwhile', base: '2026-11-01 09:00:00', time: Date.now() });
      persistSaveQueue();
    });
    await sleep(300);
    S.holdPut = null; S.releasePut(); S.releasePut = null;
    await sync;
    await until(() => page.evaluate(() => window.__swMsgs.length > 0), 8000, 'page never told');
    eq([4], puts.slice(nPuts).filter(x => x.result === 'saved').map(x => x.id), 'note 4 saved');
    eq([5], await queueIds(), 'IndexedDB still holds the edit queued meanwhile');
    eq([5], await page.evaluate(() => saveQueue.map(i => i.noteId)), 'page queue holds it too');
    await page.evaluate(() => { saveQueue = []; persistSaveQueue(); });
  });

  // ── 5. A release replaces the worker and its cache ──

  await test('a new release takes over: new worker in control, old cache deleted, new asset shown', async () => {
    await page.evaluate(() => { window.retrySaveQueue = window.__realRetry; });
    eq('r1', await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--sw-test-release').trim()), 'before');
    S.version = '2.0.0';
    S.release = 'r2';
    // The stylesheet URL carries ?v=<version>.<mtime>, so the release is a new URL.
    assert(!renderApp('2.0.0').includes('?v=1.0.0'), 'new page still links v1 assets');
    let st;
    for (let i = 0; i < 3; i++) {
      await page.reload();
      await booted(page);
      st = await until(async () => { const s = await swState(page); return s.controller && s.controller.endsWith('noodled_sw=2.0.0') ? s : null; }, 6000).catch(() => null);
      if (st) break;
    }
    assert(st, 'new worker never took control');
    eq([ORIGIN + '/'], st.scopes, 'one registration');
    st = await until(async () => { const s = await swState(page); return s.caches.filter(k => k.startsWith('noodled-')).length === 1 ? s : null; }, 6000, 'old cache not deleted');
    eq(['noodled-v9-2.0.0'], st.caches.filter(k => k.startsWith('noodled-')), 'only the new cache');
    eq('r2', await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--sw-test-release').trim()), 'new stylesheet');
    await page.reload();
    await booted(page);
    eq('r2', await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--sw-test-release').trim()), 'new stylesheet after another reload');
    const keys = await page.evaluate(async () => (await (await caches.open('noodled-v9-2.0.0')).keys()).map(r => r.url));
    assert(!keys.some(k => k.includes('v=1.0.0')), 'the new cache holds a v1 URL: ' + keys.join(', '));
  });

  await test('no uncaught page errors while online', async () => {
    eq([], pageErrors, 'page errors');
  });

  await browser.close();
  server.close(); other.close();
  const failed = results.filter(r => r[1]).length;
  console.log('\n' + (results.length - failed) + ' passed, ' + failed + ' failed');
  process.exit(failed ? 1 : 0);
}

main().catch(e => { console.error(e); process.exit(2); });
