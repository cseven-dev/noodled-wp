/*
 * Save-conflict (mtime) and search-order tests for assets/js/noodled.js.
 *
 * Run from the repo root:   node tests/save-conflict.test.js
 *
 * Loads the REAL app code in headless Chromium via tests/harness.html (same
 * bootstrap as tests/roundtrip.test.js) and serves /wp-json/noodled/v1/* from a
 * tiny in-memory fake server. The fake keeps a second-precision mtime per note
 * and applies the same conflict rule as Noodled_REST::update_note: a base of 19+
 * characters is compared to the second, a shorter one to the minute, `force`
 * skips the check. Every save (PUT /notes/{id}) is recorded so the tests can
 * check the base_modified the client sent.
 *
 * The app is driven the way the UI drives it: open a note with selectNote(),
 * change the editor content (#noteBody), then call doSave() (what the autosave
 * timer calls); pin/move/checkbox/title/agenda/offline paths call the same
 * functions their event handlers call.
 *
 * Dependency: Playwright is NOT installed in this repo. It is loaded from
 *   C:\Users\simon\.claude\skills\gauntlet\node_modules
 * Override with NOODLED_PLAYWRIGHT_DIR. Falls back to system Chrome, then Edge.
 *
 * Exit code: 0 when every group passes, 1 otherwise (2 if the run could not start).
 */

const path = require('path');
const fs = require('fs');
const { createRequire } = require('module');

const PW_DIR = process.env.NOODLED_PLAYWRIGHT_DIR || 'C:\\Users\\simon\\.claude\\skills\\gauntlet\\node_modules';
function loadPlaywright() {
  const req = createRequire(path.join(PW_DIR, 'noop.js'));
  for (const name of ['playwright', 'playwright-core']) {
    try { return req(name); } catch (e) { /* try next */ }
  }
  console.error('Could not load Playwright from ' + PW_DIR + ' (set NOODLED_PLAYWRIGHT_DIR).');
  process.exit(2);
}

const ROOT = path.resolve(__dirname, '..');
const ORIGIN = 'http://noodled.test';
const API = ORIGIN + '/wp-json/noodled/v1';

async function launch(chromium) {
  const tries = [{}, { channel: 'chrome' }, { channel: 'msedge' }];
  let lastErr;
  for (const opt of tries) {
    try { return await chromium.launch({ headless: true, ...opt }); } catch (e) { lastErr = e; }
  }
  throw lastErr;
}

// ── In-memory fake of the notes REST API ──
const fake = {
  rows: {},          // id -> { id, title, body, notebook, pinned, created_at, modified_at }
  clock: 0,          // seconds since 2026-11-22 13:45:00 (UTC, as the server stores it)
  saves: [],         // every PUT /notes/{id}: { id, payload, response }
  putDelay: 0,       // ms to hold PUT responses (to overlap saves)
  offline: false,    // abort PUTs like a dropped connection
  searchResults: null,
  revisions: {},     // note id -> GET /notes/{id}/revisions response
  stamp() {
    const d = new Date(Date.UTC(2026, 10, 22, 13, 45, 0) + this.clock * 1000);
    return d.toISOString().slice(0, 19).replace('T', ' ');
  },
  tick() { this.clock++; return this.stamp(); },
  format(r, withBody = true) {
    const out = {
      id: r.id, slug: 'n' + r.id, notebook: r.notebook, title: r.title, source: '', pinned: !!r.pinned,
      created: r.created_at.slice(0, 16), modified: r.modified_at.slice(0, 16), mtime: r.modified_at,
      att: 0, preview: r.body.slice(0, 160), tasks: { done: 0, total: 0 },
    };
    if (withBody) out.body = r.body;
    return out;
  },
  reset(rows) {
    this.rows = {}; this.saves = []; this.putDelay = 0; this.offline = false; this.searchResults = null;
    for (const r of rows) this.rows[r.id] = { pinned: false, notebook: 'General', created_at: '2026-11-01 09:00:00', ...r };
  },
  // Same rule as Noodled_REST::update_note.
  update(id, p) {
    const cur = this.rows[id];
    if (!cur) return { error: 'Note not found' };
    const base = String(p.base_modified || '');
    if (base !== '' && !p.force) {
      const len = base.length >= 19 ? 19 : 16;
      if (cur.modified_at.slice(0, len) !== base.slice(0, len)) return { conflict: true, server: this.format(cur) };
    }
    if (p.title != null) cur.title = p.title;
    if (p.body != null) cur.body = p.body;
    cur.modified_at = this.tick();
    return this.format(cur);
  },
};

async function handleApi(route, req) {
  const u = new URL(req.url());
  const p = u.pathname.replace('/wp-json/noodled/v1', '');
  const method = req.method();
  let body = {};
  try { body = JSON.parse(req.postData() || '{}'); } catch (e) {}
  const json = (o) => route.fulfill({ contentType: 'application/json', body: JSON.stringify(o) });
  let m;
  if (method === 'GET' && p === '/notes') return json(Object.values(fake.rows).map(r => fake.format(r, false)));
  if (method === 'GET' && (m = p.match(/^\/notes\/(\d+)$/))) {
    const r = fake.rows[+m[1]];
    return r ? json({ ...fake.format(r), attachments: [] }) : route.fulfill({ status: 404, contentType: 'application/json', body: '{}' });
  }
  if (method === 'PUT' && (m = p.match(/^\/notes\/(\d+)$/))) {
    if (fake.offline) return route.abort('internetdisconnected');
    const id = +m[1];
    if (fake.putDelay) await new Promise(r => setTimeout(r, fake.putDelay));
    const res = fake.update(id, body);
    fake.saves.push({ id, payload: body, response: res });
    return json(res);
  }
  if (method === 'POST' && (m = p.match(/^\/notes\/(\d+)\/pin$/))) {
    // Noodled_Notes::toggle_pin only flips `pinned` (no modified_at write).
    const r = fake.rows[+m[1]]; r.pinned = !r.pinned; return json(fake.format(r));
  }
  if (method === 'POST' && (m = p.match(/^\/notes\/(\d+)\/move$/))) {
    const r = fake.rows[+m[1]]; r.notebook = body.notebook; r.modified_at = fake.tick(); return json(fake.format(r));
  }
  if (method === 'POST' && (m = p.match(/^\/notes\/(\d+)\/task-toggle$/))) {
    // Noodled_Notes::toggle_task: flip the idx-th checklist item, then update().
    const r = fake.rows[+m[1]];
    let count = 0;
    r.body = r.body.split('\n').map(line => {
      const mm = line.match(/^(\s*[-*+]\s+\[)([ xX])(\].*)$/);
      if (!mm) return line;
      return (count++ === body.idx) ? mm[1] + (mm[2] === ' ' ? 'x' : ' ') + mm[3] : line;
    }).join('\n');
    r.modified_at = fake.tick();
    return json(fake.format(r));
  }
  if (method === 'GET' && (m = p.match(/^\/notes\/(\d+)\/revisions$/))) return json(fake.revisions[+m[1]] || []);
  if (method === 'GET' && p === '/search') return json(fake.searchResults || []);
  if (method === 'GET' && p === '/bodies') return json(Object.values(fake.rows).map(r => ({ id: r.id, body: r.body })));
  return json([]);
}

async function main() {
  const { chromium } = loadPlaywright();
  const browser = await launch(chromium);
  const context = await browser.newContext({ serviceWorkers: 'block' });
  await context.route('**/*', route => {
    const req = route.request();
    const url = req.url();
    if (url === ORIGIN + '/harness.html') return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(__dirname, 'harness.html')) });
    if (url === ORIGIN + '/noodled.js') return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(ROOT, 'assets', 'js', 'noodled.js')) });
    if (url.startsWith(API + '/') && req.resourceType() === 'fetch') {
      return handleApi(route, req).catch(e => route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'fake server: ' + e.message }) }));
    }
    if (url.startsWith(ORIGIN + '/wp-json/') && req.resourceType() === 'fetch') return route.fulfill({ contentType: 'application/json', body: '[]' });
    return route.abort();
  });
  const page = await context.newPage();
  const pageErrors = [];
  page.on('pageerror', e => pageErrors.push(e.message));

  fake.reset([]);
  await page.goto(ORIGIN + '/harness.html');
  await page.waitForFunction(() => typeof doSave === 'function' && typeof selectNote === 'function');
  await page.waitForFunction(() => document.getElementById('splash').classList.contains('hidden'), null, { timeout: 10000 }).catch(() => {});
  await page.waitForTimeout(300);

  // Page-side helpers: open a note like a list click, type into the editor.
  await page.evaluate(() => {
    window.__t = {
      async open(id) {
        document.getElementById('modalContainer').innerHTML = ''; _conflictOpen = false;
        clearTimeout(saveTimer); hasUnsavedChanges = false;
        // No offline edits carried over from an earlier group.
        saveQueue = []; persistSaveQueue(); clearInterval(saveRetryTimer); saveRetryTimer = null;
        activeNote = null; // the previous group's note must not be saved into this one
        showRawMarkdown = false;
        activeNotebook = null; viewingTrash = false;
        await loadNotes();
        await selectNote(id);
      },
      // Replace the editor content (what typing does) and run the autosave.
      type(md) {
        const el = document.getElementById('noteBody');
        el.innerHTML = renderMarkdown(md);
        hasUnsavedChanges = true;
      },
      state() { return { mtime: activeNote && activeNote.mtime, modified: activeNote && activeNote.modified, body: activeNote && activeNote.body, conflictOpen: _conflictOpen, dialog: !!document.querySelector('#modalContainer #cfMine') }; },
      listMtime(id) { const n = notes.find(x => x.id === id); return n && n.mtime; },
      order() { return [...document.querySelectorAll('#noteList .note-item')].map(e => +e.dataset.noteId); },
    };
  });

  const results = [];
  async function group(name, fn) {
    const problems = [];
    const check = (ok, msg) => { if (!ok) problems.push(msg); };
    let timer;
    const timeout = new Promise((_, rej) => { timer = setTimeout(() => rej(new Error('timed out after 20s')), 20000); });
    try { await Promise.race([fn(check), timeout]); } catch (e) { problems.push('threw: ' + e.message.split('\n')[0]); }
    clearTimeout(timer);
    results.push(!problems.length);
    console.log((problems.length ? 'FAIL  ' : 'PASS  ') + name);
    for (const p of problems) console.log('      ' + p);
  }
  const ev = (fn, arg) => page.evaluate(fn, arg);
  const lastSave = () => fake.saves[fake.saves.length - 1];

  // Fresh note 1 at 13:45:10 for each group.
  function freshNote(extra) {
    fake.clock = 10;
    fake.reset([{ id: 1, title: 'Alpha', body: 'first line', modified_at: fake.stamp(), ...(extra || {}) },
                { id: 2, title: 'Other note', body: 'other', modified_at: '2026-11-20 08:00:00' }]);
  }

  // 1. open, edit, save: base_modified == mtime; new mtime adopted.
  await group('1 save sends mtime as base and adopts the new mtime', async check => {
    freshNote();
    await ev(() => __t.open(1));
    const before = await ev(() => __t.state());
    check(before.mtime === '2026-11-22 13:45:10', 'opened note mtime is ' + before.mtime);
    await ev(() => { __t.type('first line edited'); return doSave(); });
    const s = lastSave();
    check(s && s.payload.base_modified === '2026-11-22 13:45:10', 'base_modified sent: ' + (s && s.payload.base_modified));
    check(s && !s.response.conflict, 'save conflicted');
    const after = await ev(() => __t.state());
    check(after.mtime === fake.rows[1].modified_at && after.mtime !== before.mtime, 'client mtime ' + after.mtime + ' vs server ' + fake.rows[1].modified_at);
    check(await ev(() => __t.listMtime(1)) === fake.rows[1].modified_at, 'notes list entry did not take the new mtime');
  });

  // 2. a second save right after uses the new mtime.
  await group('2 second save right after uses the new mtime (no conflict)', async check => {
    const prev = fake.rows[1].modified_at;
    await ev(() => { __t.type('first line edited again'); return doSave(); });
    const s = lastSave();
    check(s.payload.base_modified === prev, 'base ' + s.payload.base_modified + ' expected ' + prev);
    check(!s.response.conflict, 'conflicted');
    check(fake.rows[1].body.includes('edited again'), 'server body not updated');
  });

  // 2b. overlapping saves (typing during an in-flight save) are serialized.
  await group('2b typing during an in-flight save: second save waits and uses the first one\'s mtime', async check => {
    fake.putDelay = 300;
    const n0 = fake.saves.length;
    await ev(async () => {
      __t.type('overlap one');
      const a = doSave();
      await new Promise(r => setTimeout(r, 50)); // first PUT is in flight
      __t.type('overlap two');
      const b = doSave();
      await Promise.all([a, b]);
    });
    fake.putDelay = 0;
    const [s1, s2] = fake.saves.slice(n0);
    check(s1 && s2, 'expected two saves, got ' + (fake.saves.length - n0));
    if (s1 && s2) {
      check(!s1.response.conflict && !s2.response.conflict, 'a save conflicted');
      check(s2.payload.base_modified === s1.response.mtime, 'second base ' + s2.payload.base_modified + ' vs first response ' + s1.response.mtime);
    }
    check(fake.rows[1].body.includes('overlap two'), 'server lost the second edit');
    check((await ev(() => __t.state())).dialog === false, 'conflict dialog shown');
  });

  // 3. every other mutation on an open note leaves the next save conflict-free.
  async function afterMutation(check, label, mutate) {
    await mutate();
    await page.waitForTimeout(50);
    const server = fake.rows[1].modified_at;
    const st = await ev(() => __t.state());
    check(st.mtime === server, label + ': client mtime ' + st.mtime + ' vs server ' + server);
    await ev(() => { const el = document.getElementById('noteBody'); __t.type(currentBody() + '\n\nafter ' + Date.now()); return doSave(); });
    const s = lastSave();
    check(s.payload.base_modified === server, label + ': next save base ' + s.payload.base_modified + ' vs ' + server);
    check(!s.response.conflict, label + ': next save conflicted');
  }
  await group('3 pin, checkbox, move, title-only save, agenda task toggle: next save uses the refreshed mtime', async check => {
    freshNote({ body: '- [ ] task one\n- [ ] task two' });
    await ev(() => __t.open(1));
    await afterMutation(check, 'pin', () => ev(() => togglePin(1)));
    check(fake.rows[1].pinned === true, 'pin did not reach the server');
    await afterMutation(check, 'checkbox', () => ev(async () => { document.querySelector('#noteBody input[type=checkbox]').click(); await new Promise(r => setTimeout(r, 100)); }));
    check(/- \[x\] task one/.test(fake.rows[1].body), 'checkbox toggle not saved: ' + JSON.stringify(fake.rows[1].body));
    await afterMutation(check, 'move', () => ev(() => moveNote(1, 'Work')));
    check(fake.rows[1].notebook === 'Work', 'move did not reach the server');
    await afterMutation(check, 'title-only save', () => ev(async () => {
      const t = document.getElementById('titleInput'); t.value = 'Alpha renamed';
      t.dispatchEvent(new Event('change'));
      await new Promise(r => setTimeout(r, 150));
    }));
    check(fake.rows[1].title === 'Alpha renamed', 'title not saved');
    await afterMutation(check, 'agenda task toggle', () => ev(() => toggleAgendaTask(1, 1, null)));
    check(/- \[x\] task two/.test(fake.rows[1].body), 'agenda toggle not applied');
  });

  // 3b. offline queue: save fails, edit is queued with the mtime base; on
  // reconnect the replay succeeds and the open note adopts the new mtime.
  await group('3b offline queue replay adopts the new mtime; next save does not conflict', async check => {
    freshNote();
    await ev(() => __t.open(1));
    fake.offline = true;
    await ev(() => { __t.type('typed offline'); return doSave(); });
    const q = await ev(() => saveQueue.map(i => ({ noteId: i.noteId, base: i.base })));
    check(q.length === 1 && q[0].base === '2026-11-22 13:45:10', 'queue: ' + JSON.stringify(q));
    // Save status: the failed save marked the app offline, and the queued edit is never shown as saved.
    const offlineText = await ev(() => document.getElementById('saveIndicator').textContent);
    check(offlineText === 'Offline, saved on this device', 'save status with an offline queue: ' + JSON.stringify(offlineText));
    fake.offline = false;
    await ev(() => retrySaveQueue());
    const s = lastSave();
    check(s && s.payload.base_modified === '2026-11-22 13:45:10' && !s.response.conflict, 'replay: ' + JSON.stringify(s && s.payload.base_modified));
    check(fake.rows[1].body.includes('typed offline'), 'replay did not save');
    check(await ev(() => saveQueue.length) === 0, 'queue not emptied');
    check((await ev(() => __t.state())).mtime === fake.rows[1].modified_at, 'open note did not adopt the replay mtime');
    const server = fake.rows[1].modified_at;
    await ev(() => { __t.type('typed offline\n\nback online'); return doSave(); });
    check(lastSave().payload.base_modified === server && !lastSave().response.conflict, 'post-replay save base ' + lastSave().payload.base_modified);
  });

  // 3c. service-worker background flush: the app adopts the mtime it reports.
  await group('3c service-worker flush message: open note adopts the flushed mtime', async check => {
    freshNote();
    await ev(() => __t.open(1));
    fake.offline = true;
    await ev(() => { __t.type('sw queued'); return doSave(); });
    fake.offline = false;
    const item = await ev(() => saveQueue[0]);
    // What sw.js flushQueue does: PUT with the queued base, then post the result.
    const note = fake.update(1, { title: item.title, body: item.body, base_modified: item.base });
    check(!note.conflict, 'sw replay conflicted');
    // ...and, like flushQueue, store the now-empty queue before posting.
    await ev(() => idb.put('meta', { key: 'queue::' + userScope(), items: [] }));
    await ev(({ item, note }) => onSwMessage({ data: { type: 'noodled-synced', count: 1, saved: [{ item, note }] } }), { item, note });
    await page.waitForTimeout(100);
    check((await ev(() => __t.state())).mtime === fake.rows[1].modified_at, 'open note did not adopt the SW mtime');
    check(await ev(() => saveQueue.length) === 0, 'in-memory queue not re-synced from IndexedDB');
    await ev(() => { __t.type('sw queued\n\nmore'); return doSave(); });
    check(!lastSave().response.conflict, 'save after SW flush conflicted');
  });

  // 4. changed on another device in the SAME minute, different second.
  await group('4 another device saved in the same minute: conflict response and dialog', async check => {
    freshNote();
    await ev(() => __t.open(1));
    // Other device: 13:45:10 -> 13:45:11 (same minute).
    fake.rows[1].body = 'from the other device';
    fake.rows[1].modified_at = fake.tick();
    await ev(() => { __t.type('mine, typed here'); return doSave(); });
    const s = lastSave();
    check(s.payload.base_modified === '2026-11-22 13:45:10', 'base ' + s.payload.base_modified);
    check(s.response.conflict === true, 'no conflict response (would have overwritten silently)');
    check(fake.rows[1].body === 'from the other device', 'other device text was overwritten');
    const st = await ev(() => __t.state());
    check(st.dialog, 'conflict dialog not shown');
    // The save status must not claim the conflicted save went through (or still runs).
    const statusText = await ev(() => document.getElementById('saveIndicator').textContent);
    check(statusText !== 'Saved' && !statusText.startsWith('Saving'), 'save status after a conflict: ' + JSON.stringify(statusText));
    // Resolve with "Load theirs" and confirm the next save is clean.
    await ev(async () => { document.getElementById('cfTheirs').click(); await new Promise(r => setTimeout(r, 150)); });
    check((await ev(() => __t.state())).mtime === fake.rows[1].modified_at, 'theirs not loaded with its mtime');
    await ev(() => { __t.type('from the other device\n\nplus mine'); return doSave(); });
    check(!lastSave().response.conflict, 'save after "Load theirs" conflicted');
  });

  // 4b. "Keep mine" (force) and same-content short-circuit.
  await group('4b keep mine forces and adopts; identical content on both sides raises no dialog', async check => {
    freshNote();
    await ev(() => __t.open(1));
    fake.rows[1].body = 'other device again'; fake.rows[1].modified_at = fake.tick();
    await ev(() => { __t.type('mine wins'); return doSave(); });
    check((await ev(() => __t.state())).dialog, 'dialog not shown');
    await ev(async () => { document.getElementById('cfMine').click(); await new Promise(r => setTimeout(r, 150)); });
    check(lastSave().payload.force === true && fake.rows[1].body.includes('mine wins'), 'keep mine did not force-save');
    check((await ev(() => __t.state())).mtime === fake.rows[1].modified_at, 'keep mine did not adopt mtime');
    // Server already holds exactly what we are about to save (e.g. the unload
    // keepalive save, which sends no base, landed first).
    const body = await ev(() => { __t.type('same text'); return currentBody(); });
    fake.rows[1].body = body; fake.rows[1].modified_at = fake.tick();
    await ev(() => doSave());
    check(lastSave().response.conflict === true, 'expected the server to report a conflict');
    const st = await ev(() => __t.state());
    check(!st.dialog && !st.conflictOpen, 'dialog shown for identical content');
    check(st.mtime === fake.rows[1].modified_at, 'did not adopt server mtime for identical content');
  });

  // 5. old cached note object without mtime: falls back to `modified` (minute).
  await group('5 note without mtime saves with the 16-character modified base', async check => {
    freshNote();
    await ev(() => __t.open(1));
    await ev(() => { delete activeNote.mtime; });
    fake.rows[1].modified_at = '2026-11-22 13:45:40'; // same minute, other second: minute rule passes
    await ev(() => { __t.type('legacy client edit'); return doSave(); });
    const s = lastSave();
    check(s.payload.base_modified === '2026-11-22 13:45', 'base ' + s.payload.base_modified);
    check(!s.response.conflict && fake.rows[1].body.includes('legacy client edit'), 'minute-rule save failed');
    check((await ev(() => __t.state())).mtime === fake.rows[1].modified_at, 'did not adopt mtime after legacy save');
  });

  // 6. search: title matches first, each group in the existing sort order.
  await group('6 search lists title matches first (both search paths); no query keeps the order', async check => {
    fake.reset([
      { id: 11, title: 'Pump repair', body: 'notes', modified_at: '2026-11-20 10:00:00' },        // A
      { id: 12, title: 'Groceries', body: 'buy a new pump', modified_at: '2026-11-21 10:00:00' }, // B (newest)
      { id: 13, title: 'Old pump manual', body: 'pages', modified_at: '2026-11-10 10:00:00' },    // C (oldest)
    ]);
    await ev(async () => {
      document.getElementById('modalContainer').innerHTML = ''; _conflictOpen = false;
      activeNote = null; activeNotebook = null; sortMode = 'modified'; searchScope = 'notebook';
      document.getElementById('searchInput').value = '';
      await loadNotes(); renderContent();
    });
    check(JSON.stringify(await ev(() => __t.order())) === '[12,11,13]', 'no-query order ' + JSON.stringify(await ev(() => __t.order())));
    await ev(async () => { document.getElementById('searchInput').value = 'pump'; onSearch(); await new Promise(r => setTimeout(r, 200)); });
    check(JSON.stringify(await ev(() => __t.order())) === '[11,13,12]', 'client search order ' + JSON.stringify(await ev(() => __t.order())));
    // Server-search branch (scope "All" inside a notebook).
    fake.searchResults = [12, 13, 11].map(id => fake.format(fake.rows[id], false));
    await ev(async () => { activeNotebook = 'General'; searchScope = 'all'; filterNotes(); await new Promise(r => setTimeout(r, 200)); });
    check(JSON.stringify(await ev(() => __t.order())) === '[11,13,12]', 'api.search order ' + JSON.stringify(await ev(() => __t.order())));
    await ev(async () => { searchScope = 'notebook'; activeNotebook = null; document.getElementById('searchInput').value = ''; onSearch(); await new Promise(r => setTimeout(r, 100)); });
    check(JSON.stringify(await ev(() => __t.order())) === '[12,11,13]', 'cleared-query order ' + JSON.stringify(await ev(() => __t.order())));
  });

  // 7. saves outside the autosave path also carry the conflict base.
  await group('7 title-only save, checkbox toggle and lock send base_modified; a stale base opens the conflict dialog', async check => {
    freshNote({ body: '- [ ] task one\n- [ ] task two' });
    await ev(() => __t.open(1));
    const titleSave = () => ev(async () => {
      const t = document.getElementById('titleInput'); t.value = 'Alpha ' + Date.now();
      await saveTitleOnly();
    });
    let server = fake.rows[1].modified_at;
    await titleSave();
    check(lastSave().payload.base_modified === server && !lastSave().response.conflict, 'title-only save base ' + lastSave().payload.base_modified + ' vs ' + server);
    server = fake.rows[1].modified_at;
    await ev(async () => { document.querySelector('#noteBody input[type=checkbox]').click(); await new Promise(r => setTimeout(r, 100)); });
    check(lastSave().payload.base_modified === server && !lastSave().response.conflict, 'checkbox save base ' + lastSave().payload.base_modified + ' vs ' + server);
    server = fake.rows[1].modified_at;
    // Lock with a stand-in cipher: the harness is not a secure context, so WebCrypto is unavailable.
    await ev(async () => {
      window.__realEncrypt = encryptBody; window._protectPass = 'test-pass';
      encryptBody = async (plain) => 'noodled:enc:v1:' + btoa(unescape(encodeURIComponent(plain)));
      try { await lockCurrentNote(); } finally { encryptBody = window.__realEncrypt; window._protectPass = ''; }
    });
    check(lastSave().payload.body.startsWith('noodled:enc:v1:'), 'lock did not save the locked body');
    // lockCurrentNote flushes pending edits first, so its base is the mtime the save before it returned.
    server = fake.saves[fake.saves.length - 2].response.mtime;
    check(lastSave().payload.base_modified === server && !lastSave().response.conflict, 'lock save base ' + lastSave().payload.base_modified + ' vs ' + server);
    // Another device saves behind the client's back; the next title-only save must not overwrite it.
    fake.rows[1].body = 'from the other device';
    fake.rows[1].modified_at = fake.tick();
    const saves = fake.saves.length;
    await titleSave();
    check(fake.saves.length === saves + 1 && !!lastSave().payload.base_modified, 'stale title-only save sent no base_modified');
    check(lastSave().response.conflict === true, 'stale title-only save did not get a conflict response');
    check(fake.rows[1].body === 'from the other device', 'other device text was overwritten');
    check((await ev(() => __t.state())).conflictOpen === true, 'conflict dialog not opened');
  });

  // 8. restoring a version saves its exact text with snapshot:true.
  await group('8 restore sends snapshot:true and the exact revision body', async check => {
    freshNote();
    // Text the editor round trip would normalise (extra blank lines, trailing spaces, * bullets).
    const oldBody = 'Line one  \n\n\n\nLine two\n* star bullet\n';
    fake.revisions = { 1: [{ id: 5, title: 'Alpha old', body: oldBody, reason: 'edit', time: '2026-11-22T13:00:00Z' }] };
    await ev(() => __t.open(1));
    // Clear toasts left by earlier groups: showToast loops forever once a 5th toast is stacked
    // (dismissToast(..., true) removes on a timer, so the while loop never shrinks the stack).
    await ev(() => { const st = document.getElementById('toastStack'); if (st) st.innerHTML = ''; });
    await ev(() => showHistory());
    check(await ev(() => document.querySelector('#modalContainer .history-item') !== null), 'history list not shown');
    await ev(() => restoreHistory(0));
    const s = lastSave();
    // restoreHistory flushes pending edits first, so its base is the mtime the save before it returned.
    const server = fake.saves.length > 1 ? fake.saves[fake.saves.length - 2].response.mtime : '2026-11-22 13:45:10';
    check(s && s.payload.snapshot === true, 'restore did not send snapshot:true');
    check(s && s.payload.body === oldBody, 'restore body not exact: ' + JSON.stringify(s && s.payload.body));
    check(s && s.payload.title === 'Alpha old', 'restore title ' + (s && s.payload.title));
    check(s && s.payload.base_modified === server && !s.response.conflict, 'restore base ' + (s && s.payload.base_modified) + ' vs ' + server);
    check(fake.rows[1].body === oldBody, 'server body not restored');
    const st = await ev(() => __t.state());
    check(st.body === oldBody && st.mtime === fake.rows[1].modified_at, 'open note did not adopt the restored version');
    check(await ev(() => document.querySelector('#modalContainer .modal') === null), 'history modal not closed');
    fake.revisions = {};
  });

  const harnessErrors = await page.evaluate(() => window.__pageErrors.slice());
  await browser.close();
  const passed = results.filter(Boolean).length, failed = results.length - passed;
  console.log('');
  console.log(`${passed} passed, ${failed} failed (${results.length} groups)`);
  const errs = [...new Set([...harnessErrors, ...pageErrors])];
  if (errs.length) console.log('Page errors seen (informational): ' + errs.join(' | '));
  process.exit(failed ? 1 : 0);
}

main().catch(e => { console.error(e); process.exit(2); });
