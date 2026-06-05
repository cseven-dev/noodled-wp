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
zen / typewriter modes; **durable server-side version history with a visual diff
and restore**; **reminders** (date/time → notification); export HTML / download
markdown / print.

### Capture
Photo upload (auto-titled gallery note) with lightbox + EXIF; any-file attach via
toolbar, drag-drop, or clipboard paste; dictation (Web Speech API); **location
notes** (GPS → reverse-geocoded pinned map, admin-chosen provider); **voice
notes**; Plaud transcript sync; Evernote `.enex` import.

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
Magic-link + PIN auth (1-year session); per-notebook and per-note read/write
permissions (private by default, no admin god-view); owner-initiated sharing with
email notifications; admin drop folders (member-owned, shared to admin).

### Platform
PWA: installable, home-screen shortcuts, share-target, service-worker app-shell
caching, **reminder notifications** (client-scheduled today; VAPID server push is
the planned next step). WCAG 2.2 AA pass; i18n (text domain, `.pot`, `wp.i18n`).

## Architecture (summary)

PHP 8.2+ plugin; vanilla-JS SPA (`assets/js/noodled.js`); 8 custom tables via
dbDelta (notebooks, notes, users, permissions, note_permissions, attachments,
reminders, revisions); REST namespace `noodled/v1` (every response
`private, no-store`); private attachment proxy; magic-link auth. List responses
ship server-built `preview`+`tasks` (not full bodies) with lazy `/bodies` +
`/backlinks` for speed. See `CLAUDE.md` for the file-by-file map.

## Non-goals

Real-time multi-cursor collaboration; public note publishing; a browser
web-clipper extension; OCR. (Tracked as possible future work, not current
promises.)

## Roadmap (near-term)

- VAPID + wp-cron so reminders fire when the app is fully closed.
- First-run onboarding to tame the feature surface.
- RTL support if multilingual ships.
