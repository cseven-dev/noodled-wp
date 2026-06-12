<!-- KAP:BEGIN behavioral-guidelines -->
Behavioral guidelines to reduce common LLM coding mistakes. Merge with project-specific instructions as needed.

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.
<!-- KAP:END behavioral-guidelines -->

## noodled-wp — Technical Overview

**What it is:** A WordPress plugin that replicates the noodled desktop note-taking app as a full web version with family sharing.

**Version:** 1.1.154 (`Version:` header + `NOODLED_VERSION` in `noodled.php`; changelog in `metadata.json`).

**Stack:**
- WordPress plugin (PHP 8.2+)
- Vanilla JS frontend (extracted from desktop `ui.html`)
- Custom DB tables (not wp_posts)
- GitHub API for bidirectional sync
- Magic link email authentication

**Plugin structure:**
- `noodled.php` — main plugin file, constants, hooks, fail-safe load/init (logs + self-disables instead of white-screening)
- `includes/class-noodled-db.php` — 11 tables via dbDelta: notebooks (`drop_to`, `sort_order`, `color`, `parent_id` — nesting), notes, users, **sessions** (`token`, `user_id`, `expires_at` — one row per device, so signing in on one device never logs out another), permissions, note_permissions, attachments (`exif`, `alt`, `sort_order`), reminders (`note_id`, `user_id`, `remind_at`, `label`, `sent`), revisions (`note_id`, `title`, `body`, `created_at` — durable version history, pruned to 15/note), **push_subs** (per-user web-push subscriptions), **webauthn_creds** (passkey credentials: `credential_id`, `public_key` PEM, `sign_count`)
- `includes/class-noodled-settings.php` — tabbed admin Dashboard (Overview stats, Users, Branding, Import, Sync, Settings incl. **location-note map provider**: OSM / Mapbox / Google + keys); landing-page preview button
- `includes/class-noodled-app.php` — serves full-screen app at `/noodled`, auth routing, login modal, admin landing preview (`?noodled_preview=landing`); public legal pages (`?noodled_legal=privacy|terms`, branded stub); home-screen shortcut / share-target / reminder launch params (`?noodled_do=`, `?noodled_share=`, `?noodled_note=`)
- `includes/class-noodled-rest.php` — 30+ REST endpoints under `noodled/v1`, incl. the private file proxy. Notes ship server-built `preview`+`tasks` (not full bodies); lazy `/bodies` + `/backlinks`; attachment `reorder`/`alt`; notebook `color`; per-user note `reminders` (GET/POST/DELETE/sent); note `revisions` (GET, server-snapshotted on save); cross-note `/tasks` agenda + `/notes/{id}/task-toggle`; `/notebooks/parent` (nesting). `sync_status` exposes GitHub config only to admins. Every response sends `Cache-Control: private, no-store` + `Vary: Cookie` (the client also cache-busts GETs)
- `includes/class-noodled-notes.php` — note CRUD (DB operations)
- `includes/class-noodled-notebooks.php` — notebook CRUD + drop folders (member sees "{Admin}'s noodle"; admin sees member name)
- `includes/class-noodled-attachments.php` — private uploads + EXIF extraction; served only via proxy; uploads dir denied direct access, random subfolders
- `includes/class-noodled-github.php` — GitHub API client (read + write)
- `includes/class-noodled-sync.php` — bidirectional sync, webhook handler, full import; snapshots a version-history revision before a sync/import overwrites an existing note
- `includes/class-noodled-frontmatter.php` — markdown frontmatter serialize/parse (matches desktop format)
- `includes/class-noodled-evernote.php` — `.enex` import (per-user from app, plus admin)
- `includes/class-noodled-auth.php` — PIN login, one-click magic link, branded HTML emails, session management
- `includes/class-noodled-permissions.php` — per-notebook & per-note read/write access control
- `includes/class-noodled-plaud.php` — Plaud transcript sync on the web (token in Settings → Sync)
- `includes/class-noodled-push.php` — VAPID web push (delivers reminders when the app is closed; `noodled_push_check` cron, `push_subs` table)
- `includes/class-noodled-webauthn.php` — passkeys / WebAuthn (pure-PHP CBOR decode + openssl ES256 verify; `/auth/passkey/*` routes)
- `assets/css/noodled.css` — full stylesheet (dark/light theme, 3-column layout, responsive, lightbox, branded ☰ menu dashboard, mobile bottom-nav)
- `assets/js/noodled.js` — complete frontend (REST adapter): quick-add speed dial, dictation listening bar, location notes (reverse-geocoded), slash menu, swipe actions, drag-to-notebook, toast stack, list filters, search scope/highlight, attachment reorder + alt; offline save queue + conflict modal (Keep mine/theirs/**both**); table editor (round-trip serialize + add/del row/col); version-history **diff** view; **reminders** (set + client scheduler → SW notification); **Tasks/agenda** view (cross-note checklist aggregation, due dates); **smart notebooks** (saved-query sidebar); **calendar** (journal nav); **link graph** (force-directed wiki-link map); **nested notebooks** (collapsible tree); **transclusion** (`![[Note]]` embeds, round-trip via data-attr); **rich markdown** (mermaid/math lazy-loaded from CDN, callouts, collapsible — all round-trip via data-attr); **audio memos** (MediaRecorder + transcription); **numbered lists** (auto-detect `1.`+Enter → `<ol>`, slash-menu option); shared notebooks grouped under a virtual **"Others' Noodles"** section (per-owner); table toolbar follows the caret for keyboard users (Alt+Shift+Arrow ops)
- `assets/manifest.json` — PWA manifest: home-screen shortcuts (New note / Daily journal / Quick capture) + share-target (text/URL)
- `assets/sw.js` — PWA service worker (stale-while-revalidate for the app shell keyed by `?v=`); `notificationclick` (focus/open the reminded note) + `push` handler (VAPID web push; reminders fire even when the app is closed, see `class-noodled-push.php`)
- `templates/app.php` — full-screen app shell (no WP chrome); branded ☰ menu dashboard, sync-status pill, Fraunces headings, wp.i18n floor shim
- `templates/login.php` — PIN login page (fallback when no landing page set)

**i18n:** text domain `noodled`, `load_plugin_textdomain`, `languages/noodled.pot`, JS via `window.wp.i18n` (shim + `setLocaleData`); admin/login inline JS via `esc_js(__())`. WCAG 2.2 AA pass done. (Both audited 2026-06-03; reports in `GitHub/logs/`.)

**Data flow:**
- Desktop app writes to `~/Documents/noodled-notes/` (git repo) → pushes to GitHub
- WordPress pulls from GitHub (webhook or import) → stores in custom DB tables
- Web edits save to DB → auto-push to GitHub via Contents API
- Conflict resolution: last-write-wins by modified timestamp

**Auth model:**
- WP admins auto-authenticated as noodled admin
- Family members: PIN email login (no passwords); one-click magic link, or PIN-only "Already have a PIN?" (token verify, rate-limited)
- Session cookie `noodled_session` (1 year), stored per-device in `noodled_sessions` (concurrent multi-device logins; logout drops only that device). WP admins are auto-authed via the WP cookie **and** minted a long-lived app session on load (`ensure_admin_session`) so they aren't logged out when the short WP cookie lapses
- **Passkeys (WebAuthn)**: optional one-tap biometric sign-in (`class-noodled-webauthn.php` — pure-PHP CBOR decode + openssl ES256 verify; routes `/auth/passkey/{register-options,register,auth-options,auth}`). Register from the ☰ menu, sign in from the login overlay; mints a normal session on success. PIN/magic-link stays the fallback.
- Per-notebook & per-note read/write permissions; notes private by default (no admin god-view)

**Attachments:** private only — served via `GET noodled/v1/file/{id}` which 403s unless the session can read the note; uploads dir `.htaccess` denies direct access, files under random subfolders, HTML/SVG served CSP-sandboxed.

**Drop folders:** admin toggles a member (Users tab, or at invite time) → member-owned notebook shared read/write with the admin, shown under the member's name in the admin's account.

**REST API namespace:** `noodled/v1`
- Notebooks (+ color), Notes, Bodies, Backlinks, Trash, Search, Attachments (+ reorder/alt), Reminders (per-user, note-attached), File proxy, Import (Evernote/zip), Export, Config, Sync, Plaud, Auth, Sharing, Admin (users/drop/pin/permissions), **Clip** (web clipper `/clip` + `/clip/setup`, and desktop drop folder `/clip/file` — all token-authed, no cookie)

**Location notes:** quick-add captures GPS, reverse-geocodes (Nominatim), embeds a pinned map (provider per admin setting); tapping it offers Apple/Google/Waze. **Voice notes:** date-titled note + auto-started dictation.

**Recent additions (v1.1.x):**
- **Web clipper:** a per-user-token bookmarklet (`/clip`, `/clip/setup`, `noodled_clip_tokens`) that saves any page + selection into a "Web Clips" notebook (`showWebClipper()`).
- **Desktop drop folder:** `/clip/file` (token-authed) + the `noodled_dropbox.py` watcher (repo root) — any file dropped into a desktop folder becomes a note (text → body, other files attached, into an "Inbox" notebook).
- **Lock a note:** opt-in per-note client-side AES-256-GCM E2EE; body stored as `noodled:enc:v1:<payload>`, the passphrase never leaves the device; previews/search show "🔒 Locked note" server-side (`encryptBody` / `lockCurrentNote` in `noodled.js`).
- **Code notes:** a verbatim monospace note the markdown editor never reflows (`codeNoteParts`).
- **Captain's Log:** an in-app product-history page in the ☰ menu (`showCaptainsLog`, `CAPTAINS_LOG`).
- **Version history** is snapshotted before GitHub-sync and move overwrites (not just on save) and is reachable from a toolbar clock icon and the note's right-click menu.

**Deploy to Local:** Copy `noodled-wp/` to `wp-content/plugins/noodled/`
