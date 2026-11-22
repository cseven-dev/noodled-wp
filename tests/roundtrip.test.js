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
  // renderMarkdown post-pass (L3017) deletes the <br> after </h1-3>, so the blank line after every heading is lost.
  'heading followed by blank line': 'blank line after a heading is dropped',
  // renderMarkdown matches bullets on the trimmed line (L2955) and never opens a nested <ul>, so indentation is lost.
  'nested bullets 2 levels': 'child items flattened to top level',
  'nested bullets 3 levels': 'level 2 and 3 items flattened to top level',
  // renderMarkdown emits <ol> with no start attr (L2957-L2958) and listToMd numbers from 1 (L2499).
  'ordered list starting at 3': 'list renumbered from 1 (3., 4. become 1., 2.)',
  // Same flattening as nested bullets: the indented 1./2. become a sibling top-level <ol> (L2957).
  'ordered list nested in bullets': 'nested ordered list flattened to top level',
  // renderMarkdown merges adjacent blockquotes with <br> (L3016); htmlToMarkdown writes one "> " prefix (L2475) and BR as a bare newline (L2532).
  'multi-line blockquote': 'second and later quote lines lose their "> " prefix (become a plain paragraph line)',
  // tableToMd always writes the short canonical marker (L2512). Cosmetic: alignment is kept, separator text changes.
  'table with long GFM separator': 'separator |:-----|:------:|------:| rewritten as | :-- | :-: | --: |',
  // inlineFormat autolinks bare URLs (L2919) and inlineHtmlToMd always writes [text](href) (L2529).
  'bare URL': 'bare URL rewritten as [url](url)',
  // embedMedia (L4903) replaces the URL line with an iframe; htmlToMarkdown has no branch for .embed-container (falls through at L2481), so the line is deleted.
  'standalone YouTube URL': 'the YouTube URL line is deleted on save',
  // Same as YouTube: mapEmbedHtml (L4923) iframe is unknown to htmlToMarkdown; only the "Directions" button text survives.
  'location note (OpenStreetMap link)': 'OSM link replaced by the map button label text',
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
  ['heading followed by blank line', '# Title\n\nFirst paragraph.\n\n## Section\n\nSecond paragraph.'],
  ['paragraphs with inline formatting', 'Plain **bold** and *italic* and `inline code` here.\n\nA [link](https://example.com/a?b=1&c=2) and ***bold italic*** too.'],
  ['strikethrough', 'Some ~~struck out~~ words.'],
  ['bullet list with bold and links', '- plain item\n- item with **bold** text\n- item with [a link](https://example.com)\n- *italic* and `code`'],
  ['nested bullets 2 levels', '- parent one\n  - child a\n  - child b\n- parent two'],
  ['nested bullets 3 levels', '- level 1\n  - level 2\n    - level 3 with **bold**\n  - level 2 again\n- level 1 again'],
  ['ordered list', '1. first\n2. second with **bold**\n3. third with [link](https://example.com)'],
  ['ordered list starting at 3', '3. third\n4. fourth'],
  ['ordered list nested in bullets', '- shopping\n  1. eggs\n  2. milk\n- errands'],
  ['checklist', '- [ ] todo item\n- [x] done item\n- [ ] read [the docs](https://example.com/docs)\n- [x] **bold** done'],
  ['blockquote with inline formatting', '> A quote with **bold**, *italic* and a [link](https://example.com).'],
  ['multi-line blockquote', '> line one of the quote\n> line two of the quote'],
  ['fenced code block with language', 'Before the code.\n' + CODE + '\nAfter the code.'],
  ['fenced code block without language', 'Text\n```\nplain   spaced\n  indented\n```\nMore text'],
  ['code note', '```python\ndef f(x):\n    if x < 1 and x > -1:\n\n        return "a & b"\n    return None\n```'],
  ['table with alignment', '| Left | Centre | Right |\n| :-- | :-: | --: |\n| a | **b** | [c](https://example.com) |\n| d | `e` | f |'],
  ['table without alignment', '| A | B |\n| --- | --- |\n| 1 | 2 |'],
  ['table with long GFM separator', '| Left | Centre | Right |\n|:-----|:------:|------:|\n| a | b | c |'],
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
  ['standalone YouTube URL', 'Watch this:\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\nThanks'],
  ['location note (OpenStreetMap link)', '\u{1F4CD} 43.6532, -79.3832\n\nhttps://www.openstreetmap.org/?mlat=43.6532&mlon=-79.3832#map=16/43.6532/-79.3832'],
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

  async function roundTrip(md) {
    return page.evaluate(async ({ md, others }) => {
      showRawMarkdown = false;
      notes = others.map(n => ({ ...n }));
      activeNote = { id: 900, title: 'Round trip', body: md, notebook: 'General', modified: '', attachments: [], tags: [] };
      renderContent();
      await new Promise(r => setTimeout(r, 30));
      const body = currentBody();
      return body === null ? { body: md, skipped: true } : { body, skipped: false };
    }, { md, others: OTHER_NOTES });
  }

  let failed = 0, known = 0, passed = 0;
  const nowPassing = [];
  const names = new Set(CASES.map(c => c[0]));
  for (const k of Object.keys(KNOWN_FAILURES)) if (!names.has(k)) console.log('WARNING: KNOWN_FAILURES entry "' + k + '" matches no case');

  for (const [name, md] of CASES) {
    let first, second, err = null;
    try {
      first = await roundTrip(md);
      second = await roundTrip(first.body);
    } catch (e) { err = e.message.split('\n')[0]; }
    const problems = [];
    if (err) problems.push('threw: ' + err);
    else {
      const a = normalize(md), b = normalize(first.body), c = normalize(second.body);
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
