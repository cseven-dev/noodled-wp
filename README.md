# Noodled for WordPress

A full web version of the [noodled](https://github.com/cseven-dev/Noodled) desktop note-taking app, built as a self-contained WordPress plugin. Replaces Evernote with a local-first, markdown-powered workflow accessible from any browser.

## What It Does

Noodled turns any WordPress site into a private note-taking app at `/noodled/`. It runs as a full-screen single-page app with no WordPress chrome — just the editor. Notes are stored in custom database tables (not wp_posts) and optionally sync bidirectionally with a GitHub repository, keeping the desktop app and web app in lockstep.

## Core Features

### Notes & Notebooks
- **Markdown notes** with YAML frontmatter (`title`, `created`, `modified`, `source`)
- **Notebooks** as organizational containers with per-user ownership
- **Rich editor** — contenteditable with live markdown rendering (headings, bold, italic, code, lists, checklists, blockquotes, tables, images, wiki-links)
- **Wiki-links** — `[[Note Title]]` syntax links notes together, with broken-link detection
- **Full-text search** with result highlighting across all accessible notes
- **Pinning** — pin notes to the top of any notebook
- **Starring** — star notes across notebooks for quick access via the Starred view
- **Trash** — soft-delete with restore, separate trash view, permanent delete
- **Note colors** — assign color labels to notes (shown as left border)
- **Tags** — inline `#tags` rendered as clickable chips, tag cloud browser, filter by tag
- **Sorting** — cycle between Modified, Created, and Alphabetical sort orders

### Editor Tools
- **Formatting** — bullet lists, checklists (with toggle), headings (cycle H1/H2/H3/P)
- **Photo upload** — "+ Photos" grabs many images at once and creates an auto-titled `Image Upload {date/time}` note; photos render in a gallery below the text and open in a **lightbox** (arrow keys / Esc), captioned with stored **EXIF** (date taken, camera, exposure, ISO, and a 📍 map link if geotagged)
- **Attachment gallery** — images and file-type icon tiles (🌐 HTML, 📕 PDF, 📄 docs, 📎 other) render in a bar below the note rather than lumped into the text; HTML/files open in the **same window** (swipe back to return)
- **File upload & drag-and-drop** — attach any file type from the toolbar or by dropping onto the editor
- **Image paste** — Ctrl+V screenshots from clipboard, auto-uploads as attachment
- **Dictation** — tap 🎤 and speak; your words are transcribed straight into the note at the cursor (Web Speech API)
- **Source mode** — toggle between rendered view and raw markdown editing
- **Find & replace** — Ctrl+H, highlights matches in the rendered content
- **Table of contents** — jump-to-heading navigation for long notes
- **Reading mode** — distraction-free full-screen view, no toolbars
- **Typewriter mode** — keeps cursor line vertically centered while typing
- **Zen mode** — fades all paragraphs except the one you're editing
- **Version history** — stores last 5 saves per note (in-memory), click to restore
- **Word count** — live word/character/read-time counter with optional word count goal
- **Backlinks** — shows which other notes link to the current note via wiki-links
- **Export** — download any note as a standalone HTML file
- **Auto-link** — pasted URLs auto-convert to clickable markdown links
- **Media embeds** — YouTube and Vimeo URLs on their own line render as inline video players

### Sync & Import
- **GitHub bidirectional sync** — push notes to a GitHub repo, pull changes from desktop app
- **Webhook support** — GitHub push webhooks trigger automatic imports
- **Auto-sync** — silently pulls from GitHub every 5 minutes
- **Evernote import (per user)** — each member imports their own `.enex` export from inside the app (⋮ menu → Import from Evernote); admins can also bulk-import from the Dashboard
- **Plaud sync** — imports voice recordings from [Plaud](https://plaud.ai) as transcribed notes (reads token from `.env` file)
- **Conflict resolution** — last-write-wins by modified timestamp
- **Frontmatter compatibility** — reads/writes the same YAML frontmatter format as the desktop app

### Authentication
- **PIN login** — enter email, receive a 6-digit PIN via email, type it to log in (no passwords)
- **One-click magic link** — the login email's button signs you in directly (the PIN is embedded in the link)
- **PIN-only entry** — "Already have a PIN?" asks for just the 6-digit code (no email), so phone OTP autofill lands in the right field; the endpoint is IP rate-limited
- **Branded emails** — login PINs and share notifications are HTML emails styled to match your brand (name, tagline, accent color, CTA button)
- **1-year sessions** — cookie lasts 365 days so you rarely need to re-authenticate
- **WP admin auto-auth** — WordPress administrators are automatically authenticated

### Admin & Family Sharing
- **Tabbed Dashboard** — Overview, Users, Branding, Import, Sync, and Settings are tabs on a single Noodled admin page
- **At-a-glance stats** — notes, notebooks, users, pending requests, attachments, storage used, trash, drop folders, last sync, version
- **User management** — invite, approve, remove members and assign roles, all from the dashboard
- **Send PIN** — issue a member a login PIN from the backend; it's emailed *and* shown to you so you can relay it directly
- **Drop folders** — tick a member (or enable at invite time) and they get one folder whose contents are shared read/write with you, appearing in your account **under their name**; the member sees it as "{Your name}'s noodle"
- **Per-notebook & per-note sharing** — members share their own notebooks and notes (read or read/write); notes are private by default with no admin god-view
- **Landing page preview** — preview the public landing page from the dashboard even while signed in
- **Branding** — custom app name, tagline, accent color, and an uploadable landing page

### Privacy & Security
- **Private attachments** — files are served only through an access-checked proxy (`/noodled/v1/file/{id}`) that returns a file solely to a logged-in user who can read its note (otherwise 403). The uploads directory denies all direct web access and files are stored under random, unguessable subfolders — nothing is reachable at a public URL.
- **Sandboxed HTML/SVG** — uploaded HTML/SVG is served with a CSP sandbox so a malicious file can't run scripts in your site's origin
- **Fail-safe loading** — if the plugin ever throws on load or init, it logs the cause and stays out of the way instead of white-screening the whole site (including wp-admin)
- **Everything private by default** — notes, notebooks, and attachments are private to their owner unless explicitly shared

### Mobile Experience
- **Bottom tab bar** — Notes, Search, +New, Sync, Menu (replaces desktop toolbar)
- **Slide transitions** — note content slides in from right, swipe right from left edge to go back
- **Swipe between notes** — swipe left/right within a note to navigate prev/next
- **Pull to refresh** — pull down on note list to sync
- **Touch feedback** — scale-down animations on all tappable elements
- **Collapsible editor toolbar** — primary buttons visible, overflow in dropdown menu
- **PWA support** — installable as a home screen app with manifest, icons, and service worker

### Organization & Power Tools
- **Notebook colors** — auto-assigned color dots for visual scanning in sidebar
- **Notebook drag reorder** — drag notebooks to customize sidebar order
- **Notebook cover images** — set a header image for any notebook
- **Note templates** — Meeting Notes, Journal Entry, Project Plan, Quick List presets
- **Daily journal** — one-click creates/opens today's dated entry in a Journal notebook
- **Duplicate note** — clone any note from context menu
- **Bulk select** — checkbox mode to select multiple notes for move or delete
- **Quick capture** — Ctrl+. floating input bar to jot a thought without leaving current note
- **Quick open** — Ctrl+P fuzzy search to jump to any note by title
- **Focus timer** — Pomodoro timer (15/25/45/60 min) with vibration alert on completion
- **Note link graph** — shows all wiki-link connections between notes
- **Statistics dashboard** — total notes, words, per-notebook counts, 14-day activity heatmap
- **Public sharing** — generate a read-only public URL for any note

### Data & Reliability
- **Auto-save** — saves 2 seconds after you stop typing
- **Save queue** — failed saves are queued and retried every 15 seconds
- **Unsaved warning** — browser warns before closing with unsaved changes
- **Offline support** — service worker caches static assets and API responses for offline access
- **Remember last note** — reopens where you left off
- **Dynamic page title** — browser tab shows current note title

## Architecture

### Plugin Structure
```
noodled-wp/
  noodled.php                          # Main plugin file, constants, hooks
  includes/
    class-noodled-db.php               # 6 tables via dbDelta: notebooks (drop_to), notes, users, permissions, note_permissions, attachments (exif)
    class-noodled-app.php              # Serves full-screen app at /noodled/, auth routing, login modal, landing preview
    class-noodled-rest.php             # 30+ REST API endpoints under noodled/v1, incl. private file proxy
    class-noodled-notes.php            # Note CRUD (DB operations)
    class-noodled-notebooks.php        # Notebook CRUD + drop folders ("{Name}'s noodle" labelling)
    class-noodled-attachments.php      # Private uploads + EXIF extraction; served only via the proxy
    class-noodled-github.php           # GitHub API client (read + write)
    class-noodled-sync.php             # Bidirectional sync, webhook handler, full import
    class-noodled-frontmatter.php      # Markdown frontmatter serialize/parse
    class-noodled-evernote.php         # .enex import (per-user and admin)
    class-noodled-auth.php             # PIN login, magic link, branded emails, session management
    class-noodled-permissions.php      # Per-notebook & per-note read/write access control
    class-noodled-settings.php         # Tabbed admin dashboard (stats, users, branding, import, sync, settings)
    class-noodled-plaud.php            # Plaud API client for voice recording import
  assets/
    js/noodled.js                      # Complete frontend (~2300 lines)
    css/noodled.css                    # Full stylesheet (~1100 lines, dark/light themes)
    sw.js                              # Service worker for offline/caching
    manifest.json                      # PWA manifest
    icon-192.png, icon-512.png         # App icons
  templates/
    app.php                            # Full-screen app shell
    login.php                          # PIN login page
  plugin-update-checker/               # Auto-update from GitHub releases
  package.ps1                          # Build, version, and FTP deploy script
```

### Data Flow
```
Desktop app (Python/pywebview)
  └─ writes .md files to ~/Documents/noodled-notes/
  └─ git push to GitHub
       └─ webhook or manual sync
            └─ WordPress pulls from GitHub → stores in custom DB tables
                 └─ Web edits save to DB → auto-push to GitHub via Contents API
                      └─ Desktop git pull picks up web changes
```

### REST API (`noodled/v1`)
- **Notebooks** — GET/POST `/notebooks`, POST `/notebooks/rename`, `/notebooks/delete`
- **Notes** — GET/POST `/notes`, GET/PUT/DELETE `/notes/{id}`, POST `/notes/{id}/move`, `/notes/{id}/pin`, `/notes/{id}/share`
- **Trash** — GET/DELETE `/trash`, GET `/trash/count`, POST `/trash/{id}/restore`, DELETE `/trash/{id}`
- **Search** — GET `/search?q=`
- **Attachments** — POST `/attachments`, DELETE `/attachments/{id}`
- **File proxy** — GET `/file/{id}` (access-checked private file streaming; returns 403 unless the session can read the note)
- **Import** — POST `/import/evernote` (per-user `.enex` upload)
- **Sync** — GET `/sync/status`, POST `/sync/push`, `/sync/pull`, `/sync/import`
- **Plaud** — GET `/plaud/status`, POST `/plaud/sync`
- **Auth** — POST `/auth/login`, `/auth/pin`, `/auth/logout`, GET `/auth/verify` (PIN-only, rate-limited), `/auth/me`
- **Sharing** — POST `/share`, `/notebooks/{id}/shares`, `/notes/{id}/shares`, GET `/shared/{token}`
- **Config** — GET/PUT `/config`
- **Admin** — POST `/admin/users`, DELETE `/admin/users/{id}`, POST `/admin/users/{id}/approve`, `/admin/users/{id}/drop` (drop folder), `/admin/users/{id}/pin` (send PIN), `/admin/permissions`

### Keyboard Shortcuts
| Shortcut | Action |
|----------|--------|
| Ctrl+N | New note |
| Ctrl+S | Force save |
| Ctrl+P | Quick open |
| Ctrl+J | Daily journal |
| Ctrl+H | Find & replace |
| Ctrl+. | Quick capture |
| Ctrl+Shift+F | Focus search |
| Ctrl+B | Bold |
| Ctrl+I | Italic |
| Escape | Exit reading mode |
| ? | Keyboard shortcuts help |

## Deployment

### Package & Deploy
```powershell
# Package only (creates zip, copies to Versions and Production)
.\package.ps1

# Package + deploy via FTP
.\package.ps1 -Deploy
```

The script auto-increments the patch version on each run and updates both `noodled.php` header and `NOODLED_VERSION` constant.

### Configuration
1. **GitHub sync** — Settings > Noodled: enter repo owner, name, token, branch
2. **Plaud sync** — place `.env` file with `PLAUD_TOKEN=` in the plugin directory
3. **FTP deploy** — place `.env` file with `FTP_HOST`, `FTP_USER`, `FTP_PASS`, `FTP_PATH`

### Requirements
- WordPress 5.9+
- PHP 8.0+
- MySQL with InnoDB (for FULLTEXT search)
