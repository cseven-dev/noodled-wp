/*
 * Screenshot harness: renders the REAL app (templates/app.php + assets) in
 * headless Chromium against an in-memory fake REST API and saves PNGs.
 *
 * Run from the repo root:   node tests/visual.js <label>
 *
 * - tests/visual-render.php (PHP CLI, WordPress stubs) prints the real template.
 * - The page is served at http://noodled.test/. Plugin assets are served from
 *   this repo; requests for noodled.min.js / noodled.min.css are answered with
 *   the SOURCE files so shots always show current code. Every other request
 *   (CDNs, wp-includes scripts) is blocked or stubbed.
 * - Shots go to <scratch>/shots/<label>/ (never into the repo). Override the
 *   base folder with NOODLED_SHOTS_DIR, the PHP binary with NOODLED_PHP.
 *
 * Dependency: Playwright from C:\Users\simon\.claude\skills\gauntlet\node_modules
 * (override with NOODLED_PLAYWRIGHT_DIR). Falls back to system Chrome, then Edge.
 *
 * Exit code: 0 when every shot was taken, 1 if the app failed to boot or a shot
 * failed, 2 if the run could not start.
 */

const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
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
const PLUGIN = '/wp-content/plugins/noodled/';
const PHP = process.env.NOODLED_PHP || 'C:\\Users\\simon\\AppData\\Roaming\\Local\\lightning-services\\php-8.2.29+0\\bin\\win64\\php.exe';
const SHOTS_BASE = process.env.NOODLED_SHOTS_DIR || 'C:\\Users\\simon\\AppData\\Local\\Temp\\claude\\86dcffa33c9eb493\\e4804abc-ec74-4d1a-ae7e-509852137eb5\\scratchpad\\shots';

const label = (process.argv[2] || '').replace(/[^\w.-]/g, '');
if (!label) { console.error('Usage: node tests/visual.js <label>'); process.exit(2); }
const OUT = path.join(SHOTS_BASE, label);

async function launch(chromium) {
  const tries = [{}, { channel: 'chrome' }, { channel: 'msedge' }];
  let lastErr;
  for (const opt of tries) {
    try { return await chromium.launch({ headless: true, ...opt }); } catch (e) { lastErr = e; }
  }
  throw lastErr;
}

// ── Fixture ──
const NOW = Date.now();
const DAY = 86400000;
function stamp(daysAgo, hour = 10) {
  const d = new Date(NOW - daysAgo * DAY);
  d.setHours(hour, 15, 0, 0);
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:00`;
}

const SHOWCASE_ID = 101;
const SHOWCASE = [
  '# Kitchen renovation plan',
  '',
  'Notes from the walkthrough with the contractor. Budget is tight, so we are doing it in **two phases** and keeping the *existing* plumbing where we can.',
  '',
  '## Phase one',
  '',
  '- Demolition and disposal',
  '  - Upper cabinets on the north wall',
  '  - Old tile backsplash',
  '- Electrical: two new circuits for the island',
  '- Drywall patching',
  '',
  '### Order of work',
  '',
  '1. Book the permit inspection',
  '2. Confirm the cabinet delivery date',
  '3. Schedule the countertop template visit',
  '',
  '## Checklist',
  '',
  '- [x] Pick the cabinet colour',
  '- [ ] Get a second quote for the countertop',
  '- [ ] Ask about the vent hood clearance',
  '',
  '> Measure twice, order once. The last renovation slipped two weeks over a single wrong dimension.',
  '',
  'The breaker panel label says `CIRCUIT 14` for the old range. Paint codes are tracked in [[Paint colours]], see also #renovation.',
  '',
  '```js',
  'const budget = { phaseOne: 18500, phaseTwo: 12000 };',
  'console.log(budget.phaseOne + budget.phaseTwo);',
  '```',
  '',
  '| Item | Supplier | Cost |',
  '| --- | --- | --- |',
  '| Cabinets | Northwood | $7,400 |',
  '| Countertop | Stoneworks | $3,900 |',
  '| Lighting | Brightline | $650 |',
  '',
  'Supplier price sheet: [Northwood catalogue](https://example.com/northwood).',
].join('\n');

const NOTEBOOKS = [
  { id: 1, name: 'General', label: 'General', count: 0, access: 'owner', owner: 1, drop: false, color: '', parent: 0, owner_name: 'Sam Rivera' },
  { id: 2, name: 'Home', label: 'Home', count: 0, access: 'owner', owner: 1, drop: false, color: '', parent: 0, owner_name: 'Sam Rivera' },
  { id: 3, name: 'Work', label: 'Work', count: 0, access: 'owner', owner: 1, drop: false, color: '', parent: 0, owner_name: 'Sam Rivera' },
  { id: 4, name: 'Recipes', label: 'Recipes', count: 0, access: 'owner', owner: 1, drop: false, color: '', parent: 0, owner_name: 'Sam Rivera' },
];

const NOTE_ROWS = [
  { id: SHOWCASE_ID, notebook: 'Home', title: 'Kitchen renovation plan', body: SHOWCASE, days: 0, pinned: true },
  { id: 102, notebook: 'Home', title: 'Paint colours', body: 'Living room: Hale Navy. Hallway: Revere Pewter, eggshell finish. Trim stays Chantilly Lace.', days: 2, image: 501 },
  { id: 103, notebook: 'Work', title: 'Q4 planning meeting', body: 'Attendees: Priya, Marco, Jen.\n\n- [x] Share the draft roadmap\n- [ ] Follow up on hiring budget\n- [ ] Book the offsite venue', days: 1 },
  { id: 104, notebook: 'Work', title: 'Onboarding checklist for new hires', body: 'Laptop, accounts, buddy assignment, first-week reading list, and a lunch on day one.', days: 9 },
  { id: 105, notebook: 'General', title: 'Books to read', body: 'The Overstory, Piranesi, A Psalm for the Wild-Built, The Ministry for the Future.', days: 16 },
  { id: 106, notebook: 'General', title: 'Gift ideas', body: 'Mom: pottery class voucher. Dad: a decent bread knife. Alex: climbing gym pass.', days: 34 },
  { id: 107, notebook: 'Work', title: 'API migration notes', body: 'The v2 endpoints return ISO dates. Keep the v1 adapter until all three clients are switched over.', days: 51 },
  { id: 108, notebook: 'Home', title: 'Garden layout', body: 'Tomatoes along the south fence, herbs in the raised bed, and move the lavender closer to the path.', days: 75 },
  { id: 109, notebook: 'General', title: 'Trip to Lisbon', body: 'Flights booked for May. Want to see Alfama, the tile museum, and take the train out to Sintra.', days: 110 },
  { id: 110, notebook: 'Work', title: 'Performance review prep', body: 'Wins: shipped the billing rewrite, mentored two juniors. Growth: delegate earlier.', days: 150 },
  { id: 111, notebook: 'General', title: 'Running log', body: 'Week 12: 5k in 27:40, long run 14k. Knee feels fine after the new shoes.', days: 196 },
  { id: 112, notebook: 'Home', title: 'Car maintenance', body: 'Oil change at 82,000 km. Winter tires go on in November, rotate in spring.', days: 240 },
  { id: 113, notebook: 'General', title: 'Podcast episode ideas', body: 'Interview a beekeeper, a history of the paperclip, and why trains run late.', days: 300 },
  { id: 114, notebook: 'Work', title: 'Conference talk outline', body: 'Opening story, three lessons from the outage, live demo, questions.', days: 352 },
];
const STARRED = [103];
NOTE_ROWS.forEach(r => { r.modified_at = stamp(r.days, 9 + (r.id % 8)); r.created_at = stamp(r.days + 3, 9); });
NOTEBOOKS.forEach(nb => { nb.count = NOTE_ROWS.filter(r => r.notebook === nb.name).length; });

// A small generated PNG (a paint-swatch gradient) served for /file/<id>, so the
// note-list thumbnail has something to show. Built with zlib, no image library.
function makePng(w, h, pixel) {
  const zlib = require('zlib');
  const crcTable = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0; });
  const crc = (buf) => { let c = 0xffffffff; for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
  const chunk = (type, data) => {
    const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
    const td = Buffer.concat([Buffer.from(type, 'ascii'), data]);
    const c = Buffer.alloc(4); c.writeUInt32BE(crc(td));
    return Buffer.concat([len, td, c]);
  };
  const raw = Buffer.alloc((w * 3 + 1) * h);
  for (let y = 0; y < h; y++) {
    raw[y * (w * 3 + 1)] = 0;
    for (let x = 0; x < w; x++) pixel(x, y).forEach((v, i) => { raw[y * (w * 3 + 1) + 1 + x * 3 + i] = v; });
  }
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
const SWATCH_PNG = makePng(160, 160, (x, y) => x < 80 ? [31, 58, 96] : [Math.round(150 + y / 3), Math.round(150 + y / 3), 160]);

function taskCounts(body) {
  const m = body.match(/^\s*[-*+]\s+\[[ xX]\]/gm) || [];
  const done = (body.match(/^\s*[-*+]\s+\[[xX]\]/gm) || []).length;
  return { done, total: m.length };
}
function formatNote(r, withBody = true) {
  const out = {
    id: r.id, slug: 'n' + r.id, notebook: r.notebook, title: r.title, source: '', pinned: !!r.pinned,
    created: r.created_at.slice(0, 16), modified: r.modified_at.slice(0, 16), mtime: r.modified_at,
    att: r.image ? 1 : 0, thumb: r.image || 0, preview: r.body.split('\n').map(l => l.replace(/^\s*(#+|>|[-*+](\s+\[[ xX]\])?|\d+\.|\|)\s*/, '')).join(' ').replace(/[*`|]/g, '').replace(/\s+/g, ' ').trim().slice(0, 160), tasks: taskCounts(r.body),
  };
  if (withBody) out.body = r.body;
  return out;
}

function handleApi(route, req, theme) {
  const u = new URL(req.url());
  const p = u.pathname.replace('/wp-json/noodled/v1', '');
  const method = req.method();
  const json = (o) => route.fulfill({ contentType: 'application/json', body: JSON.stringify(o) });
  let m;
  if (method === 'GET') {
    if (p === '/config') return json({ theme, starred: STARRED });
    if (p === '/notebooks') return json(NOTEBOOKS);
    if (p === '/notes') {
      const nb = u.searchParams.get('notebook');
      return json(NOTE_ROWS.filter(r => !nb || r.notebook === nb).map(r => formatNote(r, false)));
    }
    if ((m = p.match(/^\/notes\/(\d+)$/))) {
      const r = NOTE_ROWS.find(x => x.id === +m[1]);
      const attachments = r && r.image ? [{ id: r.image, filename: 'swatch.png', url: ORIGIN + '/wp-json/noodled/v1/file/' + r.image, mime: 'image/png', size: SWATCH_PNG.length, alt: '', exif: null }] : [];
      return r ? json({ ...formatNote(r), attachments }) : route.fulfill({ status: 404, contentType: 'application/json', body: '{}' });
    }
    // The file proxy (thumbnails included): one generated image for every id.
    if (/^\/file\/\d+$/.test(p)) return route.fulfill({ contentType: 'image/png', body: SWATCH_PNG });
    if (p === '/bodies') return json(NOTE_ROWS.map(r => ({ id: r.id, body: r.body })));
    if (p === '/trash/count') return json(0);
    if (p === '/search') {
      const q = (u.searchParams.get('q') || '').toLowerCase();
      return json(NOTE_ROWS.filter(r => (r.title + ' ' + r.body).toLowerCase().includes(q)).map(r => formatNote(r, false)));
    }
    return json([]);
  }
  return json({});
}

function renderTemplate(which = 'app') {
  return execFileSync(PHP, [path.join(__dirname, 'visual-render.php'), ORIGIN, which], { encoding: 'utf8' });
}

const MIME = { '.js': 'application/javascript', '.css': 'text/css', '.png': 'image/png', '.json': 'application/json', '.woff2': 'font/woff2' };

async function main() {
  const html = renderTemplate();
  if (!html.includes('id="modalContainer"')) { console.error('visual-render.php did not produce the app shell.'); process.exit(2); }

  const { chromium } = loadPlaywright();
  const browser = await launch(chromium);
  fs.mkdirSync(OUT, { recursive: true });
  const written = [];
  const problems = [];
  const blocked = new Set();

  async function openApp({ theme, phone }) {
    const context = await browser.newContext(phone
      ? { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
      : { viewport: { width: 1440, height: 900 } });
    // Skip first-run popups (what's new, starter note, onboarding, install prompt).
    await context.addInitScript(() => {
      try {
        localStorage.setItem('noodled_seen_version', '0.0.0');
        localStorage.setItem('noodled_seeded', '1');
        localStorage.setItem('noodled_onboarded', '1');
        localStorage.setItem('noodled_install_dismissed', '1');
      } catch (e) {}
    });
    await context.route('**/*', (route) => {
      const req = route.request();
      const u = new URL(req.url());
      if (u.origin !== ORIGIN) { blocked.add(u.origin); return route.abort(); }
      if (u.pathname === '/') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: html });
      if (u.pathname.startsWith('/wp-json/noodled/v1')) return handleApi(route, req, theme);
      // WordPress core i18n bundles: the template's inline shim covers wp.i18n.
      if (u.pathname.startsWith('/wp-includes/')) return route.fulfill({ contentType: 'application/javascript', body: '' });
      if (u.pathname.startsWith(PLUGIN)) {
        const rel = u.pathname.slice(PLUGIN.length).replace(/\.min\.(js|css)$/, '.$1');
        const file = path.join(ROOT, rel);
        if (!file.startsWith(ROOT) || !fs.existsSync(file)) return route.fulfill({ status: 404, body: '' });
        return route.fulfill({ contentType: MIME[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file) });
      }
      blocked.add(u.origin + u.pathname);
      return route.abort();
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push('pageerror: ' + e.message));
    page.on('console', msg => { if (msg.type() === 'error' || msg.type() === 'warning') errors.push(msg.type() + ': ' + msg.text()); });
    await page.goto(ORIGIN + '/');
    const booted = await page.waitForFunction(() => {
      const s = document.getElementById('splash');
      return s && s.classList.contains('hidden') && typeof selectNote === 'function'
        && document.querySelectorAll('.note-item').length > 0;
    }, null, { timeout: 10000 }).then(() => true, () => false);
    await page.waitForTimeout(400);
    return { context, page, errors, booted };
  }

  async function shot(page, name) {
    const file = path.join(OUT, name + '.png');
    await page.waitForTimeout(350); // let popup/slide animations finish
    await page.screenshot({ path: file });
    written.push(file);
  }
  async function closeModal(page) {
    await page.evaluate(() => { document.getElementById('modalContainer').innerHTML = ''; });
  }
  async function openShowcase(page) {
    await page.evaluate((id) => selectNote(id), SHOWCASE_ID);
    await page.waitForFunction(() => document.getElementById('noteBody') && document.getElementById('noteBody').textContent.includes('Phase one'), null, { timeout: 5000 });
  }

  const scenes = [
    { phone: false, run: async (page, t) => {
      await openShowcase(page);
      await shot(page, `desktop-note-${t}`);
      await page.keyboard.press('Control+k');
      await page.waitForSelector('#modalContainer .modal-overlay');
      await shot(page, `desktop-palette-${t}`);
      await closeModal(page);
      page.evaluate(() => { createNotebook(); });
      await page.waitForSelector('#modalContainer .modal-overlay');
      await shot(page, `desktop-popup-${t}`);
      await closeModal(page);
      await page.evaluate(() => selectNotebook('Recipes'));
      await page.waitForFunction(() => document.querySelectorAll('.note-item').length === 0);
      await shot(page, `desktop-empty-notebook-${t}`);
      await page.evaluate(() => selectNotebook(''));
      await page.fill('#searchInput', 'zebra quantum');
      await page.waitForFunction(() => document.querySelectorAll('.note-item').length === 0);
      await page.waitForTimeout(500);
      await shot(page, `desktop-search-empty-${t}`);
      await page.evaluate(() => clearSearch());
      await page.evaluate(() => { config.starred = []; return showStarred(); });
      await page.waitForFunction(() => document.querySelectorAll('.note-item').length === 0);
      await shot(page, `desktop-starred-empty-${t}`);
      // The boot-time loading skeleton, drawn on demand.
      await page.evaluate(() => showLoadingSkeleton());
      await shot(page, `desktop-loading-${t}`);
    } },
    { phone: true, run: async (page, t) => {
      await shot(page, `phone-list-${t}`);
      await openShowcase(page);
      await shot(page, `phone-note-${t}`);
      page.evaluate(() => { createNotebook(); });
      await page.waitForSelector('#modalContainer .modal-overlay');
      await shot(page, `phone-popup-${t}`);
    } },
  ];

  let bootFailed = false;
  for (const scene of scenes) {
    for (const theme of ['dark', 'light']) {
      const where = (scene.phone ? 'phone' : 'desktop') + '/' + theme;
      const { context, page, errors, booted } = await openApp({ theme, phone: scene.phone });
      if (!booted) {
        bootFailed = true;
        problems.push(where + ': app did not boot (splash still up or empty note list)');
        await page.screenshot({ path: path.join(OUT, `boot-failed-${scene.phone ? 'phone' : 'desktop'}-${theme}.png`) });
      } else {
        try { await scene.run(page, theme); } catch (e) { problems.push(where + ': ' + e.message.split('\n')[0]); }
      }
      const actual = await page.evaluate(() => document.documentElement.getAttribute('data-theme')).catch(() => '?');
      if (actual !== theme) problems.push(where + ': data-theme is "' + actual + '"');
      for (const e of new Set(errors)) problems.push(where + ': console ' + e);
      await context.close();
    }
  }
  // The PIN login page (templates/login.php): follows the OS colour scheme, not data-theme.
  const loginHtml = renderTemplate('login');
  for (const phone of [false, true]) {
    for (const scheme of ['dark', 'light']) {
      const where = 'login-' + (phone ? 'phone' : 'desktop') + '/' + scheme;
      const context = await browser.newContext({
        ...(phone ? { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true } : { viewport: { width: 1440, height: 900 } }),
        colorScheme: scheme,
      });
      await context.route('**/*', (route) => {
        const u = new URL(route.request().url());
        if (u.origin !== ORIGIN) { blocked.add(u.origin); return route.abort(); }
        if (u.pathname === '/') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: loginHtml });
        if (u.pathname.startsWith(PLUGIN)) {
          const file = path.join(ROOT, u.pathname.slice(PLUGIN.length));
          if (!file.startsWith(ROOT) || !fs.existsSync(file)) return route.fulfill({ status: 404, body: '' });
          return route.fulfill({ contentType: MIME[path.extname(file)] || 'application/octet-stream', body: fs.readFileSync(file) });
        }
        blocked.add(u.origin + u.pathname);
        return route.abort();
      });
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', e => errors.push('pageerror: ' + e.message));
      page.on('console', msg => { if (msg.type() === 'error' || msg.type() === 'warning') errors.push(msg.type() + ': ' + msg.text()); });
      try {
        await page.goto(ORIGIN + '/');
        await page.waitForSelector('.login-card');
        await page.evaluate(() => document.fonts.ready);
        await shot(page, `login-${phone ? 'phone' : 'desktop'}-${scheme}`);
      } catch (e) { problems.push(where + ': ' + e.message.split('\n')[0]); }
      for (const e of new Set(errors)) problems.push(where + ': console ' + e);
      await context.close();
    }
  }
  await browser.close();

  console.log('Files written (' + written.length + '):');
  for (const f of written) console.log('  ' + f);
  if (blocked.size) console.log('Blocked requests: ' + [...blocked].join(', '));
  if (problems.length) { console.log('Problems:'); for (const p of problems) console.log('  ' + p); }
  process.exit(bootFailed || problems.some(p => !p.includes(': console ')) ? 1 : 0);
}

main().catch(e => { console.error(e); process.exit(2); });
