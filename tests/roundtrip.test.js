/*
 * Markdown round-trip tests for assets/js/noodled.js.
 *
 * Run from the repo root:   node tests/roundtrip.test.js
 * (set NOODLED_VERBOSE=1 to also print expected/actual for KNOWN cases)
 *
 * For each case the REAL app code (assets/js/noodled.js, unmodified) is loaded in
 * headless Chromium via tests/harness.html, then the same path a real
 * open-then-save takes is driven: set activeNote, call renderContent() (which
 * renders the body into #noteBody, the code textarea, or the lock panel, then
 * runs resolveTransclusions/renderRichBlocks), then currentBody() (what doSave()
 * persists). A null from currentBody() means doSave() skips the save, so the
 * stored body is unchanged. Each case is checked twice: original == first round
 * trip, and first round trip == second round trip (idempotence).
 * A case is [name, markdown] or [name, markdown, opts]: opts.expect is the exact
 * first-round-trip output when it is an accepted normalization of the input, and
 * opts.map is set as noodledConfig.map (location-note map provider) for the case.
 *
 * Dependency: Playwright is NOT installed in this repo. It is loaded from
 *   C:\Users\simon\.claude\skills\gauntlet\node_modules
 * Override with the env var NOODLED_PLAYWRIGHT_DIR (a node_modules folder that
 * contains "playwright" or "playwright-core"). If Playwright's bundled Chromium is
 * missing, the system Chrome, then Edge, is used.
 *
 * No network: every request is served by a route handler on a fake origin; the
 * API gets empty JSON, everything else (mermaid/katex CDN, images, iframes) is
 * aborted.
 *
 * Exit code: 0 when every case not listed in KNOWN_FAILURES passes, 1 otherwise.
 */

// Cases that fail today because of a real bug in noodled.js. Remove an entry once
// the bug is fixed (the run prints a notice when a listed case starts passing).
const KNOWN_FAILURES = {
};

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

// Notes that exist in the fake account, so [[Note Title]] and ![[Note Title]]
// resolve to a real note (the other wiki link in the cases is deliberately broken).
const OTHER_NOTES = [
  { id: 901, title: 'Note Title', body: 'Embedded **body** text.', notebook: 'General', preview: 'Embedded body text.', modified: '' },
];

const MERMAID = '```mermaid\ngraph TD\n  A[Start] --> B{"x < 1 & y > 2"}\n  B -->|yes| C\n\n  %% comment *not italic*\n```';
const MATH = '```math\n\\frac{a_1}{b_2} < c & d \\\\\n  \\sum_{i=1}^{n} x_i^2\n```';
const CODE = '```js\nfunction f(a, b) {\n  // compare <a> & "b"\n  if (a < b) {\n\n    return \'*not* _md_\';\n  }\n\treturn null;\n}\n```';

const CASES = [
  ['headings with inline formatting', '# Title with **bold**\n## Sub with *italic*\n### Third with [a link](https://example.com/page)'],
  // Accepted normalization: the blank line right after a heading is dropped.
  ['heading followed by blank line', '# Title\n\nFirst paragraph.\n\n## Section\n\nSecond paragraph.', { expect: '# Title\nFirst paragraph.\n\n## Section\nSecond paragraph.' }],
  ['paragraphs with inline formatting', 'Plain **bold** and *italic* and `inline code` here.\n\nA [link](https://example.com/a?b=1&c=2) and ***bold italic*** too.'],
  ['strikethrough', 'Some ~~struck out~~ words.'],
  ['bullet list with bold and links', '- plain item\n- item with **bold** text\n- item with [a link](https://example.com)\n- *italic* and `code`'],
  ['nested bullets 2 levels', '- parent one\n  - child a\n  - child b\n- parent two'],
  ['nested bullets 3 levels', '- level 1\n  - level 2\n    - level 3 with **bold**\n  - level 2 again\n- level 1 again'],
  ['ordered list', '1. first\n2. second with **bold**\n3. third with [link](https://example.com)'],
  ['ordered list starting at 3', '3. third\n4. fourth'],
  ['ordered list nested in bullets', '- shopping\n  1. eggs\n  2. milk\n- errands'],
  ['checklist', '- [ ] todo item\n- [x] done item\n- [ ] read [the docs](https://example.com/docs)\n- [x] **bold** done'],
  ['nested checklist', '- [ ] parent open\n  - [x] child done\n  - [ ] child open\n- [x] parent done\n  - [ ] child of done'],
  ['nested bullets with bold and link', '- top\n  - child with **bold** and [a link](https://example.com/x)\n  - plain child'],
  // Accepted normalization: a tab-indented child is written back with the 2-space indent.
  ['tab-indented child', '- parent\n\t- child\n- next', { expect: '- parent\n  - child\n- next' }],
  // Accepted normalization: a 4-space indent is one level, written back as 2 spaces.
  ['4-space-indented children', '- parent\n    - child\n        - grandchild', { expect: '- parent\n  - child\n    - grandchild' }],
  ['list followed by paragraph', '- item one\n  - nested\nA paragraph after the list.'],
  ['ordered list starting at 1', '1. one\n2. two'],
  ['checklist nested in ordered list', '1. step\n  - [ ] sub task\n  - [x] sub done\n2. next step'],
  ['back out two levels then a type change', '- a\n  - b\n    - c\n1. d'],
  ['two bullet lists separated by blank line', '- a\n- b\n\n- c\n- d'],
  ['two ordered lists separated by blank line', '1. a\n2. b\n\n1. c\n2. d'],
  ['two checklists separated by blank line', '- [ ] a\n- [x] b\n\n- [ ] c\n- [x] d'],
  ['blank line between bullet list and checklist', '- a\n\n- [ ] b', { expect: '- a\n- [ ] b' }],
  ['year at line start is a paragraph', '2024. A big year.\n\nNext paragraph.'],
  ['two year paragraphs', '2024. One\n\n2025. Two'],
  ['three digit ordered list', '100. a\n101. b'],
  ['asterisk bullets with nesting', '* one\n* two\n  * nested'],
  ['plus bullets', '+ one\n+ two'],
  ['asterisk checklist', '* [ ] a\n* [x] b'],
  ['spaced asterisks are a divider', '* * *', { expect: '---' }],
  ['blockquote with inline formatting', '> A quote with **bold**, *italic* and a [link](https://example.com).'],
  ['multi-line blockquote', '> line one of the quote\n> line two of the quote'],
  ['two blockquotes separated by blank line', '> first quote\n\n> second quote'],
  ['fenced code block with language', 'Before the code.\n' + CODE + '\nAfter the code.'],
  ['fenced code block without language', 'Text\n```\nplain   spaced\n  indented\n```\nMore text'],
  ['code note', '```python\ndef f(x):\n    if x < 1 and x > -1:\n\n        return "a & b"\n    return None\n```'],
  ['table with alignment', '| Left | Centre | Right |\n| :-- | :-: | --: |\n| a | **b** | [c](https://example.com) |\n| d | `e` | f |'],
  ['table without alignment', '| A | B |\n| --- | --- |\n| 1 | 2 |'],
  // Accepted normalization: the separator is rewritten in short form (alignment kept).
  ['table with long GFM separator', '| Left | Centre | Right |\n|:-----|:------:|------:|\n| a | b | c |', { expect: '| Left | Centre | Right |\n| :-- | :-: | --: |\n| a | b | c |' }],
  ['callout', '> [!warning] Careful now\n> Body with **bold** and a [link](https://example.com)\n> second body line'],
  ['collapsible callout', '> [!note]- Click to expand\n> Hidden body text'],
  ['callout followed by paragraph', '> [!tip]\n> Short tip\n\nNormal paragraph after.'],
  ['mermaid block', 'Diagram:\n' + MERMAID + '\nEnd.'],
  ['math block', 'Formula:\n' + MATH + '\nEnd.'],
  ['latex block', 'Formula:\n```latex\nE = mc^2 & \\alpha < \\beta\n```\nEnd.'],
  ['wiki links', 'See [[Note Title]] and also [[Missing Note]] for details.'],
  ['transclusion', 'Before\n![[Note Title]]\nAfter'],
  ['image reference', 'Inline ![a diagram](https://example.com/files/diagram.png) here.\n![photo](/wp-json/noodled/v1/attachment/5/photo.jpg)'],
  ['horizontal rule', 'Above\n\n---\n\nBelow'],
  ['locked note sentinel', 'noodled:enc:v1:eyJpdiI6IkFBQUEiLCJjdCI6IkJCQkIqKl9fPD4mIn0='],
  ['special characters', 'Tom & Jerry <tag> "double" \'single\' 2 < 3 > 1\nsnake_case_name and file_name_here.txt\na * b and 5*3 and 2 * 3 * 4\n__dunder__ and **unbalanced bold'],
  ['tags and timestamps', 'Meeting #project-x notes\n[12:30] Kickoff\n[1:02:03] Wrap up #done'],
  ['bare URL', 'Visit https://example.com/path today.'],
  ['link text differs from href', 'A [named link](https://example.com/page) and [https://a.example](https://b.example).'],
  ['YouTube URL mid-sentence', 'See https://www.youtube.com/watch?v=dQw4w9WgXcQ for the clip.'],
  ['standalone Vimeo URL', 'Watch:\nhttps://vimeo.com/76979871\nDone'],
  ['standalone YouTube URL', 'Watch this:\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\nThanks'],
  ['location note (OpenStreetMap link)', '\u{1F4CD} 43.6532, -79.3832\n\nhttps://www.openstreetmap.org/?mlat=43.6532&mlon=-79.3832#map=16/43.6532/-79.3832'],
  ['location note (Mapbox provider)', '\u{1F4CD} 43.6532, -79.3832\n\nhttps://www.openstreetmap.org/?mlat=43.6532&mlon=-79.3832#map=16/43.6532/-79.3832', { map: { provider: 'mapbox', mapboxToken: 'pk.test' } }],
  ['location note (Google provider)', '\u{1F4CD} 43.6532, -79.3832\n\nhttps://www.openstreetmap.org/?mlat=43.6532&mlon=-79.3832#map=16/43.6532/-79.3832', { map: { provider: 'gmaps', gmapsKey: 'test-key' } }],
  ['heading level 4 stays literal', '#### Not a rendered heading'],
  ['multiple blank lines', 'one\n\n\ntwo'],
  ['long mixed document', [
    '# Project **Alpha**',
    'Intro paragraph with *italic*, `code` and a [link](https://example.com).',
    '## Tasks',
    '- [x] write spec',
    '- [ ] review [PR](https://example.com/pr/1)',
    '## Notes',
    '- point one with **bold**',
    '- point two',
    '1. step one',
    '2. step two',
    '> quoted *thought*',
    '> [!warning] Heads up',
    '> deadline is Friday',
    '| Item | Qty |',
    '| :-- | --: |',
    '| apples | 3 |',
    'Code:',
    '```bash',
    'echo "a & b" > out.txt',
    '',
    '  ls -la',
    '```',
    MERMAID,
    'See [[Note Title]] and #alpha.',
    '![[Note Title]]',
    '![chart](https://example.com/chart.png)',
    '---',
    'The end & goodbye <3',
  ].join('\n')],
];

function normalize(s) {
  return String(s).split('\n').map(l => l.replace(/\s+$/, '')).join('\n').replace(/\n+$/, '');
}

async function launch(chromium) {
  const tries = [{}, { channel: 'chrome' }, { channel: 'msedge' }];
  let lastErr;
  for (const opt of tries) {
    try { return await chromium.launch({ headless: true, ...opt }); } catch (e) { lastErr = e; }
  }
  throw lastErr;
}

function block(label, text) {
  return '      ' + label + ':\n' + text.split('\n').map(l => '        | ' + l).join('\n');
}

async function main() {
  const { chromium } = loadPlaywright();
  const browser = await launch(chromium);
  const context = await browser.newContext({ serviceWorkers: 'block' });
  const blocked = [];
  await context.route('**/*', route => {
    const req = route.request();
    const url = req.url();
    if (url === ORIGIN + '/harness.html') return route.fulfill({ contentType: 'text/html', body: fs.readFileSync(path.join(__dirname, 'harness.html')) });
    if (url === ORIGIN + '/noodled.js') return route.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(path.join(ROOT, 'assets', 'js', 'noodled.js')) });
    if (url.startsWith(ORIGIN + '/wp-json/') && req.resourceType() === 'fetch') return route.fulfill({ contentType: 'application/json', body: '[]' });
    blocked.push(url);
    return route.abort();
  });
  const page = await context.newPage();
  const pageErrors = [];
  page.on('pageerror', e => pageErrors.push(e.message));

  await page.goto(ORIGIN + '/harness.html');
  await page.waitForFunction(() => typeof renderMarkdown === 'function' && typeof htmlToMarkdown === 'function'
    && typeof renderContent === 'function' && typeof currentBody === 'function');
  // Let the app's boot (DOMContentLoaded handler) finish against the stubbed API
  // so it cannot overwrite activeNote/notes mid-case.
  const booted = await page.waitForFunction(() => document.getElementById('splash').classList.contains('hidden'), null, { timeout: 10000 })
    .then(() => true, () => false);
  await page.waitForTimeout(300);

  async function roundTrip(md, map) {
    return page.evaluate(async ({ md, others, map }) => {
      noodledConfig.map = map;
      showRawMarkdown = false;
      notes = others.map(n => ({ ...n }));
      activeNote = { id: 900, title: 'Round trip', body: md, notebook: 'General', modified: '', attachments: [], tags: [] };
      renderContent();
      await new Promise(r => setTimeout(r, 30));
      const body = currentBody();
      return body === null ? { body: md, skipped: true } : { body, skipped: false };
    }, { md, others: OTHER_NOTES, map: map || {} });
  }

  let failed = 0, known = 0, passed = 0;
  const nowPassing = [];
  const names = new Set(CASES.map(c => c[0]));
  for (const k of Object.keys(KNOWN_FAILURES)) if (!names.has(k)) console.log('WARNING: KNOWN_FAILURES entry "' + k + '" matches no case');

  for (const [name, md, opts = {}] of CASES) {
    let first, second, err = null;
    try {
      first = await roundTrip(md, opts.map);
      second = await roundTrip(first.body, opts.map);
    } catch (e) { err = e.message.split('\n')[0]; }
    const problems = [];
    if (err) problems.push('threw: ' + err);
    else {
      const a = normalize(opts.expect !== undefined ? opts.expect : md), b = normalize(first.body), c = normalize(second.body);
      if (b !== a) problems.push(['round trip changed the markdown', a, b]);
      if (c !== b) problems.push(['second round trip differs from first (not idempotent)', b, c]);
    }
    const isKnown = Object.prototype.hasOwnProperty.call(KNOWN_FAILURES, name);
    const note = (!err && first.skipped) ? ' (no editor mounted, save skipped, body untouched)' : '';
    if (!problems.length) {
      passed++;
      if (isKnown) { nowPassing.push(name); console.log('PASS  ' + name + note + '  <-- in KNOWN_FAILURES but passes now: remove it from the list'); }
      else console.log('PASS  ' + name + note);
      continue;
    }
    if (isKnown) { known++; console.log('KNOWN ' + name + '  (' + KNOWN_FAILURES[name] + ')'); }
    else { failed++; console.log('FAIL  ' + name); }
    if (!isKnown || process.env.NOODLED_VERBOSE) {
      for (const p of problems) {
        if (typeof p === 'string') { console.log('      ' + p); continue; }
        console.log('      ' + p[0]);
        console.log(block('expected', p[1]));
        console.log(block('actual', p[2]));
      }
    }
  }

  const harnessErrors = await page.evaluate(() => window.__pageErrors.slice());
  await browser.close();

  console.log('');
  console.log(`${passed} passed, ${known} known failures, ${failed} failed (${CASES.length} cases)`);
  if (!booted) console.log('Note: app boot did not hide the splash within 10s (cases still ran).');
  const hosts = [...new Set(blocked.map(u => u.split('?')[0]))];
  console.log(`Network requests blocked: ${blocked.length}${hosts.length ? ' (' + hosts.join(', ') + ')' : ''}`);
  const errs = [...new Set([...harnessErrors, ...pageErrors])];
  if (errs.length) console.log('Page errors seen (informational): ' + errs.join(' | '));
  if (nowPassing.length) console.log('Remove from KNOWN_FAILURES (now passing): ' + nowPassing.join(', '));
  process.exit(failed ? 1 : 0);
}

main().catch(e => { console.error(e); process.exit(2); });
