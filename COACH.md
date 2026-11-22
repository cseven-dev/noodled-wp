# Coach Report — Noodled for WordPress v1.1.154
_Last coached: 2026-06-19_

A senior-dev, whole-product read of the `noodled-wp` plugin: what it does, how it
looks, and the five pillars. This run is backed by **fresh** audits (all ran
2026-06-19): a full `/5p` scorecard (Speed/Security/Accessibility/Language/SEO),
a refreshed `/gap` competitive analysis, and a `/pp` note-taking pain-point scan.

## Scorecard
| Dimension | Grade | Score | Source/date |
|---|---|---|---|
| Capability | A | 96/100 | coach 2026-06-19 (AI shipped) |
| Aesthetics/UX | A- | 91/100 | coach 2026-06-19 (code-only; no live pass) |
| Speed | B | 88/100 | /so 2026-06-19 (post-fix r2) |
| Security | B | 85/100 | /sa 2026-06-19 (post-fix) |
| Accessibility | A | 95/100 | /ac 2026-06-19 (post-fix) |
| Language | A | 91/100 | /lr 2026-06-19 |
| SEO | A | 93/100 | /5p 2026-06-19 (post-fix) |
| **Overall** | **A-** | **91/100** | coach 2026-06-19 (post-fix r2) |

**Holistic read:** a genuinely impressive, feature-complete product — a real
Evernote replacement built with senior-level care (server-built note lists,
de-N+1 JOINs, a real offline queue + conflict UX, passkeys, push, fully
internationalized SPA). Capability and craft are A-tier, and three pillars now
score A (Accessibility 95, SEO 93, Language 91). This run measured the previously
*estimated* pillars: the first pass surfaced a **High-severity unauthenticated
GitHub-webhook bypass** (forged push could overwrite/delete any note when
`webhook_secret` was unset), which **dropped the overall to B+ until it was closed
the same session** (the handler now fails closed). With that hole shut, the
overall is **A- (91)** after a full same-day remediation pass: the two Medium
security items (share-link expiry/revocation/exact-match; a site-wide PIN-verify
failure cap) and the render-blocking app CSS are all now closed, lifting Security
to B (85) and Speed to B (84) — every pillar is B or A. What keeps it from a clean
A is no longer a security hole but **capability + incrementals**: the AI
ask-your-notes table-stakes gap, PWA-only mobile, plus minor items (GitHub PAT
stored plaintext, the Fraunces font still render-blocking, gtag on the private
app, and the pending `.env` rotation). None are crises — they're the edge between
very good and world-class.

> ⚠️ **Out-of-band, do this regardless:** the local `.env` holds a live FTP
> password (noodled.ca) and a live Plaud token. They were read during the security
> audit — **rotate both.** Not web-exposed (package.ps1 excludes `.env` from the
> zip), but treat them as compromised.

## Recommendations
### 🚀 Quick wins
- [x] **Close the GitHub webhook auth bypass** — _done 2026-06-19: `handle_webhook()` now fails closed — a missing `webhook_secret` returns 403 instead of skipping the HMAC check, so a forged push can no longer write to any notebook unauthenticated. (Security, S)_ → `class-noodled-sync.php`
- [ ] **Rotate the `.env` FTP + Plaud secrets** — _guided 2026-06-19, **awaiting your action** (only you can rotate live creds): (1) reset the noodled.ca FTP password in your host panel, (2) regenerate the Plaud token, then update `noodled-wp/.env` (FTP_PASS, PLAUD_TOKEN) and root `.env` (PLAUD_TOKEN). Both files are gitignored/not web-exposed, so this is precautionary. (Security, S)_ → `.env`
- [x] **Fresh `.min` builds** — _done 2026-06-19: regenerated `noodled.min.js`/`noodled.min.css` via esbuild so the working tree matches source. Full deploy (version bump + FTP) stays yours via `package.ps1`. (Speed, S)_
- [x] **Add an explicit `noindex` to the app shell + login fallback** — _done 2026-06-19: `<meta name="robots" content="noindex, nofollow">` added to `templates/app.php` + `templates/login.php`. (SEO, S)_
- [x] **A11y editor feedback gaps** — _done 2026-06-19: `aria-label` on the note-title input + raw-markdown textarea (were placeholder-only); `role="status" aria-live="polite"` on `#saveIndicator` and `#noteCount` so save state + result counts are announced. (Accessibility, S)_ → `noodled.js`, `templates/app.php`
- [x] Run `/gap` for competitive context — _done 2026-06-19: refreshed gap analysis; AI assistant is now the #1 table-stakes gap, self-hosting-on-your-own-WP is the top differentiator to defend._
- [x] Run `/so` and `/sa` to turn estimates into measured grades — _done (this run): Speed C 78, Security D 55 (real numbers, replacing last run's estimates)._
- [x] Re-run `/ac` and `/lr` — _done: a11y B 88, i18n A 91 on the current surface._
- [x] Dedupe the landing page's two `<h1>`; add `SPEC.md`; gate sync diagnostics; `defer` the app script; keyboard path for table toolbar — _all done in prior runs (v1.1.128–1.1.130)._

### 🏗️ High-impact
- [x] **Render-blocking stylesheet** — _done 2026-06-19: `noodled.css` now loads via inline critical CSS (`#noodled-critical`) + non-blocking `preload`/media-swap with a `<noscript>` fallback. Speed 78 → 84. (Speed, M)_ → `templates/app.php`
- [x] **Self-host the Fraunces font** — _done 2026-06-19: latin woff2 now in `assets/fonts/` + `@font-face` (`font-display:swap`) in `noodled.css`, preloaded; removed the third-party Google Fonts `<link>` + preconnects. Speed 84 → 88; keeps the branded dashboard look with no external dependency. (Speed/Privacy, S)_ → `templates/app.php`, `noodled.css`
- [ ] **Drop or consent-gate gtag.js on the private app** — _the lone third-party request left on the app shell (loads every authenticated session); removing it reaches Speed A and fits the privacy-first pitch. Left as your call (analytics). (Speed/Privacy, S)_ → `templates/app.php`, `templates/login.php`
- [x] **Two Medium security findings** — _done 2026-06-19 (`/sa`): public share-links now expire (30d) + are revocable (owner DELETE + "Stop public link" menu) + exact-matched (`hash_equals`); token-only PIN verify gained a site-wide failure cap on top of per-IP. Worst-open dropped Medium → Low. Security 72 → 85. Note: legacy share-links (no expiry) now 404 until re-shared. (Security, S/M)_ → `class-noodled-rest.php`, `class-noodled-auth.php`, `noodled.js`
- [x] **AI: ask-your-notes / summarize (BYO-key, server-proxied)** — _built 2026-06-19 (code-complete, pending live-key test): new `class-noodled-ai.php` with Claude/OpenAI/Google Gemini adapters; `/ai/summarize` + `/ai/ask` REST routes (authed); admin Settings → provider/key/model; ✨ Summarize button + "Ask your notes…" editor menu (shown only when a key is set). Keys stay server-side; locked notes never sent; ask corpus is access-scoped + capped. No Pro gate per decision. **Verify with a real key before relying on it.** (Capability/Revenue, M)_ → `class-noodled-ai.php`, ROADMAP §B
- [ ] **Drop gtag.js from the private app** — _Google Analytics loads every session on an authenticated notes app: a privacy smell that undercuts the self-hosting pitch, plus an extra request. (Speed/Positioning, effort S)_ → `class-noodled-app.php`
- [ ] **Native mobile (Capacitor iOS slice first)** — _every rival ships native; PWA-only is the biggest remaining gap. 80/20: an iOS wrapper adding only push-when-closed + a share extension. **Scoped 2026-06-19** (`MOBILE-IOS-SCOPE.md`): ~1 week build + Apple review; needs an Apple Developer account ($99/yr) + a Mac — 4 open decisions there before building. (Capability, effort L)_ → `MOBILE-IOS-SCOPE.md`, ROADMAP §A
- [x] Persist version history server-side; finish web push (VAPID + cron); first-run onboarding; nested notebooks — _all done (v1.1.130–1.1.140)._

### ✨ Polish
- [ ] **Free/Pro split (`/paid`)** — _gap rec: free core + a ~$39 one-time "Noodled Pro" site license, AI-assistant-led, Obsidian-style. A per-seat fee is indefensible vs free OSS peers; do NOT subscription-gate. (Revenue, effort M)_ → `/paid`, `/gap` pricing
- [ ] **RTL support** (`rtl.css` / CSS logical properties) — _only matters if Arabic/Hebrew/Farsi are in scope; the lone i18n gap keeping Language off a clean A. (effort M)_ → `/lr`
- [x] **Bound `Noodled_Notes::corpus()` with a `LIMIT`** — _done 2026-06-19: added `LIMIT 400` (newest-first) to the AI "ask" corpus query, closing the one unbounded query the back-end /so flagged. Worst-case memory is now flat on a large account; identical output for normal corpora (the ~60KB context cap bites first). (Back-end speed, S)_ → `class-noodled-notes.php`
- [ ] **Ship one real locale** (`.json` + `.po/.mo`) — _the translation pipeline exists but no locale file ships yet; one real locale validates the round-trip and proves the i18n claim. (effort S)_ → `languages/`
- [ ] **Loss-free ENEX importer** — _`/pp` flags Evernote-migration friction (dropped attachments/PDFs/tags) as the clearest acquisition funnel; harden `class-noodled-evernote.php`. (Capability/Growth, effort M)_ → `/pp`
- [ ] **Plugin/extension hook bus**, **Canvas/whiteboard**, **public note publishing** — _differentiators, not table stakes; schedule after AI + mobile. (effort L/L/M)_ → ROADMAP §C/§D + smaller items

## Strengths to protect
- **Breadth + completeness** — a true Evernote replacement: notes/notebooks, wiki-links, search, tags, trash, pin/star, attachments + EXIF + lightbox, dictation, location notes, Plaud + Evernote import, GitHub bidirectional sync, PWA, reminders + web push, table editor, version diff, passkeys, offline-first, lock-a-note E2EE, web clipper, code notes.
- **The differentiator to defend** — zero-extra-infrastructure self-hosting on a WordPress site you already own. No competitor in the set (Notion, Obsidian, Joplin, Anytype) can replicate it without becoming a different product. Family per-item permissions is the strong second.
- **Engineering discipline** — server-built note lists, N+1 → JOINs, lazy `/bodies`+`/backlinks`, ETag/304 file proxy, minified+cache-busted bundles, stale-while-revalidate SW.
- **Security posture (the parts that are right)** — XSS-hardened markdown, upload allowlist + content verification, private file proxy (403 unless authorized), per-user/per-note permissions, no-store headers, magic-link rate limiting. (The webhook bypass is the one open hole against an otherwise careful pass.)
- **Resilience** — real offline write queue + reconnect flush + genuine conflict UX (Keep mine/theirs/both).
- **Branded, cohesive UX** — Fraunces ☰ dashboard, dark/light, responsive 3-column, mobile speed-dial, lightbox, toasts, swipe, first-run onboarding — its own consistent design language.
- **i18n done right** — text domain, `.pot`, the 332KB SPA fully wrapped in `wp.i18n` (552 calls) rather than hard-coded English. Rare for a hand-rolled SPA.
- **SEO deference done right** — the one rankable surface (landing) carries full meta/canonical/OG/Twitter/`SoftwareApplication` JSON-LD; the private app correctly defers.

## Score history
- 2026-06-04 — Overall A- 90; first coach run. Baseline after v1.1.128. Speed/Security/SEO were coach estimates.
- 2026-06-04 (later) — Overall A- 92; persisted version history server-side, fixed landing double-`<h1>`, added `SPEC.md`, ran four audits (/so B+ 88 est, /sa A- 90 est, /ac B+ 94, /lr A- 95).
- 2026-06-04→05 (v1.1.130–1.1.140) — large execution batches: 9-feature PKM product batch, offline-first (IndexedDB + Background Sync), VAPID web push, table paste, first-run onboarding, offline-conflict data-loss fix. Capability + Resilience rose.
- 2026-06-19 (re-audit /so both + /sa) — re-ran after the AI feature landed. **Security holds B 85**: all three prior fixes still stand AND the new AI surface passed review clean (`ai_key` never reaches the browser, locked notes never sent, `/ai/ask` egress scoped to the caller's own notes, AI output XSS-safe via the same `renderMarkdown`). **Front-end Speed holds B 88**; **back-end Speed baseline B 90** (first back-end score — strong: cheap serve path, set-based queries, bounded push cron, AI remote calls are on-demand not hot-path). One new minor item from the AI code: `corpus()` is unbounded — **fixed same session** (`LIMIT 400`), closing the only unbounded query, so back-end Speed is now clean. Composite (frontend-pillar) unchanged A 90.
- 2026-06-19 (Speed r2) — self-hosted the Fraunces font (latin woff2 + `@font-face`, preloaded) and removed the third-party Google Fonts request from the app shell. Speed **84 → 88 B**; composite holds A 90 on stronger footing. Only remaining Speed lever is gtag.js on the private app (a product decision). The `.pot` refresh is still pending (no php/wp-cli available in this environment to run makepot).
- 2026-06-19 (AI feature) — built the #1 roadmap item: **AI assistant** (BYO-key, server-proxied) with Claude/OpenAI/Google Gemini providers, Summarize + Ask-your-notes, admin settings, and a frontend that only surfaces the controls when a key is configured. Closes the top table-stakes gap from `/gap`; Capability 94 → 96. Code-complete, compiles clean (esbuild), but not yet exercised against a live provider key — verify before relying on it. No Pro gate (per decision; revisit with `/paid`). New user-facing strings need a `.pot` refresh.
- 2026-06-19 (remediation r2) — Overall **A- 90 → A- 91**; composite 5p **86 → A 90** (every pillar now B or A). Fixed both Medium security findings (share-link expiry + revoke + exact-match; site-wide PIN-verify failure cap) → Security **72 → 85 B**, and the render-blocking app stylesheet (inline critical + non-blocking load) → Speed **78 → 84 B**. Remaining are all incremental: GitHub PAT plaintext (Low), Fraunces font render-blocking, gtag on the private app, pending `.env` rotation. Biggest remaining value is capability (AI ask-your-notes), not pillars.
- 2026-06-19 (re-measured) — Overall **B+ 87 → A- 90** after re-running `/5p` against the post-fix tree. Security **D 55 → C 72** (High webhook bypass closed → worst-open now Medium: share-link expiry, PIN brute-force), Accessibility **B 88 → A 95**, SEO **A 91 → A 93**. Composite 81 → 86; no pillar is D/F. Remaining levers: the two Medium security items, render-blocking CSS (Speed), and the pending `.env` secret rotation. Scores are working-tree; a deployed `/5p` would confirm live.
- 2026-06-19 (fixes) — cleared 4 of 5 quick wins same session: closed the High webhook auth-bypass (fail-closed on missing secret), added `noindex` to app+login, fixed the a11y editor labels + live regions (save status, result count), and regenerated fresh `.min` builds. Only the `.env` secret rotation remains (needs Simon — host panel + Plaud). Security should re-measure off the D (the one open High is closed); Accessibility and SEO tick up. Re-run `/5p` after deploy to confirm.
- 2026-06-19 — Overall **A- 92 → B+ 87**. First fully-measured run (fresh `/5p`, `/gap`, `/pp`). Capability A 94, Aesthetics A- 91 hold. Measured pillars: Language A 91 and SEO A 91 confirm strong; Accessibility B 88; **Speed C 78** (render-blocking CSS) and **Security D 55** (open High: unauthenticated GitHub-webhook bypass) replaced last run's optimistic estimates — that's what dropped the overall. Market shifted: AI ask-your-notes is now the #1 table-stakes gap; pricing rec is a ~$39 one-time Pro license, not per-seat. Top next lever: close the webhook bypass (and rotate `.env` secrets).
