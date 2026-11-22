# Noodled iOS — Scope (Capacitor wrap, 80/20 first slice)

_Scoped 2026-06-19. Source: ROADMAP.md §A. Supersedes the root `NOODLE_MOBILE_PLAN.md`
(that doc describes an older, abandoned approach — a from-scratch React Native/Expo
app reading GitHub directly; we are NOT rebuilding the app)._

## 1. Goal & thesis

Ship a thin **iOS app that wraps the existing hosted PWA** (`noodled.ca/noodled`) in a
Capacitor WebView. We do **not** rebuild the UI — the SPA already is the app. The
wrapper exists only to fix the two things iOS PWAs genuinely can't do, which is why
people ask for a "real app":

1. **Push notifications when the app is closed** (iOS Safari/PWA push is gated and
   unreliable; reminders are a core feature).
2. **A system Share-extension** ("Share → Noodled" from any app creates a note).

Everything else (notes, editor, sync, offline, search, passkeys) already works in the
WebView because it's the same web app. That's the 80/20: ~90% of "native" value for a
fraction of a rebuild.

**Explicitly out of scope for this slice:** Android (follows later, same shell),
native editor, native offline store, biometric-gated app lock, widgets, Apple
Watch. Listed in §9 as fast-follows.

## 2. What already works in our favor

| Need | Already shipped | Gap for native |
|---|---|---|
| App UI | The PWA at `/noodled` (`assets/manifest.json`, `assets/sw.js`) | none — load it in the WebView |
| Reminders backend | `class-noodled-push.php` (VAPID web push), `push_subs` table, `noodled_push_check` cron, `/push/*` REST | add an **APNs** send path + store device tokens alongside VAPID subs |
| "Create note from elsewhere" | token-authed `/clip` + `/clip/file` endpoints (web clipper + drop folder) | a native **share extension** that POSTs to `/clip`/`/clip/file` |
| Auth in a WebView | magic-link / PIN already cookie-based; works in a WebView | **universal links** so the emailed magic link reopens the app; optionally bridge passkeys to native WebAuthn |
| Camera / files | web file input works in WebView | native camera/share is nicer but not required for v1 |

The backend lift is small and additive — no rewrite, just an APNs path next to the
existing VAPID one and an Apple App Site Association file.

## 3. The slice — in / out

**In (v1, iOS only):**
- Capacitor shell loading the hosted app (remote URL, so ordinary plugin deploys keep
  updating the app — no App Store review per content change).
- **Push when closed** via APNs (`@capacitor/push-notifications`): register device
  token → store server-side → reminder cron fans out APNs alongside VAPID.
- **Share extension**: "Share → Noodled" for text/URL (and a file → `/clip/file`),
  reusing the existing token auth.
- **Universal links** (`applinks`) so magic-link sign-in reopens the app, not Safari.
- Safe-area / status-bar polish so the WebView isn't clipped by the notch.

**Out (deliberately deferred):** Android, native editor/offline, biometric app-lock,
widgets, push rich media, in-app purchase. None block v1.

## 4. Backend changes (in `noodled-wp`, additive)

1. **Device-token storage.** Extend the push layer to accept an `apns` subscription
   type. Either add a `platform`/`apns_token` column to `noodled_push_subs` or a
   sibling `noodled_apns_tokens` table (per-user, like `push_subs`). REST:
   `POST /push/register-apns { token }` (authed).
2. **APNs sender.** A small APNs client (token-based JWT auth: an Apple `.p8` key +
   key id + team id, stored in settings like the VAPID keys). In the existing
   `noodled_push_check` cron, when a reminder is due, send to **both** the user's web
   (VAPID) and native (APNs) subscriptions; mark consumed only on a successful
   delivery (mirror the current VAPID logic).
3. **Apple App Site Association.** Serve `/.well-known/apple-app-site-association`
   (JSON, no extension, correct MIME) listing the app's `appID` and the
   `/noodled*` paths, so universal links resolve. Add a tiny route (like the existing
   legal-page/manifest routes in `class-noodled-app.php`).
4. **Settings.** New "Mobile / Push (Apple)" fields: APNs Key ID, Team ID, Bundle ID,
   and the `.p8` key (store like the GitHub PAT — and encrypt at rest, see the open
   Low-severity finding). Admin-only.

No change to notes/auth/sync — the WebView reuses them as-is.

## 5. Mobile project (`noodled-mobile/`, new sibling repo)

```
noodled-mobile/
├── capacitor.config.ts      # server.url = https://noodled.ca/noodled, appId, scheme
├── ios/                     # generated Xcode project (committed)
├── src/                     # minimal: just bootstraps Capacitor plugins
└── ios/ShareExtension/      # native share extension target (Swift)
```

**Dependencies:** `@capacitor/core`, `@capacitor/ios`, `@capacitor/push-notifications`,
`@capacitor/app` (universal-link handling), `@capacitor/status-bar`,
`@capacitor/share`. The share **extension** is a native iOS target (Capacitor doesn't
provide one) — a thin Swift extension that grabs the shared text/URL/file and calls
`/clip` with the stored token (read from an App Group shared with the WebView).

**Why remote-URL Capacitor (not bundled web):** loading the hosted app means every
WordPress deploy updates the mobile app instantly, with no App Store review per
change. The tradeoff is the App Store "minimum functionality" risk (§7).

## 6. Phased plan (each phase has a verify gate)

1. **Shell boots** → Capacitor project wrapping `noodled.ca/noodled`; sign in via PIN in
   the WebView. _Verify: app opens, you can read/edit a note on a real iPhone._
2. **Safe-area + status bar** → notch/home-indicator padding correct in light/dark.
   _Verify: no clipped toolbar, no overlap on an iPhone with a notch._
3. **APNs push** → device registers token; backend stores it; a test reminder fires
   while the app is **fully closed**. _Verify: lock screen notification arrives;
   tapping it opens the reminded note (reuse the SW `notificationclick` deep-link)._
4. **Universal links** → AASA served; tapping an emailed magic link opens the app and
   signs in. _Verify: cold magic-link tap lands in the app, authenticated._
5. **Share extension** → "Share → Noodled" from Safari/Photos creates a note via
   `/clip`/`/clip/file`. _Verify: shared URL + selection appears as a note in the
   Inbox/Web Clips notebook._
6. **TestFlight** → signed build to your own device + a couple of testers.
   _Verify: installs and runs from TestFlight, not just a dev cable._

Phases 1–2 are a day; 3–5 are the real work; 6 is pipeline plumbing.

## 7. App Store review risk (the main external risk)

Apple rejects "thin WebView wrappers" under Guideline 4.2 (minimum functionality).
**Mitigation:** v1 ships **real native** push, share-extension, and universal links —
not just a WebView. That's usually enough to clear 4.2. Have a fallback narrative
(self-hosted notes app, native integrations) ready for review notes. If still
rejected, options: add native camera capture, or distribute via TestFlight/Ad-Hoc for
family-only use (no review needed for ≤100 devices).

## 8. Prerequisites & cost

- **Apple Developer Program** — $99/yr (required to install beyond a 7-day dev cert and
  to use APNs/TestFlight).
- **A Mac with Xcode** — required to build/sign/submit iOS (no cloud-only path for the
  share extension + APNs entitlements). Capacitor needs native Xcode; Expo's cloud
  build is not an option here because we use a custom share-extension target.
- APNs auth key (`.p8`) + Key ID + Team ID from the developer portal.
- Signing certs + provisioning profiles (managed by Xcode).

## 9. Fast-follows (after v1)

- **Android** — same Capacitor shell + FCM instead of APNs (the backend already
  abstracts "send to this user's devices"); Play Store is $25 one-time.
- Native camera/photo capture, biometric app-lock, share rich files, home-screen
  widget for today's reminders.

## 10. Effort estimate

- Backend (APNs sender + token storage + AASA + settings): **~1–1.5 days**.
- Capacitor shell + safe-area + universal links: **~0.5 day**.
- Share extension (native Swift target + App Group token plumbing): **~1 day** (the
  fiddliest part).
- Signing / TestFlight / store listing + review iteration: **~1 day + review wait**.
- **Total: ~1 working week of build, plus Apple review latency** and the $99 account.

## 11. Open decisions (need your call before building)

1. **Apple Developer account** — do you have one / willing to pay the $99/yr? (Hard
   blocker for APNs + TestFlight.)
2. **Mac access** — do you have a Mac for Xcode? (Required; affects whether this is
   feasible now or needs a cloud Mac.)
3. **iOS-only first, or build the Android shell in the same pass?** (Android is cheap
   once the backend abstracts device tokens, but doubles the store/testing surface.)
4. **Distribution** — public App Store (review risk) or family-only via TestFlight/
   Ad-Hoc (no review, ≤100 devices)? Changes the §7 risk entirely.

## Definition of done (v1)

A signed iOS build, installable via TestFlight, that: opens the hosted noodled app and
signs in; receives a reminder push while fully closed and deep-links to the note; opens
via an emailed magic link; and creates a note from the system Share sheet — with the
backend sending APNs alongside the existing web push.
