# Noodled — Roadmap

Scope notes for the four larger features still missing versus the market-leading
PKM apps (per the 2026-06-11 gap analysis). Everything else a buyer shortlists is
already shipped (web clipper, opt-in E2EE, code notes, drop folder, sync-safe
history, passkeys, offline-first, reminders/push). These four are planned, not
built. Suggested order: **B (AI) → A (iOS slice) → D (canvas) → C (plugin API)**.

---

## A. Native mobile apps  (effort: High, ~1–2 weeks + ongoing)

**Why:** every rival ships native iOS/Android; noodled is PWA-only, and iOS PWA
has no background sync and gated push. This is the single biggest remaining gap.

**Approach:** wrap the existing SPA with **Capacitor** (do not rebuild). noodled
is already a PWA (`assets/manifest.json`, `assets/sw.js`, single page at
`/noodled`). A thin Capacitor shell loads the hosted app, so ordinary plugin
deploys keep updating the mobile app without an app-store review each time.

**What it takes:**
- New `noodled-mobile/` Capacitor project; add iOS + Android platforms.
- Native plugins:
  - **Push** (APNs for iOS, FCM for Android): replaces browser web-push on device.
    The server gains an APNs/FCM send path alongside the existing VAPID
    `push_subs` infrastructure (store device tokens, fan out from the same
    reminder cron).
  - **Share extension / share target**: "Share → noodled" creates a note; reuse
    the token-authed `/clip` or `/clip/file` endpoints.
  - **Camera / Filesystem**: attachments and photo capture.
  - **Biometric** unlock; safe-area / status-bar polish.
- **Auth:** magic-link/PIN already works in a WebView; add universal links (iOS) /
  app links (Android) so the magic link reopens the app; bridge passkeys to native
  WebAuthn.
- **Release pipeline:** Xcode (a Mac is required) + Android Studio; signing certs;
  **Apple Developer Program ($99/yr)** + **Google Play ($25 one-time)**; store
  listings, screenshots, privacy labels; every release goes through review.

**Risks:** App Store "minimum functionality" pushback on a thin WebView (mitigate
with real native share/push/biometric), cert renewals, two build toolchains.

**80/20 first slice:** an iOS-only Capacitor wrapper that adds only
push-when-closed and a share-extension (the two real iOS PWA pain points).

---

## B. AI: ask-your-notes / summarize  (effort: Med, ~few days)

**Why:** now table stakes (Notion AI, Evernote AI). Fits the self-hosted ethos as
a bring-your-own-key feature; clean Pro gate.

**Approach:** bring-your-own-key, **server-proxied**. Keys never reach the
browser; locked/encrypted note bodies are never sent.

**What it takes:**
- **Setting:** provider (Anthropic/OpenAI) + API key in admin settings (reuse the
  `class-noodled-settings.php` tab pattern + options storage). Pro-gateable.
- **REST (authed, mirrors existing handlers):**
  - `POST /ai/summarize { note_id }` — summarize the open note.
  - `POST /ai/ask { query }` — answer across the user's notebooks. The server
    retrieves candidate notes via the existing `Noodled_Notes::search()` /
    `bodies()`, caps the context window, then calls the provider with
    `wp_remote_post`. Exclude any `noodled:enc:v1:` (locked) bodies.
- **Frontend:** a ✨ button in the editor toolbar (summarize) and an
  "Ask your notes" modal (reuse the `showStats` / `showCaptainsLog` modal pattern).
  Spinner, or stream tokens.
- Implement with the **/claude-api** workflow (prompt caching, current model IDs).

**Risks:** privacy messaging (opt-in, states exactly what leaves the server),
token/context limits (retrieval, never dump-everything), per-user cost on their
own key.

---

## C. Plugin / extension system  (effort: High)

**Why:** Obsidian's 4,300+ plugins and Joplin's plugin platform are their primary
moat and power-user lock-in. Lets noodled grow without core bloat.

**Approach:** a small **client-side hook bus**, mirroring WordPress hooks in JS,
so add-ons extend noodled without editing core.

**What it takes:**
- `window.noodled.hooks` with `addAction / doAction / addFilter / applyFilters`,
  plus registration for: slash-menu items, editor-toolbar buttons, menu-dashboard
  items, note-context-menu items, a markdown render filter, and lifecycle events
  (`noteOpened`, `noteSaved`).
- The real work is **refactoring the currently hardcoded builders to emit through
  the registry**: the `renderMenuDashboard()` section arrays, the slash menu, the
  `showNoteContext()` items, and the `renderContent()` toolbar.
- **Loading extensions:** an admin "Extensions" setting listing trusted script URLs
  (or uploads), enqueued after `noodled.js`. **v1 is admin-only** — arbitrary JS in
  the app context is powerful, so document the trust boundary clearly.
- Optionally expose a few server-side `do_action` / `apply_filters` hooks for
  REST/data extensions.

**Risks:** security (arbitrary JS), API stability/versioning, scope creep. Ship a
minimal surface first (slash menu + toolbar + render filter), then grow.

---

## D. Canvas / whiteboard  (effort: High)

**Why:** Obsidian Canvas and Notion boards are increasingly expected for visual
thinkers; differentiates from the plain-list rivals.

**Approach:** a full-screen infinite board of draggable cards (notes, text,
images) with connectors. Persist the canvas **as a note** (JSON in a fenced
sentinel block, like the lock / code-note / table round-trips) so it syncs via
GitHub like everything else.

**What it takes:**
- Data model: nodes `{ type, id, x, y, w, h, ref }` + edges, stored as a
  `noodled:canvas:v1:` block in a note body (round-trips through markdown and
  GitHub sync untouched).
- Render: a pan/zoom DOM/SVG layer (lazy-load a small lib from a CDN per the
  existing mermaid/katex pattern, or hand-roll with CSS transforms); drag nodes,
  draw edges; embed live note cards by reusing the **transclusion** renderer.
- Entry: a "New canvas" menu item; open canvases like notes; a minimal toolbar
  (add card, link, delete).
- Mobile pan/zoom touch handling. Optional bonus: read Obsidian `.canvas` JSON for
  interop.

**Risks:** a distinct interaction surface, mobile touch handling, performance with
many nodes. Start with place-cards-and-link, then grow.

---

## Smaller remaining items (post-four)

- **Transparent at-rest encryption** (all notes, server-held key) layered under the
  existing opt-in lock-a-note. High effort; breaks server-side search, so it needs
  a search-index rethink.
- **Public publishing** of selected notes (reuse the existing public legal-page
  plumbing). Med.
- **OCR / image + PDF text search** (Evernote's archive superpower). High; declared
  a non-goal today.
