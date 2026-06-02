## noodled-wp — Technical Overview

**What it is:** A WordPress plugin that replicates the noodled desktop note-taking app as a full web version with family sharing.

**Stack:**
- WordPress plugin (PHP 8.2+)
- Vanilla JS frontend (extracted from desktop `ui.html`)
- Custom DB tables (not wp_posts)
- GitHub API for bidirectional sync
- Magic link email authentication

**Plugin structure:**
- `noodled.php` — main plugin file, constants, hooks, fail-safe load/init (logs + self-disables instead of white-screening)
- `includes/class-noodled-db.php` — 6 tables via dbDelta: notebooks (`drop_to`), notes, users, permissions, note_permissions, attachments (`exif`)
- `includes/class-noodled-settings.php` — tabbed admin Dashboard (Overview stats, Users, Branding, Import, Sync, Settings); landing-page preview button
- `includes/class-noodled-app.php` — serves full-screen app at `/noodled`, auth routing, login modal, admin landing preview (`?noodled_preview=landing`)
- `includes/class-noodled-rest.php` — 30+ REST endpoints under `noodled/v1`, incl. the private file proxy
- `includes/class-noodled-notes.php` — note CRUD (DB operations)
- `includes/class-noodled-notebooks.php` — notebook CRUD + drop folders (member sees "{Admin}'s noodle"; admin sees member name)
- `includes/class-noodled-attachments.php` — private uploads + EXIF extraction; served only via proxy; uploads dir denied direct access, random subfolders
- `includes/class-noodled-github.php` — GitHub API client (read + write)
- `includes/class-noodled-sync.php` — bidirectional sync, webhook handler, full import
- `includes/class-noodled-frontmatter.php` — markdown frontmatter serialize/parse (matches desktop format)
- `includes/class-noodled-evernote.php` — `.enex` import (per-user from app, plus admin)
- `includes/class-noodled-auth.php` — PIN login, one-click magic link, branded HTML emails, session management
- `includes/class-noodled-permissions.php` — per-notebook & per-note read/write access control
- `assets/css/noodled.css` — full stylesheet (dark/light theme, 3-column layout, responsive, lightbox)
- `assets/js/noodled.js` — complete frontend with REST API adapter replacing pywebview.api
- `assets/sw.js` — PWA service worker (network-first for assets so deploys are picked up)
- `templates/app.php` — full-screen app shell (no WP chrome)
- `templates/login.php` — PIN login page (fallback when no landing page set)

**Data flow:**
- Desktop app writes to `~/Documents/noodled-notes/` (git repo) → pushes to GitHub
- WordPress pulls from GitHub (webhook or import) → stores in custom DB tables
- Web edits save to DB → auto-push to GitHub via Contents API
- Conflict resolution: last-write-wins by modified timestamp

**Auth model:**
- WP admins auto-authenticated as noodled admin
- Family members: PIN email login (no passwords); one-click magic link, or PIN-only "Already have a PIN?" (token verify, rate-limited)
- Session cookie `noodled_session` (1 year)
- Per-notebook & per-note read/write permissions; notes private by default (no admin god-view)

**Attachments:** private only — served via `GET noodled/v1/file/{id}` which 403s unless the session can read the note; uploads dir `.htaccess` denies direct access, files under random subfolders, HTML/SVG served CSP-sandboxed.

**Drop folders:** admin toggles a member (Users tab, or at invite time) → member-owned notebook shared read/write with the admin, shown under the member's name in the admin's account.

**REST API namespace:** `noodled/v1`
- Notebooks, Notes, Trash, Search, Attachments, File proxy, Import (Evernote), Config, Sync, Plaud, Auth, Sharing, Admin (users/drop/pin/permissions)

**Deploy to Local:** Copy `noodled-wp/` to `wp-content/plugins/noodled/`
