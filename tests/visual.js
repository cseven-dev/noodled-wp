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
 * Exit code: 0 when every shot was taken, 1 if the app failed to boot, a shot
 * failed or a page logged a console error (warnings are only listed), 2 if the
 * run could not start.
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

// Desktop viewport; override with NOODLED_W / NOODLED_H (e.g. 5120 x 1440 for an ultrawide monitor).
const DESKTOP = { width: +process.env.NOODLED_W || 1440, height: +process.env.NOODLED_H || 900 };

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

const LONG_ID = 199;
const LONG_BODY = (() => {
  const out = ['# Field guide to the long note', '', 'A deliberately long note so wide screens can flow it into columns.'];
  for (let i = 1; i <= 14; i++) {
    out.push('', '## Section ' + i, '');
    out.push('Paragraph one of section ' + i + '. The quick brown fox jumps over the lazy dog while the planners argue about budgets, schedules, and which phase comes first. '.repeat(3));
    out.push('', 'Paragraph two of section ' + i + '. Keep the plumbing where it is, move the lavender closer to the path, and book the offsite venue before the prices rise again. '.repeat(2));
    if (i % 4 === 1) out.push('', '- first item in a list', '- second item in a list', '- third item in a list');
    if (i % 5 === 2) out.push('', '| Item | Cost |', '| --- | --- |', '| Cabinets | 4200 |', '| Counters | 3100 |', '| Tile | 900 |');
    if (i % 6 === 3) out.push('', '```js', 'function plan(phase) {', '  return phase === 1 ? "demo" : "install";', '}', '```');
  }
  return out.join('\n');
})();

const NOTEBOOKS = [
  { id: 1, name: 'General', label: 'General', count: 0, access: 'owner', owner: 1, drop: false, color: '', parent: 0, owner_name: 'Sam Rivera' },
  { id: 2, name: 'Home', label: 'Home', count: 0, access: 'owner', owner: 1, drop: false, color: '', parent: 0, owner_name: 'Sam Rivera' },
  { id: 3, name: 'Work', label: 'Work', count: 0, access: 'owner', owner: 1, drop: false, color: '', parent: 0, owner_name: 'Sam Rivera' },
  { id: 4, name: 'Recipes', label: 'Recipes', count: 0, access: 'owner', owner: 1, drop: false, color: '', parent: 0, owner_name: 'Sam Rivera' },
];

const NOTE_ROWS = [
  { id: SHOWCASE_ID, notebook: 'Home', title: 'Kitchen renovation plan', body: SHOWCASE, days: 0, pinned: true },
  { id: LONG_ID, notebook: 'Home', title: 'Field guide to the long note', body: LONG_BODY, days: 3 },
  { id: 102, notebook: 'Home', title: 'Paint colours', body: 'Living room: Hale Navy. Hallway: Revere Pewter, eggshell finish. Trim stays Chantilly Lace. #renovation #home', days: 2, image: 501 },
  { id: 103, notebook: 'Work', title: 'Q4 planning meeting', body: 'Attendees: Priya, Marco, Jen.\n\n- [x] Share the draft roadmap\n- [ ] Follow up on hiring budget\n- [ ] Book the offsite venue\n\n#work #planning', days: 1 },
  { id: 104, notebook: 'Work', title: 'Onboarding checklist for new hires', body: 'Laptop, accounts, buddy assignment, first-week reading list, and a lunch on day one.', days: 9 },
  { id: 105, notebook: 'General', title: 'Books to read', body: 'The Overstory, Piranesi, A Psalm for the Wild-Built, The Ministry for the Future. #reading', days: 16 },
  { id: 106, notebook: 'General', title: 'Gift ideas', body: 'Mom: pottery class voucher. Dad: a decent bread knife. Alex: climbing gym pass.', days: 34 },
  { id: 107, notebook: 'Work', title: 'API migration notes', body: 'The v2 endpoints return ISO dates. Keep the v1 adapter until all three clients are switched over. #work', days: 51 },
  { id: 108, notebook: 'Home', title: 'Garden layout', body: 'Tomatoes along the south fence, herbs in the raised bed, and move the lavender closer to the path. #garden #home', days: 75 },
  { id: 109, notebook: 'General', title: 'Trip to Lisbon', body: 'Flights booked for May. Want to see Alfama, the tile museum, and take the train out to Sintra. #travel', days: 110 },
  { id: 110, notebook: 'Work', title: 'Performance review prep', body: 'Wins: shipped the billing rewrite, mentored two juniors. Growth: delegate earlier.', days: 150 },
  { id: 111, notebook: 'General', title: 'Running log', body: 'Week 12: 5k in 27:40, long run 14k. Knee feels fine after the new shoes. #health', days: 196 },
  { id: 112, notebook: 'Home', title: 'Car maintenance', body: 'Oil change at 82,000 km. Winter tires go on in November, rotate in spring.', days: 240 },
  { id: 113, notebook: 'General', title: 'Podcast episode ideas', body: 'Interview a beekeeper, a history of the paperclip, and why trains run late.', days: 300 },
  { id: 114, notebook: 'Work', title: 'Conference talk outline', body: 'Opening story, three lessons from the outage, live demo, questions.', days: 352 },
];
const STARRED = [103];
// Attachment-text hits (GET /search/files shape) for the PDF search shots.
const PDF_HITS = [
  { note_id: 112, attachment_id: 601, filename: 'tire-warranty.pdf', snippet: 'Keep the receipt and the rotation log in the glovebox. Coverage: the tread warranty runs 80,000 km or six years, whichever comes first, provided rotation records are kept.' },
  { note_id: 107, attachment_id: 603, filename: 'api-spec-v2-final-reviewed.pdf', snippet: 'Section 4.2 warranty of compatibility: v1 clients keep working until the adapter is retired.' },
];
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
// Same rule as Noodled_Notes::tags_of (locked bodies and fenced code have none).
function tagsOf(body) {
  if (body.startsWith('noodled:enc:v1:')) return [];
  const m = body.replace(/```[\s\S]*?```/g, ' ').match(/(?:^|\s)#([A-Za-z][\w-]*)/g) || [];
  return [...new Set(m.map(t => t.trim().slice(1)))];
}
function formatNote(r, withBody = true) {
  const out = {
    id: r.id, slug: 'n' + r.id, notebook: r.notebook, title: r.title, source: '', pinned: !!r.pinned,
    created: r.created_at.slice(0, 16), modified: r.modified_at.slice(0, 16), mtime: r.modified_at,
    att: r.image ? 1 : 0, thumb: r.image || 0, preview: r.body.split('\n').map(l => l.replace(/^\s*(#+|>|[-*+](\s+\[[ xX]\])?|\d+\.|\|)\s*/, '')).join(' ').replace(/[*`|]/g, '').replace(/\s+/g, ' ').trim().slice(0, 160), tasks: taskCounts(r.body),
    tags: tagsOf(r.body),
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
    // Cross-notebook title index (Noodled_Notes::titles shape, no bodies).
    if (p === '/titles') return json(NOTE_ROWS.map(r => ({ id: r.id, title: r.title, notebook: r.notebook, modified: r.modified_at.slice(0, 16), pinned: !!r.pinned, tags: tagsOf(r.body) })));
    if (p === '/backlinks') {
      const t = ('[[' + (u.searchParams.get('title') || '') + ']]').toLowerCase();
      return json(NOTE_ROWS.filter(r => r.body.toLowerCase().includes(t)).map(r => formatNote(r, false)));
    }
    // PDF search: one note that matches only inside its PDF, one that matches both ways.
    if (p === '/search/files') {
      const q = (u.searchParams.get('q') || '').toLowerCase();
      return json(PDF_HITS.filter(h => h.snippet.toLowerCase().includes(q)));
    }
    if (p === '/attachments/unindexed') return json([{ id: 601, note_id: 112, filename: 'tire-warranty.pdf' }, { id: 602, note_id: 104, filename: 'handbook.pdf' }, { id: 603, note_id: 107, filename: 'api-spec.pdf' }]);
    if (p === '/search') {
      const q = (u.searchParams.get('q') || '').toLowerCase();
      return json(NOTE_ROWS.filter(r => (r.title + ' ' + r.body).toLowerCase().includes(q)).map(r => formatNote(r, false)));
    }
    return json([]);
  }
  // A save echoes the stored note (unchanged), like update_note does.
  if (method === 'PUT' && (m = p.match(/^\/notes\/(\d+)$/))) {
    const r = NOTE_ROWS.find(x => x.id === +m[1]);
    if (r) return json(formatNote(r));
  }
  // Canned AI replies (no provider is ever called).
  if (p === '/ai/summarize') {
    const md = '## Summary\nThe kitchen is being redone in two phases to stay on budget, keeping the existing plumbing.\n\n## Action items\n- [ ] Book the permit inspection\n- [ ] Get a second quote for the countertop\n- [ ] Ask about the vent hood clearance\n';
    return json({ markdown: md, text: md });
  }
  if (p === '/ai/ask') {
    return json({ text: 'You chose **Hale Navy** for the living room and Revere Pewter for the hallway, with Chantilly Lace trim. The cabinet colour is already picked for the kitchen.', sources: [{ id: 102, title: 'Paint colours' }, { id: SHOWCASE_ID, title: 'Kitchen renovation plan' }] });
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
      : { viewport: DESKTOP });
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
      // A typed query searches notes in every notebook, then notebooks, then commands.
      await page.evaluate(() => selectNotebook('Work'));
      await page.keyboard.press('Control+k');
      await page.waitForSelector('#cmdpInput');
      await page.fill('#cmdpInput', 'pla');
      await shot(page, `desktop-palette-query-${t}`);
      await closeModal(page);
      await page.evaluate(() => selectNotebook(null));
      // "Linked from" section in the editor footer (Paint colours is linked from the showcase).
      await page.evaluate(() => selectNote(102));
      await page.waitForSelector('#editorFooter .backlinks');
      await shot(page, `desktop-backlinks-${t}`);
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
      // Search filters popover, then a picked tag filtering the list.
      await page.evaluate(() => selectNotebook(null));
      await page.click('#searchFilterBtn');
      await page.waitForSelector('#searchFilterMenu.show');
      await shot(page, `desktop-search-filters-${t}`);
      await page.click('#searchFilterMenu .sf-tag[data-value="home"]');
      await page.waitForFunction(() => document.getElementById('searchInput').value === 'tag:home');
      await shot(page, `desktop-search-tag-${t}`);
      await page.evaluate(() => clearSearch());
      // Tag cloud from the sidebar "Tags" item.
      await page.click('#nbTags');
      await page.waitForSelector('#modalContainer .tag-chip');
      await shot(page, `desktop-tag-cloud-${t}`);
      await closeModal(page);
      // AI: the summary popup with "Add to top of note", and Ask with sources.
      await openShowcase(page);
      page.evaluate(() => { summarizeNote(); });
      await page.waitForSelector('#aiAddTop');
      await shot(page, `desktop-ai-summary-${t}`);
      await closeModal(page);
      await page.evaluate(() => askYourNotes());
      await page.fill('#aiAskInput', 'What paint colours did we pick?');
      await page.evaluate(() => runAsk());
      await page.waitForSelector('#aiAskAnswer .ai-sources');
      await shot(page, `desktop-ai-ask-${t}`);
      await closeModal(page);
      // The boot-time loading skeleton, drawn on demand.
      // PDF search: a hit row that matches only inside a PDF (Car maintenance).
      await page.evaluate(() => selectNotebook(null));
      await page.fill('#searchInput', 'warranty');
      await page.waitForSelector('.note-item .file-hit');
      await shot(page, `desktop-pdf-search-${t}`);
      await page.evaluate(() => clearSearch());
      // The menu item, then the backfill popup mid-run and finished (extraction faked:
      // the CDN is blocked here, and the real extraction is checked separately).
      await page.evaluate(() => openMenuDashboard());
      await page.waitForSelector('.md-panel');
      await page.evaluate(() => { const b = [...document.querySelectorAll('.md-card')].find(x => x.textContent.includes('Make PDFs searchable')); if (b) b.scrollIntoView({ block: 'center' }); });
      await shot(page, `desktop-menu-pdf-${t}`);
      await page.evaluate(() => closeMenuDashboard());
      await page.evaluate(() => { window.extractPdfText = () => new Promise(r => setTimeout(() => r('text'), 700)); window.fetch = ((orig) => (url, o) => String(url).includes('/file/') ? Promise.resolve(new Response(new Blob(['%PDF']))) : orig(url, o))(window.fetch); });
      page.evaluate(() => { makePdfsSearchable(); });
      await page.waitForFunction(() => /2 of 3/.test((document.getElementById('pdfBfStatus') || {}).textContent || ''));
      await shot(page, `desktop-pdf-backfill-${t}`);
      await page.waitForFunction(() => /made searchable/.test((document.getElementById('pdfBfStatus') || {}).textContent || ''), null, { timeout: 5000 });
      await shot(page, `desktop-pdf-backfill-done-${t}`);
      await closeModal(page);
      await page.evaluate(() => showLoadingSkeleton());
      await shot(page, `desktop-loading-${t}`);
    } },
    { phone: false, run: async (page, t) => {
      // Wide screens: the list flows into columns and a long note flows into side-by-side columns.
      await page.evaluate((id) => selectNote(id), LONG_ID);
      await page.waitForFunction(() => document.getElementById('noteBody') && document.getElementById('noteBody').textContent.includes('Section 14'), null, { timeout: 5000 });
      await page.waitForTimeout(700); // ResizeObserver debounce
      const m = await page.evaluate(() => {
        const nb = document.getElementById('noteBody'), body = nb.closest('.content-body');
        const cols = (el) => { const v = getComputedStyle(el).columnCount; return v === 'auto' ? 1 : +v; };
        const tc = getComputedStyle(document.getElementById('noteList')).gridTemplateColumns;
        const before = body.scrollHeight;
        body.classList.remove('multicol');
        const single = body.scrollHeight;
        body.classList.add('multicol');
        return { w: innerWidth, listCols: tc === 'none' ? 1 : tc.split(' ').length, noteCols: cols(nb), scrollH: before, singleH: single };
      });
      const wantList = m.w >= 3800 ? 4 : m.w >= 2800 ? 3 : m.w >= 1900 ? 2 : 1;
      if (m.listCols !== wantList) problems.push(`long-note ${t}: list columns ${m.listCols}, expected ${wantList} at ${m.w}`);
      const multi = m.w >= 2300 ? m.noteCols > 1 : m.noteCols === 1;
      if (!multi) problems.push(`long-note ${t}: note column-count ${m.noteCols} at ${m.w}`);
      if (m.w >= 2300 && !(m.scrollH < m.singleH)) problems.push(`long-note ${t}: scrollHeight ${m.scrollH} not below single-column ${m.singleH}`);
      await shot(page, `desktop-long-note-${t}`);
      await openShowcase(page);
      await page.waitForTimeout(700);
      const short = await page.evaluate(() => { const v = getComputedStyle(document.getElementById('noteBody')).columnCount; return v === 'auto' ? 1 : +v; });
      if (short !== 1) problems.push(`long-note ${t}: short note has column-count ${short}`);
    } },
    { phone: true, run: async (page, t) => {
      await shot(page, `phone-list-${t}`);
      await openShowcase(page);
      await shot(page, `phone-note-${t}`);
      page.evaluate(() => { createNotebook(); });
      await page.waitForSelector('#modalContainer .modal-overlay');
      await shot(page, `phone-popup-${t}`);
      await closeModal(page);
      await page.evaluate(() => showCommandPalette());
      await page.waitForSelector('#cmdpInput');
      await page.fill('#cmdpInput', 'pla');
      await shot(page, `phone-palette-query-${t}`);
      await closeModal(page);
      // Back to the list, then the search filters popover and the tag cloud.
      await page.evaluate(() => { document.querySelector('.col-content')?.classList.remove('open'); });
      await page.click('#searchFilterBtn');
      await page.waitForSelector('#searchFilterMenu.show');
      await shot(page, `phone-search-filters-${t}`);
      await page.evaluate(() => { closeSearchFilters(); return showTagCloud(); });
      await page.waitForSelector('#modalContainer .tag-chip');
      await shot(page, `phone-tag-cloud-${t}`);
      await closeModal(page);
      await openShowcase(page);
      page.evaluate(() => { summarizeNote(); });
      await page.waitForSelector('#aiAddTop');
      await shot(page, `phone-ai-summary-${t}`);
      await closeModal(page);
      await page.evaluate(() => askYourNotes());
      await page.fill('#aiAskInput', 'What paint colours did we pick?');
      await page.evaluate(() => runAsk());
      await page.waitForSelector('#aiAskAnswer .ai-sources');
      await shot(page, `phone-ai-ask-${t}`);
      await closeModal(page);
      // PDF search hit row and the backfill popup on a phone.
      await page.evaluate(() => { document.querySelector('.col-content')?.classList.remove('open'); });
      await page.fill('#searchInput', 'warranty');
      await page.waitForSelector('.note-item .file-hit');
      await shot(page, `phone-pdf-search-${t}`);
      await page.evaluate(() => clearSearch());
      await page.evaluate(() => { window.extractPdfText = () => new Promise(r => setTimeout(() => r('text'), 700)); window.fetch = ((orig) => (url, o) => String(url).includes('/file/') ? Promise.resolve(new Response(new Blob(['%PDF']))) : orig(url, o))(window.fetch); });
      page.evaluate(() => { makePdfsSearchable(); });
      await page.waitForFunction(() => /2 of 3/.test((document.getElementById('pdfBfStatus') || {}).textContent || ''));
      await shot(page, `phone-pdf-backfill-${t}`);
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
        ...(phone ? { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true } : { viewport: DESKTOP }),
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
  // Console warnings are reported only; a console error or uncaught page error fails the run.
  process.exit(bootFailed || problems.some(p => !p.includes(': console warning: ')) ? 1 : 0);
}

main().catch(e => { console.error(e); process.exit(2); });
