# Coach Report — Noodled for WordPress v1.1.128
_Last coached: 2026-06-04_

A senior-dev, whole-product read of the `noodled-wp` plugin: what it does, how it
looks, and the five pillars. Speed, Security, and SEO have **no standalone audit
report yet**, so those rows are coach estimates from the code, not measured grades
(run `/so`, `/sa`, `/5p` to replace them with real numbers). Accessibility and
Language reuse the 2026-06-03 reports, adjusted upward for fixes shipped in
v1.1.128 today (both `/lr` errors and the `/ac` slash-menu + contrast items are
resolved).

## Scorecard
| Dimension | Grade | Score | Source/date |
|---|---|---|---|
| Capability | A | 95/100 | coach 2026-06-04 |
| Aesthetics/UX | A- | 90/100 | coach 2026-06-04 (code-only; no live pass) |
| Speed | B+ | 88/100 | /so 2026-06-04 (static baseline; no live PSI) |
| Security | A- | 90/100 | /sa 2026-06-04 (static baseline) |
| Accessibility | B+ | 94/100 | /ac 2026-06-04 |
| Language | A- | 95/100 | /lr 2026-06-04 |
| SEO | B+ | 90/100 | coach est 2026-06-04 (landing h1 fixed; no /5p) |
| **Overall** | **A-** | **92/100** | coach 2026-06-04 |

**Holistic read:** this is a genuinely impressive, feature-complete product —
a real Evernote replacement engineered with care (server-side note lists, JOIN
de-N+1, a real offline queue + conflict UX, a security pass, a branded SPA). It
reads like a senior dev's work. What keeps it from a clean A: two pillars
(Speed/Security) have no independent audit to confirm the strong code signals,
the just-shipped version-history **diff** sits on top of history that isn't
persisted server-side, reminders don't fire when the app is closed, and there's
no RTL. None are crises; they're the edge between "very good" and "world-class."

## Recommendations
### 🚀 Quick wins
- [x] Run `/so` and `/sa` to turn the two estimated pillars into measured grades — _done 2026-06-04: Speed B+ 88, Security A- 90 (static baselines)_
- [x] Re-run `/ac` and `/lr` to capture today's fixes — _done 2026-06-04: a11y B 90 → B+ 94, i18n B 90 → A- 95; all prior errors cleared_
- [x] Dedupe the landing page's two `<h1>` into one — _done 2026-06-04: demo mockup title demoted to a heading-role div via `serve_landing` (survives lab re-sync)_
- [x] Add `SPEC.md` (positioning + promised feature set) — _done 2026-06-04_
- [x] Gate the GitHub sync diagnostics behind `manage_options` — _done 2026-06-04: `sync_status` now returns owner/repo/branch + token flag only to admins (push/pull/import were already admin-only)_
- [x] Add `defer` to the main app script — _done 2026-06-04: `noodled.js` is now `defer`red (boot listener still fires; inline SW/i18n ordering preserved)_
- [x] Give the table toolbar a keyboard path — _done 2026-06-04: Alt+Shift+Arrows add/delete row+column from the caret cell; shortcuts shown in the toolbar button titles_

### 🏗️ High-impact
- [x] Persist note version history server-side — _done 2026-06-04: new `noodled_revisions` table + `/notes/{id}/revisions`; `update()` snapshots the pre-edit state (deduped, pruned to 15); `showHistory()` now fetches from the server, so diff/restore survive reloads and work across devices_
- [ ] Finish web push: VAPID keys + a wp-cron sender — _reminders currently fire only while the app is open; the SW `push` handler is already scaffolded; effort M_ → `class-noodled-rest.php` (cron), SW
- [ ] First-run onboarding / progressive disclosure — _50+ tools is a lot of surface for a new user; a short guided tour or staged reveal would cut overwhelm; effort M_ → `noodled.js`
- [ ] RTL support (`rtl.css` / CSS logical properties) — _only matters if Arabic/Hebrew/Farsi are in scope; the app has directional CSS and no RTL today; effort M_ → CSS, `/lr` fix list
- [ ] Nested notebooks / folder hierarchy — _flat notebooks are the main capability gap vs Notion/Evernote nesting; effort L_ → notebooks model
- [ ] Run `/gap` for competitive context (Notion, Obsidian Publish, Evernote, Standard Notes) — _no gap report exists; would surface table-stakes gaps + any Pro opportunity; effort S to run_ → `/gap`

### ✨ Polish
- [ ] Table editor: paste a TSV/markdown table to auto-build, and per-column alignment markers — _rounds out the new table editing; effort S/M_ → `noodled.js`
- [ ] Confirm the "Keep both" conflict copy lands in the visible notebook and refreshes cleanly — _new path, worth a manual pass; effort S_ → `showConflict`
- [ ] Consider a `/paid` free/pro split only if noodled.ca is meant to monetize — _currently single-tier (a deliberate family-product choice); list here as an option, not a gap; effort M_ → `/paid`

## Strengths to protect
- **Breadth + completeness** — a true Evernote replacement: notes/notebooks, wiki-links, search, tags, trash, pin/star, attachments + EXIF + lightbox, dictation, location notes, Plaud + Evernote import, GitHub bidirectional sync, PWA, reminders, table editor, version diff.
- **Engineering discipline** — server-built note lists (preview/tasks, not bodies), N+1 → JOINs, lazy `/bodies`+`/backlinks`, ETag/304 file proxy, minified bundles, stale-while-revalidate SW, cache-busting.
- **Security posture** — XSS-hardened markdown rendering, upload allowlist + content verification, private file proxy (403 unless authorized), per-user/per-note permissions, no-store headers, CSRF on landing upload, magic-link rate limiting.
- **Resilience** — real offline write queue with localStorage persistence + reconnect flush, and a genuine conflict UX (Keep mine / theirs / both).
- **Branded, cohesive UX** — Fraunces ☰ dashboard, dark/light, responsive 3-column, mobile speed-dial, lightbox, toasts, swipe — its own consistent design language.
- **i18n foundation** — text domain, `load_plugin_textdomain`, `.pot` (738 entries), `wp.i18n` shim, ~98% string coverage in the app.

## Score history
- 2026-06-04 — Overall A- 90; first coach run. Baseline after v1.1.128 (reminders, table editor + round-trip fix, version diff, conflict keep-both, legal pages) shipped live. Speed/Security/SEO are coach estimates pending `/so`, `/sa`, `/5p`.
- 2026-06-04 (later) — Overall A- 92; persisted version history server-side (new `revisions` table), fixed the landing double-`<h1>`, added `SPEC.md`, and ran the four audits: `/so` B+ 88, `/sa` A- 90, `/ac` B+ 94 (was B 90), `/lr` A- 95 (was B 90). New open items: gate GitHub sync diagnostics (security info-leak), `defer` the main script, keyboard path for the table toolbar.
- 2026-06-04 (v1.1.130) — shipped a 9-feature product batch (from a `/pp` note-app market read): unified Tasks/agenda + due dates, smart notebooks, calendar, link graph, nested notebooks, note transclusion, rich markdown (mermaid/math/callouts/collapsible), audio memos. Per-note encryption deliberately deferred. Capability and Aesthetics both rise; discoverability of the now-larger surface makes first-run onboarding the clearest next lever.
- 2026-06-04 (later still) — cleared all three new quick wins: gated `sync_status` GitHub diagnostics to admins (closes the /sa info-leak), `defer`red the main bundle (frontend speed), and added Alt+Shift+Arrow table-editing shortcuts (closes the /ac keyboard warning). Speed → ~A- and Security → A on the code layer once re-measured. Remaining headline item: VAPID web push.
