## noodled-wp — Technical Overview

**What it is:** A WordPress plugin that replicates the noodled desktop note-taking app as a full web version with family sharing.

**Stack:**
- WordPress plugin (PHP 8.2+)
- Vanilla JS frontend (extracted from desktop `ui.html`)
- Custom DB tables (not wp_posts)
- GitHub API for bidirectional sync
- Magic link email authentication

**Plugin structure:**
- `noodled.php` — main plugin file, constants, hooks
- `includes/class-noodled-db.php` — 5 tables via dbDelta (notebooks, notes, users, permissions, attachments)
- `includes/class-noodled-settings.php` — admin settings page (GitHub config, user management, permissions matrix)
- `includes/class-noodled-app.php` — serves full-screen app at `/noodled`, handles auth routing
- `includes/class-noodled-rest.php` — 20+ REST API endpoints under `noodled/v1`
- `includes/class-noodled-notes.php` — note CRUD (DB operations)
- `includes/class-noodled-notebooks.php` — notebook CRUD
- `includes/class-noodled-attachments.php` — file uploads to `wp-content/uploads/noodled/`
- `includes/class-noodled-github.php` — GitHub API client (read + write)
- `includes/class-noodled-sync.php` — bidirectional sync, webhook handler, full import
- `includes/class-noodled-frontmatter.php` — markdown frontmatter serialize/parse (matches desktop format)
- `includes/class-noodled-auth.php` — magic link login, session management
- `includes/class-noodled-permissions.php` — per-notebook read/write access control
- `assets/css/noodled.css` — full stylesheet (dark/light theme, 3-column layout, responsive)
- `assets/js/noodled.js` — complete frontend with REST API adapter replacing pywebview.api
- `templates/app.php` — full-screen app shell (no WP chrome)
- `templates/login.php` — magic link login page

**Data flow:**
- Desktop app writes to `~/Documents/noodled-notes/` (git repo) → pushes to GitHub
- WordPress pulls from GitHub (webhook or import) → stores in custom DB tables
- Web edits save to DB → auto-push to GitHub via Contents API
- Conflict resolution: last-write-wins by modified timestamp

**Auth model:**
- WP admins auto-authenticated as noodled admin
- Family members: magic link email login (no passwords)
- Session cookie `noodled_session` (30 days)
- Per-notebook read/write permissions

**REST API namespace:** `noodled/v1`
- Notebooks, Notes, Trash, Search, Attachments, Config, Sync, Auth, Admin

**Deploy to Local:** Copy `noodled-wp/` to `wp-content/plugins/noodled/`
