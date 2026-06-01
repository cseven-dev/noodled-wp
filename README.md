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
- **File upload** — attach any file type, inserts markdown link into note body
- **Image paste** — Ctrl+V screenshots from clipboard, auto-uploads as attachment
- **Voice memo** — record audio from browser microphone, saves as `.webm` attachment
- **HTML attachment viewer** — uploaded HTML files (e.g., Claude artifacts) open in a full-screen overlay with Back button, instead of navigating away
- **Drag & drop** — drop files onto the editor to attach
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
- **Plaud sync** — imports voice recordings from [Plaud](https://plaud.ai) as transcribed notes (reads token from `.env` file)
- **Conflict resolution** — last-write-wins by modified timestamp
- **Frontmatter compatibility** — reads/writes the same YAML frontmatter format as the desktop app

### Authentication
- **PIN login** — enter email, receive a 6-digit PIN via email, type it to log in (no passwords, no magic link URLs to fumble with on mobile)
- **1-year sessions** — cookie lasts 365 days so you rarely need to re-authenticate
- **WP admin auto-auth** — WordPress administrators are automatically authenticated
- **Family sharing** — invite members via email, per-notebook read/write permissions

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
    class-noodled-db.php               # 5 tables via dbDelta (notebooks, notes, users, permissions, attachments)
    class-noodled-app.php              # Serves full-screen app at /noodled/, handles auth routing
    class-noodled-rest.php             # 25+ REST API endpoints under noodled/v1
    class-noodled-notes.php            # Note CRUD (DB operations)
    class-noodled-notebooks.php        # Notebook CRUD
    class-noodled-attachments.php      # File uploads to wp-content/uploads/noodled/
    class-noodled-github.php           # GitHub API client (read + write)
    class-noodled-sync.php             # Bidirectional sync, webhook handler, full import
    class-noodled-frontmatter.php      # Markdown frontmatter serialize/parse
    class-noodled-auth.php             # PIN login, session management
    class-noodled-permissions.php      # Per-notebook read/write access control
    class-noodled-settings.php         # Admin settings page (GitHub config, user management)
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
- **Sync** — GET `/sync/status`, POST `/sync/push`, `/sync/pull`, `/sync/import`
- **Plaud** — GET `/plaud/status`, POST `/plaud/sync`
- **Auth** — POST `/auth/login`, `/auth/pin`, `/auth/logout`, GET `/auth/verify`, `/auth/me`
- **Sharing** — POST `/share`, GET `/shared/{token}`
- **Config** — GET/PUT `/config`
- **Admin** — POST `/admin/users`, DELETE `/admin/users/{id}`, POST `/admin/permissions`

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
