# Noodled for WordPress — Product Spec

## Vision

Turn any WordPress site into a private, local-first **markdown note-taking app**
at `/noodled/` — a self-hosted Evernote replacement a family or small team can
run on infrastructure they already own. Notes live in custom DB tables (never
`wp_posts`), render as a full-screen single-page app with no WordPress chrome,
and optionally sync bidirectionally with a GitHub repo so the desktop app and the
web app stay in lockstep.

## Positioning

- **Self-hosted & private** — no third-party SaaS; your notes stay on your host.
- **Local-first parity** — the web app mirrors the `pywebview` desktop app; GitHub
  is the shared source of truth (last-write-wins by modified timestamp).
- **Family-friendly, password-free** — magic-link / PIN auth, per-user privacy,
  owner-initiated sharing, and admin "drop folders".
- **Markdown-native** — plain `.md` + YAML frontmatter; portable, greppable, yours.

Not a Notion/Confluence competitor for large orgs, and not a public publishing
platform — the app itself is private and `noindex`; only the marketing landing
page is public and SEO-tuned.

## Target users

Individuals and families replacing Evernote who already run WordPress (or will,
to self-host). Admin = the WordPress owner; members join by email invite.

## Core feature set (what it promises to do)

### Notes & organization
Markdown notes with frontmatter; notebooks (per-user owned); wiki-links
(`[[Title]]`) with broken-link detection; full-text search with highlighting;
pin, star, color labels, inline `#tags` + tag cloud; trash with restore and
permanent delete; sort by modified / created / alphabetical.

### Editor
Contenteditable live-markdown editor (headings, bold/italic/code, lists,
checklists with toggle, blockquotes, **tables with add/remove row+column**,
images, wiki-links); slash menu; find & replace; table of contents; reading /
zen / typewriter modes; **code notes** (a verbatim monospace note that the
markdown editor never reflows); **lock a note** (opt-in per-note end-to-end
encryption, client-side AES-256-GCM, the passphrase never leaves the device);
**durable server-side version history with a visual diff and restore** (now
snapshotted on sync overwrites too, reachable from a toolbar clock icon or the
note's right-click menu); **reminders** (date/time → notification); export HTML /
download markdown / print.

### Capture
Photo upload (auto-titled gallery note) with lightbox + EXIF; any-file attach via
toolbar, drag-drop, or clipboard paste; dictation (Web Speech API); **location
notes** (GPS → reverse-geocoded pinned map, admin-chosen provider); **voice
notes**; **web clipper** (a per-user-token bookmarklet that saves any page +
selection into a Web Clips notebook); **desktop drop folder** (a small Windows
watcher posts any file dropped in a folder to the app, text becomes the note body,
other files are attached); Plaud transcript sync; Evernote `.enex` import.

### Sync & data
GitHub bidirectional sync (Contents API + webhook + full import); markdown
frontmatter matching the desktop format; **offline write queue** with reconnect
replay and a **conflict resolver** (keep mine / theirs / both).

### Offline-first
Goal: open and edit any recent note with no connection, and never lose an edit.
Backed by an **IndexedDB layer shared by the app and the service worker** (records
scoped by user, wiped on logout / user-switch so a shared device never leaks one
member's notes to another).
- **Offline note opening (#6, all platforms):** note bodies are cached on read,
  plus a warm cache of **all pinned + the 200 most-recently-modified** notes (LRU
  evicted). `get_note` falls back to the cached body offline; cached notes show an
  "available offline" marker.
- **Background flush (#4, Chromium only):** the durable save queue lives in
  IndexedDB; a service-worker **Background Sync** (`sync`) handler replays it when
  the device reconnects, even with the app closed. iOS Safari/PWA has no Background
  Sync, so it falls back to the existing flush-on-next-open behaviour.
- **Conflict-safe replay:** background replay uses the modified-timestamp guard
  (never a blind overwrite); a conflict re-queues the edit and raises the
  conflict resolver the next time the app opens.

### Accounts & sharing
Magic-link + PIN auth (1-year session, rate-limited against email-scanner
prefetch); **passkeys / WebAuthn** (one-tap Face ID / fingerprint sign-in, PIN
stays the fallback); **multi-device sessions** (one row per device, so signing in
on one device never logs out another); per-notebook and per-note read/write
permissions (private by default, no admin god-view); owner-initiated sharing with
email notifications; admin drop folders (member-owned, shared to admin).

### Platform
PWA: installable, home-screen shortcuts, share-target, service-worker app-shell
caching, **reminder notifications** (client-scheduled in-app, plus **VAPID web
push via wp-cron** so reminders fire when the app is closed). **Captain's Log**: a
fun in-app history of the product, reachable from the ☰ menu. WCAG 2.2 AA pass;
i18n (text domain, `.pot`, `wp.i18n`).

## Architecture (summary)

PHP 8.2+ plugin; vanilla-JS SPA (`assets/js/noodled.js`); 11 custom tables via
dbDelta (notebooks, notes, users, sessions, permissions, note_permissions,
attachments, reminders, revisions, push_subs, webauthn_creds); REST namespace
`noodled/v1` (every response
`private, no-store`); private attachment proxy; magic-link auth. List responses
ship server-built `preview`+`tasks` (not full bodies) with lazy `/bodies` +
`/backlinks` for speed. See `CLAUDE.md` for the file-by-file map.

## Non-goals

Real-time multi-cursor collaboration; public note publishing; OCR / handwriting
recognition. (Tracked as possible future work, not current promises. The web
clipper, once a non-goal, now ships as a bookmarklet.)

## Roadmap

The four larger features still missing versus the market-leading PKM apps. See
`ROADMAP.md` for the full scoping of each.

- **AI: ask-your-notes / summarize** (Med) — bring-your-own-key, server-proxied,
  never sends locked notes.
- **Native mobile apps** (High) — Capacitor wrapper of the existing PWA, with
  native push + share-extension; iOS push-when-closed is the 80/20 first slice.
- **Canvas / whiteboard** (High) — an infinite board persisted as a note so it
  syncs via GitHub like everything else.
- **Plugin / extension system** (High) — a client-side hook bus so add-ons extend
  the app without editing core.

Smaller remaining items: transparent at-rest encryption (under the opt-in lock),
public note publishing, OCR, and RTL support if multilingual ships.
