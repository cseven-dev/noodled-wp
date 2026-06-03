/* noodled for WordPress
   API adapter + UI logic */

// i18n: wp.i18n is guaranteed by the floor shim + bundle loaded before this file.
const { __, _n, _x, sprintf } = window.wp.i18n;

// ── API Adapter ──
const api = {
  _base: noodledConfig.apiBase,
  _nonce: noodledConfig.nonce,

  async _fetch(endpoint, options = {}) {
    const method = (options.method || 'GET').toUpperCase();
    let url = this._base + endpoint;
    // Cache-bust reads: a unique URL per request defeats SiteGround's dynamic
    // cache, Cloudflare, the service worker, and the browser HTTP cache — so a
    // user always gets their own fresh data, never a stale or another user's copy.
    if (method === 'GET') {
      this._cb = (this._cb || 0) + 1;
      url += (url.includes('?') ? '&' : '?') + '_cb=' + Date.now() + '-' + this._cb;
    }
    const headers = {
      'X-WP-Nonce': this._nonce,
      'Content-Type': 'application/json',
      'Cache-Control': 'no-cache',
      ...options.headers,
    };
    try {
      const res = await fetch(url, { ...options, headers, credentials: 'same-origin', cache: 'no-store' });
      setOnline(true);
      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        throw new Error(err.message || `API error ${res.status}`);
      }
      return res.json();
    } catch (e) {
      if (e.message === 'Failed to fetch' || e.name === 'TypeError') {
        setOnline(false);
      }
      throw e;
    }
  },

  get_notebooks()                        { return this._fetch('/notebooks'); },
  create_notebook(name)                  { return this._fetch('/notebooks', { method: 'POST', body: JSON.stringify({ name }) }); },
  rename_notebook(oldName, newName)       { return this._fetch('/notebooks/rename', { method: 'POST', body: JSON.stringify({ old_name: oldName, new_name: newName }) }); },
  delete_notebook(name)                  { return this._fetch('/notebooks/delete', { method: 'POST', body: JSON.stringify({ name }) }); },
  get_notes(notebook)                    { return this._fetch('/notes' + (notebook ? '?notebook=' + encodeURIComponent(notebook) : '')); },
  get_note(notebook, noteId)             { return this._fetch('/notes/' + noteId); },
  create_note(notebook, title, body)     { return this._fetch('/notes', { method: 'POST', body: JSON.stringify({ notebook, title, body: body || '' }) }); },
  save_note(notebook, noteId, title, body, opts) { const p = { title, body }; if (opts && opts.base) p.base_modified = opts.base; if (opts && opts.force) p.force = true; return this._fetch('/notes/' + noteId, { method: 'PUT', body: JSON.stringify(p) }); },
  delete_note(notebook, noteId)          { return this._fetch('/notes/' + noteId, { method: 'DELETE' }); },
  move_note(from, noteId, to)            { return this._fetch('/notes/' + noteId + '/move', { method: 'POST', body: JSON.stringify({ notebook: to }) }); },
  toggle_pin(notebook, noteId)           { return this._fetch('/notes/' + noteId + '/pin', { method: 'POST' }); },
  get_trash()                            { return this._fetch('/trash'); },
  restore_note(noteId)                   { return this._fetch('/trash/' + noteId + '/restore', { method: 'POST' }); },
  permanent_delete(noteId)               { return this._fetch('/trash/' + noteId, { method: 'DELETE' }); },
  empty_trash()                          { return this._fetch('/trash', { method: 'DELETE' }); },
  trash_count()                          { return this._fetch('/trash/count'); },
  search(query)                          { return this._fetch('/search?q=' + encodeURIComponent(query)); },
  get_backlinks(title)                   { return this._fetch('/backlinks?title=' + encodeURIComponent(title)); },
  get_bodies()                           { return this._fetch('/bodies'); },
  save_attachment(nb, noteId, name, b64) { return this._fetch('/attachments', { method: 'POST', body: JSON.stringify({ note_id: noteId, filename: name, data: b64 }) }); },
  delete_attachment(id)                  { return this._fetch('/attachments/' + id, { method: 'DELETE' }); },
  import_evernote(formData)              { return fetch(this._base + '/import/evernote', { method: 'POST', headers: { 'X-WP-Nonce': this._nonce }, credentials: 'same-origin', body: formData }).then(r => r.json()); },
  admin_users()                          { return this._fetch('/admin/users'); },
  admin_invite(email, name, role, drop)  { return this._fetch('/admin/users', { method: 'POST', body: JSON.stringify({ email, name, role, drop }) }); },
  admin_delete_user(id)                  { return this._fetch('/admin/users/' + id, { method: 'DELETE' }); },
  admin_approve(id)                      { return this._fetch('/admin/users/' + id + '/approve', { method: 'POST' }); },
  admin_send_pin(id)                     { return this._fetch('/admin/users/' + id + '/pin', { method: 'POST' }); },
  admin_set_drop(id, enabled)            { return this._fetch('/admin/users/' + id + '/drop', { method: 'POST', body: JSON.stringify({ enabled }) }); },
  get_config()                           { return this._fetch('/config'); },
  set_config(key, value)                 { return this._fetch('/config', { method: 'PUT', body: JSON.stringify({ key, value }) }); },
  get_version()                          { return Promise.resolve(noodledConfig.version); },
  sync_plaud()                           { return this._fetch('/plaud/sync', { method: 'POST' }); },
  git_push()                             { return this._fetch('/sync/push', { method: 'POST' }); },
  git_pull()                             { return this._fetch('/sync/pull', { method: 'POST' }); },
  git_status()                           { return this._fetch('/sync/status'); },
  // Sharing (user-to-user)
  notebook_shares(nbId)                  { return this._fetch('/notebooks/' + nbId + '/shares'); },
  notebook_unshare(nbId, email)          { return this._fetch('/notebooks/' + nbId + '/unshare', { method: 'POST', body: JSON.stringify({ email }) }); },
  note_shares(noteId)                    { return this._fetch('/notes/' + noteId + '/shares'); },
  note_share(noteId, email, canWrite)    { return this._fetch('/notes/' + noteId + '/shares', { method: 'POST', body: JSON.stringify({ email, access: canWrite ? 'write' : 'read' }) }); },
  note_unshare(noteId, email)            { return this._fetch('/notes/' + noteId + '/unshare', { method: 'POST', body: JSON.stringify({ email }) }); },
};

// ── State ──
let notebooks = [];
let notes = [];
let filteredNotes = [];
let activeNotebook = null;
let activeNote = null;
let viewingTrash = false;
let config = { theme: 'dark' };
let saveTimer = null;
let sortMode = 'modified'; // 'modified', 'created', 'alpha'
let searchQuery = '';
let showRawMarkdown = false;
let bulkMode = false;
let bulkSelected = new Set();
let readingMode = false;
let viewingStarred = false;

// ── Init ──
document.addEventListener('DOMContentLoaded', async () => {
  // Failsafe: always hide splash after 3 seconds
  setTimeout(() => {
    const s = document.getElementById('splash');
    if (s && !s.classList.contains('hidden')) {
      s.classList.add('hidden');
      console.warn('Noodled: splash timeout — API may be unreachable');
    }
  }, 3000);

  showLoadingSkeleton();

  // Start the independent reads immediately so they overlap the config round-trip
  // instead of running in series (config -> notebooks -> notes was 3 serial RTTs).
  const notesP = loadNotes().catch(e => console.error('Noodled: notes load failed', e.message));
  const trashP = updateTrashCount().catch(() => {});

  try {
    config = await api.get_config();
  } catch (e) {
    console.warn('Noodled: config load failed', e.message);
    config = { theme: 'dark' };
  }
  applyTheme(config.theme || 'dark');
  applyReadingPrefs();

  // Notebooks need config.notebook_order for the saved sort, so they follow config;
  // notes/trash were already in flight in parallel.
  await loadNotebooks().catch(e => console.error('Noodled: notebooks load failed', e.message));
  await Promise.allSettled([ notesP, trashP ]);

  document.getElementById('splash')?.classList.add('hidden');
  document.addEventListener('keydown', handleGlobalKey);

  // Restore last note
  restoreLastNote();

  // Auto-sync every 5 minutes
  startAutoSync(5);

  // Offline detection
  window.addEventListener('online', () => setOnline(true));
  window.addEventListener('offline', () => setOnline(false));
});

// ── Theme ──
const _systemDark = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
function effectiveTheme(mode) {
  if (mode === 'auto') return (_systemDark && _systemDark.matches) ? 'dark' : 'light';
  return mode === 'light' ? 'light' : 'dark';
}
function applyTheme(mode) {
  config.theme = mode;
  document.documentElement.setAttribute('data-theme', effectiveTheme(mode));
  const btn = document.getElementById('themeBtn');
  if (btn) {
    /* translators: %s is the current theme mode (dark, light, or auto) */
    btn.title = sprintf( __( 'Theme: %s (click to change)', 'noodled' ), mode );
    /* translators: %s is the current theme mode (dark, light, or auto) */
    btn.setAttribute('aria-label', sprintf( __( 'Theme: %s. Click to change.', 'noodled' ), mode ));
    const glyph = mode === 'auto' ? '&#9681;' : (mode === 'light' ? '&#9728;' : '&#9680;');
    btn.innerHTML = '<span aria-hidden="true">' + glyph + '</span>';
  }
}
// When in auto mode, follow live OS changes.
if (_systemDark) { try { _systemDark.addEventListener('change', () => { if (config.theme === 'auto') applyTheme('auto'); }); } catch (e) {} }

async function toggleTheme() {
  const next = config.theme === 'dark' ? 'light' : (config.theme === 'light' ? 'auto' : 'dark');
  applyTheme(next);
  /* translators: %s is the current theme mode (dark, light, or auto) */
  showToast(sprintf( __( 'Theme: %s', 'noodled' ), next ));
  try { await api.set_config('theme', next); } catch (e) {}
}

// ── Notebooks ──
async function loadNotebooks() {
  notebooks = await api.get_notebooks();
  // Apply saved order
  const order = config.notebook_order;
  if (order && order.length) {
    notebooks.sort((a, b) => {
      const ai = order.indexOf(a.name);
      const bi = order.indexOf(b.name);
      if (ai === -1 && bi === -1) return 0;
      if (ai === -1) return 1;
      if (bi === -1) return -1;
      return ai - bi;
    });
  }
  renderNotebooks();
}

const nbColors = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#f97316'];

function renderNotebooks() {
  const el = document.getElementById('nbList');
  el.innerHTML = notebooks.map((nb, i) => {
    const name = nb.name;
    // Display label may differ from the addressing name (e.g. a member's drop
    // folder shows as "Shared with {Admin}" but is still addressed by name).
    const label = nb.label || nb.name;
    const active = activeNotebook === name ? ' active' : '';
    const color = nbColors[i % nbColors.length];
    const shared = nb.access !== 'owner' ? '<span style="font-size:9px;color:var(--text-muted);margin-left:4px" title="' + escAttr(__( 'Shared with you', 'noodled' )) + '" aria-hidden="true">&#128279;</span><span class="sr-only">' + esc(__( '(shared with you)', 'noodled' )) + '</span>' : '';
    const readOnly = nb.access === 'read' ? '<span style="font-size:9px;color:var(--text-muted);margin-left:2px" title="' + escAttr(__( 'Read only', 'noodled' )) + '" aria-hidden="true">&#128274;</span><span class="sr-only">' + esc(__( '(read only)', 'noodled' )) + '</span>' : '';
    /* translators: %s is the notebook name */
    const nbAria = escAttr(sprintf( __( 'Notebook %s', 'noodled' ), label ));
    return `<div class="nb-item${active}" role="button" tabindex="0" aria-label="${nbAria}" onclick="selectNotebook('${esc(name)}')" oncontextmenu="event.preventDefault(); showNbContext(event, '${esc(name)}')">
      <span class="nb-color" style="background:${color}"></span>
      <span class="nb-name">${esc(label)}${shared}${readOnly}</span>
      <span class="count">${nb.count}</span>
    </div>`;
  }).join('');

  document.getElementById('nbAll').className = 'nb-all' + (activeNotebook === null && !viewingTrash && !viewingStarred ? ' active' : '');
  setupNotebookDrag();
}

async function selectNotebook(name) {
  viewingTrash = false;
  viewingStarred = false;
  activeNotebook = name;
  renderNotebooks();
  renderNoteListSkeleton(); // shimmer while the new notebook's notes load
  await loadNotes();
}

async function createNotebook() {
  const name = await showPrompt(__( 'New Notebook', 'noodled' ), __( 'Notebook name:', 'noodled' ));
  if (!name) return;
  await api.create_notebook(name);
  await loadNotebooks();
  selectNotebook(name);
}

function showNbContext(event, name) {
  const nb = notebooks.find(n => n.name === name);
  const items = [
    { label: __( 'Rename', 'noodled' ), action: () => renameNotebook(name) },
    { label: __( 'Set cover image', 'noodled' ), action: () => setNotebookCover(name) },
  ];
  if (nb && nb.access === 'owner') {
    items.push({ label: __( 'Share...', 'noodled' ), action: () => showShareDialog(nb) });
  }
  items.push({ sep: true });
  items.push({ label: __( 'Delete', 'noodled' ), danger: true, action: () => deleteNotebook(name) });
  showContextMenu(event, items);
}

async function showShareDialog(nb) {
  const el = document.getElementById('modalContainer');
  el.innerHTML = `
    <div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
      <div class="modal" style="min-width:380px">
        <h3>${esc(sprintf( /* translators: %s is the notebook name */ __( 'Share "%s"', 'noodled' ), nb.name ))}</h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">${esc(__( 'Enter their email to grant access', 'noodled' ))}</p>
        <input id="shareEmail" placeholder="${escAttr(__( 'Email address', 'noodled' ))}" aria-label="${escAttr(__( 'Email address to share with', 'noodled' ))}" autofocus>
        <div style="display:flex;gap:8px;margin-bottom:16px">
          <label style="font-size:12px;color:var(--text)"><input type="checkbox" id="shareWrite"> ${esc(__( 'Can edit', 'noodled' ))}</label>
        </div>
        <div class="modal-buttons">
          <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">${esc(__( 'Cancel', 'noodled' ))}</button>
          <button class="btn btn-sm btn-accent" onclick="doShare(${nb.id})">${esc(__( 'Share', 'noodled' ))}</button>
        </div>
        <div id="shareMsg" style="margin-top:8px;font-size:12px"></div>
        <div id="nbShareList" style="margin-top:14px"></div>
      </div>
    </div>
  `;
  document.getElementById('shareEmail').focus();
  renderNotebookShareList(nb.id);
}

async function doShare(notebookId) {
  const email = document.getElementById('shareEmail').value;
  const canWrite = document.getElementById('shareWrite').checked;
  const msg = document.getElementById('shareMsg');
  if (!email) { msg.textContent = __( 'Email required', 'noodled' ); msg.style.color = 'var(--red)'; return; }

  try {
    const r = await api._fetch('/share', {
      method: 'POST',
      body: JSON.stringify({ notebook_id: notebookId, email, can_write: canWrite })
    });
    if (r.error) { msg.textContent = r.error; msg.style.color = 'var(--red)'; }
    else { msg.textContent = __( 'Shared!', 'noodled' ); msg.style.color = 'var(--green)'; renderNotebookShareList(notebookId); }
  } catch (e) {
    /* translators: %s is the error message */
    msg.textContent = sprintf( __( 'Failed: %s', 'noodled' ), e.message ); msg.style.color = 'var(--red)';
  }
}

async function renderNotebookShareList(notebookId) {
  const list = document.getElementById('nbShareList');
  if (!list) return;
  const shares = await api.notebook_shares(notebookId);
  if (!Array.isArray(shares) || !shares.length) { list.innerHTML = '<div style="font-size:12px;color:var(--text-muted)">' + esc(__( 'Not shared with anyone yet.', 'noodled' )) + '</div>'; return; }
  list.innerHTML = '<div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">' + esc(__( 'Shared with', 'noodled' )) + '</div>' + shares.map(s =>
    `<div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:13px">
       <span>${esc(s.display_name || s.email)} <span style="color:var(--text-muted)">(${s.can_write == 1 ? esc(__( 'edit', 'noodled' )) : esc(__( 'read', 'noodled' ))})</span></span>
       <button class="btn btn-sm" onclick="removeNotebookShare(${notebookId}, '${esc(s.email)}')">${esc(__( 'Remove', 'noodled' ))}</button>
     </div>`).join('');
}

async function removeNotebookShare(notebookId, email) {
  await api.notebook_unshare(notebookId, email);
  renderNotebookShareList(notebookId);
}

// ── Note-level user sharing ──
function showNoteShareDialog(noteId) {
  const note = filteredNotes.find(n => n.id === noteId) || activeNote;
  const title = note ? note.title : __( 'note', 'noodled' );
  const el = document.getElementById('modalContainer');
  el.innerHTML = `
    <div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
      <div class="modal" style="min-width:400px">
        <h3>${esc(sprintf( /* translators: %s is the note title */ __( 'Share "%s"', 'noodled' ), title ))}</h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">${esc(__( 'Give another noodled user access to just this note.', 'noodled' ))}</p>
        <div style="display:flex;gap:8px;align-items:center">
          <input id="nShareEmail" placeholder="${escAttr(__( 'Email address', 'noodled' ))}" aria-label="${escAttr(__( 'Email address to share with', 'noodled' ))}" style="flex:1" autofocus>
          <label style="font-size:12px;white-space:nowrap"><input type="checkbox" id="nShareWrite"> ${esc(__( 'Can edit', 'noodled' ))}</label>
          <button class="btn btn-sm btn-accent" onclick="doNoteShare(${noteId})">${esc(__( 'Share', 'noodled' ))}</button>
        </div>
        <div id="nShareMsg" style="margin-top:8px;font-size:12px"></div>
        <div id="nShareList" style="margin-top:14px"></div>
        <div class="modal-buttons" style="margin-top:14px">
          <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">${esc(__( 'Close', 'noodled' ))}</button>
        </div>
      </div>
    </div>`;
  document.getElementById('nShareEmail').focus();
  renderNoteShareList(noteId);
}

async function renderNoteShareList(noteId) {
  const list = document.getElementById('nShareList');
  if (!list) return;
  const shares = await api.note_shares(noteId);
  if (!Array.isArray(shares) || !shares.length) { list.innerHTML = '<div style="font-size:12px;color:var(--text-muted)">' + esc(__( 'Not shared with anyone yet.', 'noodled' )) + '</div>'; return; }
  list.innerHTML = '<div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">' + esc(__( 'Shared with', 'noodled' )) + '</div>' + shares.map(s =>
    `<div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:13px">
       <span>${esc(s.display_name || s.email)} <span style="color:var(--text-muted)">(${s.can_write == 1 ? esc(__( 'edit', 'noodled' )) : esc(__( 'read', 'noodled' ))})</span></span>
       <button class="btn btn-sm" onclick="removeNoteShare(${noteId}, '${esc(s.email)}')">${esc(__( 'Remove', 'noodled' ))}</button>
     </div>`).join('');
}

async function doNoteShare(noteId) {
  const email = document.getElementById('nShareEmail').value.trim();
  const canWrite = document.getElementById('nShareWrite').checked;
  const msg = document.getElementById('nShareMsg');
  if (!email) { msg.textContent = __( 'Email required', 'noodled' ); msg.style.color = 'var(--red)'; return; }
  try {
    const r = await api.note_share(noteId, email, canWrite);
    if (r.error) { msg.textContent = r.error; msg.style.color = 'var(--red)'; return; }
    msg.textContent = __( 'Shared!', 'noodled' ); msg.style.color = 'var(--green)';
    document.getElementById('nShareEmail').value = '';
    renderNoteShareList(noteId);
  } catch (e) { /* translators: %s is the error message */ msg.textContent = sprintf( __( 'Failed: %s', 'noodled' ), e.message ); msg.style.color = 'var(--red)'; }
}

async function removeNoteShare(noteId, email) {
  await api.note_unshare(noteId, email);
  renderNoteShareList(noteId);
}

async function renameNotebook(name) {
  const newName = await showPrompt(__( 'Rename Notebook', 'noodled' ), __( 'New name:', 'noodled' ), name);
  if (!newName || newName === name) return;
  await api.rename_notebook(name, newName);
  if (activeNotebook === name) activeNotebook = newName;
  await loadNotebooks();
  await loadNotes();
}

async function deleteNotebook(name) {
  /* translators: %s is the notebook name */
  if (!(await showConfirm(sprintf( __( 'Delete notebook "%s" and all its notes?', 'noodled' ), name ), { danger: true, okLabel: __( 'Delete', 'noodled' ) }))) return;
  await api.delete_notebook(name);
  if (activeNotebook === name) activeNotebook = null;
  activeNote = null;
  await loadNotebooks();
  await loadNotes();
  renderContent();
}

// ── Notes ──
async function loadNotes() {
  _bodiesLoaded = false; // list responses are body-free; re-hydrate lazily on demand
  try {
    notes = await api.get_notes(activeNotebook);
    // Cache for offline reading (keyed by notebook scope).
    try { localStorage.setItem('noodled_notes_cache', JSON.stringify({ nb: activeNotebook, notes })); } catch (e) {}
  } catch (e) {
    // Offline / fetch failed → fall back to the last cached copy so notes stay readable.
    try {
      const c = JSON.parse(localStorage.getItem('noodled_notes_cache') || 'null');
      if (c && c.nb === activeNotebook && Array.isArray(c.notes)) { notes = c.notes; showToast(__( 'Offline — showing cached notes', 'noodled' )); }
      else throw e;
    } catch (_) { throw e; }
  }
  filterNotes();
}

// Note bodies are no longer shipped in the list (fast first paint). Features that
// genuinely need all content — search, tag cloud, link graph, stats — call this
// to hydrate `notes[]` with bodies once per load via one lean request.
let _bodiesLoaded = false;
let _bodiesPromise = null;
async function ensureBodies() {
  if (_bodiesLoaded) return;
  if (!_bodiesPromise) {
    _bodiesPromise = (async () => {
      try {
        const rows = await api.get_bodies();
        const byId = {};
        (rows || []).forEach(r => { byId[r.id] = r.body; });
        const merge = arr => arr.forEach(n => { if (n && n.body == null && byId[n.id] != null) n.body = byId[n.id]; });
        merge(notes); merge(filteredNotes);
        _bodiesLoaded = true;
      } catch (e) { /* offline / failed → features degrade to title+preview */ }
      finally { _bodiesPromise = null; }
    })();
  }
  return _bodiesPromise;
}

function filterNotes() {
  searchQuery = document.getElementById('searchInput').value.toLowerCase();
  const q = searchQuery.trim();
  if (q) {
    // Full-body search needs the bodies hydrated; fetch them once, then re-filter.
    if (!_bodiesLoaded) ensureBodies().then(() => { if (searchQuery.trim()) filterNotes(); });
    // Token-AND: every word must appear somewhere (title, body, or notebook),
    // so "dish pump" matches a note containing both words in any order. Until
    // bodies arrive this searches title/preview/notebook, then upgrades.
    const toks = q.split(/\s+/);
    filteredNotes = notes.filter(n => {
      const hay = ((n.title || '') + ' ' + (n.body || n.preview || '') + ' ' + (n.notebook || '')).toLowerCase();
      return toks.every(t => hay.includes(t));
    });
  } else {
    filteredNotes = [...notes];
  }
  filteredNotes.sort((a, b) => {
    if (a.pinned !== b.pinned) return b.pinned ? 1 : -1;
    if (sortMode === 'alpha') return (a.title || '').localeCompare(b.title || '');
    if (sortMode === 'created') return (b.created || '').localeCompare(a.created || '');
    const cmp = (b.modified || '').localeCompare(a.modified || '');
    return cmp !== 0 ? cmp : (b.created || '').localeCompare(a.created || '');
  });
  renderNoteList();
}

function onSearch() { updateSearchClear(); filterNotes(); }

// Light haptic tap on supported devices (mobile). Silently no-ops elsewhere.
function haptic(pattern) { try { if (navigator.vibrate) navigator.vibrate(pattern || 8); } catch (e) {} }

function cycleSort() {
  const modes = ['modified', 'created', 'alpha'];
  const labels = { modified: __( 'Modified', 'noodled' ), created: __( 'Created', 'noodled' ), alpha: __( 'A-Z', 'noodled' ) };
  sortMode = modes[(modes.indexOf(sortMode) + 1) % modes.length];
  document.getElementById('sortBtn').textContent = labels[sortMode];
  filterNotes();
}

// Wrap search-term matches in the open note's body with <mark>, scroll to the
// first, and clear them the moment the user starts editing. Marks serialize away
// harmlessly (htmlToMarkdown keeps the text, drops the tag), so it's safe.
function highlightSearchInBody() {
  const q = (searchQuery || '').trim();
  const el = document.getElementById('noteBody');
  if (!q || !el) return;
  const toks = q.split(/\s+/).filter(Boolean).map(t => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
  if (!toks.length) return;
  const test = new RegExp('(' + toks.join('|') + ')', 'i');
  const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, {
    acceptNode(n) {
      if (!n.nodeValue || !test.test(n.nodeValue)) return NodeFilter.FILTER_REJECT;
      const p = n.parentNode;
      if (!p || p.nodeName === 'MARK' || p.nodeName === 'SCRIPT' || p.nodeName === 'STYLE') return NodeFilter.FILTER_REJECT;
      return NodeFilter.FILTER_ACCEPT;
    }
  });
  const targets = [];
  let node;
  while ((node = walker.nextNode())) targets.push(node);
  const reg = new RegExp('(' + toks.join('|') + ')', 'gi');
  targets.forEach(textNode => {
    const s = textNode.nodeValue;
    const frag = document.createDocumentFragment();
    let last = 0, m;
    reg.lastIndex = 0;
    while ((m = reg.exec(s))) {
      if (m.index > last) frag.appendChild(document.createTextNode(s.slice(last, m.index)));
      const mk = document.createElement('mark');
      mk.className = 'search-hl';
      mk.textContent = m[0];
      frag.appendChild(mk);
      last = m.index + m[0].length;
      if (m.index === reg.lastIndex) reg.lastIndex++;
    }
    if (last < s.length) frag.appendChild(document.createTextNode(s.slice(last)));
    textNode.parentNode.replaceChild(frag, textNode);
  });
  const first = el.querySelector('.search-hl');
  if (first) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
  el.addEventListener('input', clearSearchHighlights, { once: true });
}

function clearSearchHighlights() {
  const el = document.getElementById('noteBody');
  if (!el) return;
  el.querySelectorAll('mark.search-hl').forEach(mk => {
    mk.parentNode.replaceChild(document.createTextNode(mk.textContent), mk);
  });
  el.normalize();
}

function highlightMatch(text, query) {
  if (!query) return esc(text);
  const escaped = esc(text);
  const re = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
  return escaped.replace(re, '<mark>$1</mark>');
}

// Bucket a note's date into a list section label.
function dateGroup(dateStr) {
  if (!dateStr) return __( 'Earlier', 'noodled' );
  const d = new Date(dateStr.replace(' ', 'T') + (dateStr.length <= 16 ? 'Z' : ''));
  if (isNaN(d)) return __( 'Earlier', 'noodled' );
  const now = new Date();
  const day = 86400000;
  const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
  const t = d.getTime();
  if (t >= startToday) return __( 'Today', 'noodled' );
  if (t >= startToday - day) return __( 'Yesterday', 'noodled' );
  if (t >= startToday - 7 * day) return __( 'This week', 'noodled' );
  if (t >= startToday - 30 * day) return __( 'This month', 'noodled' );
  return __( 'Earlier', 'noodled' );
}

function renderNoteList() {
  const el = document.getElementById('noteList');
  setupNoteSwipe();
  const countEl = document.getElementById('noteCount');
  const q = (document.getElementById('searchInput') || {}).value || '';
  const searching = !!q.trim();

  const setCount = (n) => { if (countEl) countEl.textContent = searching
    ? sprintf( _n( '%d result', '%d results', n, 'noodled' ), n )
    : sprintf( _n( '%d note', '%d notes', n, 'noodled' ), n ); };

  if (!filteredNotes.length) {
    el.innerHTML = emptyListHtml(q.trim());
    setCount(0);
    return;
  }

  const cover = activeNotebook ? getNotebookCover(activeNotebook) : '';
  const coverHtml = cover ? `<div class="nb-cover" style="background-image:url('${cover}')"></div>` : '';
  // Group by date when browsing (not searching, default sort, not trash).
  const grouped = !searchQuery && sortMode === 'modified' && !viewingTrash;
  let lastGroup = null;
  const out = [];
  filteredNotes.forEach(n => {
    if (grouped) {
      const g = n.pinned ? __( 'Pinned', 'noodled' ) : dateGroup(n.modified || n.created);
      if (g !== lastGroup) { lastGroup = g; out.push(`<div class="note-group">${esc(g)}</div>`); }
    }
    out.push(renderNoteItem(n));
  });
  el.innerHTML = coverHtml + out.join('');
  setCount(filteredNotes.length);
}

// Context-aware empty state for the note list.
function emptyListHtml(query) {
  if (query) {
    return `<div class="empty-state list-empty">
      <div class="empty-icon" aria-hidden="true">&#128269;</div>
      <div class="empty-title">${esc(__( 'No matching notes', 'noodled' ))}</div>
      <div class="empty-sub">${esc(sprintf( __( 'Nothing matches "%s".', 'noodled' ), query ))}</div>
      <button class="btn btn-sm" style="margin-top:12px" onclick="clearSearch()">${esc(__( 'Clear search', 'noodled' ))}</button>
    </div>`;
  }
  if (viewingTrash) {
    return `<div class="empty-state list-empty"><div class="empty-icon" aria-hidden="true">&#128465;</div><div class="empty-title">${esc(__( 'Trash is empty', 'noodled' ))}</div><div class="empty-sub">${esc(__( 'Deleted notes show up here.', 'noodled' ))}</div></div>`;
  }
  const where = activeNotebook
    /* translators: %s is the notebook name */
    ? sprintf( __( 'No notes in %s yet', 'noodled' ), activeNotebook )
    : __( 'No notes yet', 'noodled' );
  return `<div class="empty-state list-empty">
    <div class="empty-icon" aria-hidden="true">&#127837;</div>
    <div class="empty-title">${esc(where)}</div>
    <div class="empty-sub">${esc(__( 'Create your first note to get started.', 'noodled' ))}</div>
    <button class="btn btn-sm btn-accent" style="margin-top:12px" onclick="createNote()">${esc(__( '+ New note', 'noodled' ))}</button>
  </div>`;
}

// ── Swipe actions on note rows (mobile): right = pin, left = delete ──
let _swipe = null;
function setupNoteSwipe() {
  const list = document.getElementById('noteList');
  if (!list || list._swipeWired) return;
  list._swipeWired = true;
  list.addEventListener('touchstart', (e) => {
    const row = e.target.closest('.note-item');
    if (!row || bulkMode) { _swipe = null; return; }
    const t = e.touches[0];
    _swipe = { row, id: +row.dataset.noteId, x0: t.clientX, y0: t.clientY, dx: 0, active: false };
  }, { passive: true });
  list.addEventListener('touchmove', (e) => {
    if (!_swipe) return;
    const t = e.touches[0];
    const dx = t.clientX - _swipe.x0, dy = t.clientY - _swipe.y0;
    if (!_swipe.active) {
      if (Math.abs(dy) > 10 && Math.abs(dy) >= Math.abs(dx)) { _swipe = null; return; } // vertical scroll wins
      if (Math.abs(dx) > 12 && Math.abs(dx) > Math.abs(dy) * 1.4) _swipe.active = true;
      else return;
    }
    _swipe.dx = dx;
    const clamped = Math.max(-110, Math.min(110, dx));
    _swipe.row.style.transition = 'none';
    _swipe.row.style.transform = `translateX(${clamped}px)`;
    _swipe.row.classList.toggle('swipe-del', dx < -28);
    _swipe.row.classList.toggle('swipe-pin', dx > 28);
    e.preventDefault();
  }, { passive: false });
  const end = () => {
    if (!_swipe) return;
    const { row, id, dx, active } = _swipe;
    _swipe = null;
    row.style.transition = '';
    row.style.transform = '';
    row.classList.remove('swipe-del', 'swipe-pin');
    if (!active) return;
    if (dx <= -72) { haptic([10, 30, 10]); deleteNote(id); }
    else if (dx >= 72) { togglePin(id); }
  };
  list.addEventListener('touchend', end);
  list.addEventListener('touchcancel', end);
}

// Shimmer placeholder rows shown while a notebook's notes load.
function renderNoteListSkeleton() {
  const el = document.getElementById('noteList');
  if (!el) return;
  let rows = '';
  for (let i = 0; i < 7; i++) {
    rows += '<div class="skel-row"><div class="skel-line skel-title"></div><div class="skel-line skel-meta"></div><div class="skel-line skel-prev"></div></div>';
  }
  el.innerHTML = rows;
}

function clearSearch() {
  const inp = document.getElementById('searchInput');
  if (inp) inp.value = '';
  searchQuery = '';
  updateSearchClear();
  filterNotes();
}

function updateSearchClear() {
  const inp = document.getElementById('searchInput');
  const btn = document.getElementById('searchClear');
  if (inp && btn) btn.hidden = !inp.value.trim();
}

function renderNoteItem(n) {
  {
    // The server ships a ready-made preview + task counts so the list never
    // carries (or re-parses) full note bodies. Fall back to body if present
    // (e.g. a freshly-saved note merged in client-side).
    const preview = (n.preview != null && n.preview !== '')
      ? n.preview
      : (n.body || '').replace(/[#>*`_~|\[\]]/g, '').replace(/\s+/g, ' ').trim().substring(0, 160);
    const isActive = activeNote && activeNote.id === n.id;
    const badge = n.source === 'plaud' ? '<span class="source-badge">plaud</span>' : '';
    const pin = n.pinned ? '<span style="margin-right:4px;font-size:11px" title="' + escAttr(__( 'Pinned', 'noodled' )) + '">&#128204;</span>' : '';
    const star = isStarred(n.id) ? '<span style="margin-right:4px;font-size:11px;color:#f59e0b" title="' + escAttr(__( 'Starred', 'noodled' )) + '">&#9733;</span>' : '';
    const sharedBadge = n.shared ? '<span style="margin-right:4px;font-size:11px" title="' + escAttr(__( 'Shared with you', 'noodled' )) + '">&#128279;</span>' : '';
    /* translators: %d is the number of attachments */
    const attBadge = (n.att > 0) ? `<span class="att-badge" title="${escAttr(sprintf( _n( '%d attachment', '%d attachments', n.att, 'noodled' ), n.att ))}">&#128206; ${n.att}</span>` : '';
    // Task counts come from the server (n.tasks); fall back to scanning the body
    // only if it happens to be present.
    const tasks = (n.tasks && n.tasks.total > 0) ? n.tasks : (n.body ? checklistProgress(n.body) : null);
    /* translators: %1$d is completed tasks, %2$d is total tasks */
    const taskBadge = tasks ? `<span class="att-badge task-badge ${tasks.done === tasks.total ? 'all-done' : ''}" title="${escAttr(sprintf( __( '%1$d of %2$d tasks done', 'noodled' ), tasks.done, tasks.total ))}">&#10003; ${tasks.done}/${tasks.total}</span>` : '';
    const time = relativeTime(n.modified || n.created);
    const fullDate = n.modified || n.created || '';
    /* translators: %s is the note title */
    const checkbox = bulkMode ? `<input type="checkbox" class="bulk-cb" aria-label="${escAttr(sprintf( __( 'Select note: %s', 'noodled' ), n.title || __( 'Untitled', 'noodled' ) ))}" ${bulkSelected.has(n.id) ? 'checked' : ''} onclick="toggleBulkSelect(${n.id}, event)">` : '';
    const noteColor = getNoteColor(n.id);
    const colorStyle = noteColor ? `border-left:3px solid ${noteColor}` : '';
    // In bulk mode the row contains a real checkbox, so it must NOT be a role=button
    // (a button can't contain interactive descendants); the checkbox is the control.
    /* translators: %s is the note title */
    const rowRole = bulkMode ? '' : `role="button" tabindex="0" aria-label="${escAttr(sprintf( __( 'Open note: %s', 'noodled' ), n.title || __( 'Untitled', 'noodled' ) ))}"`;
    return `
      <div class="note-item ${isActive ? 'active' : ''}" data-note-id="${n.id}" style="${colorStyle}"
           ${rowRole}
           onclick="${bulkMode ? `toggleBulkSelect(${n.id}, event)` : `handleNoteTap(event, ${n.id})`}"
           oncontextmenu="event.preventDefault(); showNoteContext(event, ${n.id})">
        <div class="title">${checkbox}${pin}${star}${sharedBadge}${highlightMatch(n.title, searchQuery)}${badge}</div>
        <div class="meta">
          <span title="${escAttr(fullDate)}">${esc(time)}</span>
          <span>${esc(n.notebook)}</span>
          ${attBadge}
          ${taskBadge}
        </div>
        <div class="preview">${highlightMatch(preview, searchQuery)}</div>
      </div>
    `;
  }
}

async function selectNote(noteId) {
  if (typeof dictating !== 'undefined' && dictating) stopDictation();
  // Show immediate loading feedback
  const item = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
  if (item) item.classList.add('loading');

  await doSave();
  try {
    activeNote = await api.get_note(null, noteId);
  } catch (e) {
    // Offline → read from the cached list copy (body only, no attachments).
    const c = notes.find(n => n.id === noteId);
    if (c) { activeNote = { ...c }; showToast(__( 'Offline — showing cached note', 'noodled' )); }
    else throw e;
  }
  trackRecentNote(noteId);
  renderNoteList();
  renderContent();
}

// Recently-viewed note stack (for the Ctrl-E quick switcher).
let _recentViewed = [];
function trackRecentNote(id) {
  _recentViewed = _recentViewed.filter(x => x !== id);
  _recentViewed.unshift(id);
  if (_recentViewed.length > 12) _recentViewed.length = 12;
}

// A localized "date and time it was started" title, used as the default for
// quick-add notes (new, upload, voice, location). The user can edit it after.
function dateTimeTitle() {
  try { return new Date().toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }); }
  catch (e) { return new Date().toLocaleString(); }
}

// Put the caret in the note body so typed/dictated text becomes note content,
// not the title. The title is pre-filled with the date/time and stays editable.
function focusNoteBody() {
  const raw = document.getElementById('noteBodyRaw');
  if (raw) { raw.focus(); return; }
  const body = document.getElementById('noteBody');
  if (body) {
    body.focus();
    const sel = window.getSelection();
    const r = document.createRange();
    r.selectNodeContents(body); r.collapse(false);
    sel.removeAllRanges(); sel.addRange(r);
  }
}

async function createNote() {
  await doSave();
  const nb = activeNotebook || 'General';
  const note = await api.create_note(nb, dateTimeTitle(), '');
  await loadNotebooks();
  await loadNotes();
  activeNote = note;
  renderNoteList();
  renderContent();
  document.querySelector('.col-content')?.classList.add('open');
  closeSidebar();
  setTimeout(focusNoteBody, 100);
}

// ── Quick-add speed dial (the mobile + button) ──────────────────────────────
// Opens a small menu: new note, photo/document, voice note, or location note.
// Every option creates a note titled with the date/time it was started and
// drops the user into the editor; the title stays editable afterwards.
function quickAddOpen() {
  const c = document.getElementById('quickAddContainer');
  if (!c || c.dataset.open === '1') return;
  haptic();
  const items = [
    { icon: '📝', label: __( 'New note', 'noodled' ),         sub: __( 'Blank note', 'noodled' ),        run: quickAddNote },
    { icon: '📎', label: __( 'Photo or document', 'noodled' ), sub: __( 'Upload files', 'noodled' ),      run: quickAddFiles },
    { icon: '🎙️', label: __( 'Voice note', 'noodled' ),        sub: __( 'Dictate to text', 'noodled' ),   run: quickAddVoice },
    { icon: '📍', label: __( 'Location note', 'noodled' ),     sub: __( 'Pin where you are', 'noodled' ), run: quickAddLocation },
  ];
  window._quickAddRun = items.map(it => it.run);
  c.innerHTML =
    '<div class="quick-add-scrim" id="quickAddScrim" onclick="quickAddClose()"></div>' +
    '<div class="quick-add-menu" id="quickAddMenu" role="menu" aria-label="' + escAttr(__( 'Add', 'noodled' )) + '">' +
      items.map((it, i) =>
        '<button class="quick-add-item" role="menuitem" onclick="quickAddPick(' + i + ')">' +
          '<span class="qa-icon" aria-hidden="true">' + it.icon + '</span>' +
          '<span class="qa-label"><span>' + esc(it.label) + '</span><span class="qa-sub">' + esc(it.sub) + '</span></span>' +
        '</button>'
      ).join('') +
    '</div>';
  c.dataset.open = '1';
  const btn = document.getElementById('quickAddBtn');
  if (btn) { btn.classList.add('open'); btn.setAttribute('aria-expanded', 'true'); }
  requestAnimationFrame(() => {
    document.getElementById('quickAddScrim')?.classList.add('show');
    document.getElementById('quickAddMenu')?.classList.add('show');
  });
  document.addEventListener('keydown', quickAddEsc);
}

function quickAddClose() {
  const c = document.getElementById('quickAddContainer');
  if (!c || c.dataset.open !== '1') return;
  c.dataset.open = '0';
  document.getElementById('quickAddScrim')?.classList.remove('show');
  document.getElementById('quickAddMenu')?.classList.remove('show');
  const btn = document.getElementById('quickAddBtn');
  if (btn) { btn.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
  document.removeEventListener('keydown', quickAddEsc);
  setTimeout(() => { if (c.dataset.open !== '1') c.innerHTML = ''; }, 240);
}

function quickAddEsc(e) { if (e.key === 'Escape') quickAddClose(); }

function toggleQuickAdd(e) {
  if (e) e.stopPropagation();
  const c = document.getElementById('quickAddContainer');
  if (c && c.dataset.open === '1') quickAddClose(); else quickAddOpen();
}

function quickAddPick(i) {
  const run = (window._quickAddRun || [])[i];
  quickAddClose();
  if (typeof run === 'function') run();
}

// Blank note titled with the date/time, straight into the editor.
function quickAddNote() { createNote(); }

// Voice note: new date/time note opened in the editor with dictation
// (speech → text) auto-started, so the mic is live as soon as it opens.
async function quickAddVoice() {
  await doSave();
  const nb = activeNotebook || 'General';
  const note = await api.create_note(nb, dateTimeTitle(), '');
  if (!note || note.error) { showToast(__( 'Could not create note', 'noodled' )); return; }
  activeNote = note;
  await loadNotebooks();
  await loadNotes();
  renderNoteList();
  renderContent();
  document.querySelector('.col-content')?.classList.add('open');
  closeSidebar();
  // Focus the body and begin listening once the editor is in the DOM.
  setTimeout(() => {
    focusNoteBody();
    if (typeof dictating !== 'undefined' && !dictating) toggleVoiceMemo();
  }, 250);
}

// Location note: capture the current GPS position and create a note with the
// coordinates plus an embedded, pinned map, then open it in the editor.
function quickAddLocation() {
  if (!navigator.geolocation) { showToast(__( 'Location is not available on this device', 'noodled' )); return; }
  showToast(__( 'Getting your location…', 'noodled' ));
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      const lat = Math.round(pos.coords.latitude * 1e6) / 1e6;
      const lng = Math.round(pos.coords.longitude * 1e6) / 1e6;
      createNoteFromLocation(lat, lng);
    },
    (err) => {
      if (err && err.code === err.PERMISSION_DENIED) showToast(__( 'Location access denied', 'noodled' ));
      else showToast(__( 'Could not get your location', 'noodled' ));
    },
    { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }
  );
}

async function createNoteFromLocation(lat, lng) {
  await doSave();
  const nb = activeNotebook || 'General';
  // A coordinates line plus a standalone OpenStreetMap link; the renderer turns
  // the link into an embedded map with a marker (see embedMedia).
  const mapUrl = 'https://www.openstreetmap.org/?mlat=' + lat + '&mlon=' + lng + '#map=16/' + lat + '/' + lng;
  /* translators: %1$s is latitude, %2$s is longitude */
  const body = sprintf( __( '📍 %1$s, %2$s', 'noodled' ), lat, lng ) + '\n\n' + mapUrl + '\n';
  const note = await api.create_note(nb, dateTimeTitle(), body);
  if (!note || note.error) { showToast(__( 'Could not create note', 'noodled' )); return; }
  activeNote = await api.get_note(null, note.id);
  await loadNotebooks();
  await loadNotes();
  renderNoteList();
  renderContent();
  document.querySelector('.col-content')?.classList.add('open');
  closeSidebar();
  setTimeout(focusNoteBody, 100);
}

function showNoteContext(event, noteId) {
  if (viewingTrash) {
    showContextMenu(event, [
      { label: __( 'Restore', 'noodled' ), action: () => restoreNote(noteId) },
      { sep: true },
      { label: __( 'Delete permanently', 'noodled' ), danger: true, action: () => permanentDelete(noteId) },
    ]);
    return;
  }

  const note = filteredNotes.find(n => n.id === noteId);
  const pinLabel = note && note.pinned ? __( 'Unpin', 'noodled' ) : __( 'Pin to top', 'noodled' );

  const moveItems = notebooks
    .filter(nb => !note || nb.name !== note.notebook)
    .map(nb => ({
      /* translators: %s is the notebook name */
      label: sprintf( __( 'Move to %s', 'noodled' ), nb.name ),
      action: () => moveNote(noteId, nb.name)
    }));

  const starLabel = isStarred(noteId) ? __( 'Unstar', 'noodled' ) : __( 'Star', 'noodled' );
  showContextMenu(event, [
    { label: pinLabel, action: () => togglePin(noteId) },
    { label: starLabel, action: () => toggleStar(noteId) },
    { label: __( 'Color', 'noodled' ), action: () => showColorPicker(noteId) },
    { label: __( 'Duplicate', 'noodled' ), action: () => duplicateNote(noteId) },
    { label: __( 'Open in split', 'noodled' ), action: () => openInSplit(noteId) },
    { label: __( 'Copy text', 'noodled' ), action: () => copyNoteText(noteId) },
    { label: __( 'Share with people…', 'noodled' ), action: () => showNoteShareDialog(noteId) },
    { label: __( 'Public link', 'noodled' ), action: () => shareNoteLink(noteId) },
    { sep: true },
    ...moveItems,
    { sep: true },
    { label: __( 'Delete', 'noodled' ), danger: true, action: () => deleteNote(noteId) },
  ]);
}

async function togglePin(noteId) {
  const note = notes.find(n => n.id === noteId) || filteredNotes.find(n => n.id === noteId);
  if (!note) return;
  const prev = !!note.pinned;
  // Optimistic: flip locally and re-sort instantly (notes/filteredNotes share
  // object refs), then reconcile with the server in the background.
  note.pinned = !prev;
  haptic();
  filterNotes();
  try { await api.toggle_pin(note.notebook, noteId); }
  catch (e) { note.pinned = prev; filterNotes(); showToast(__( 'Could not update pin', 'noodled' )); }
}

async function copyNoteText(noteId) {
  const note = await api.get_note(null, noteId);
  if (note.body) {
    await navigator.clipboard.writeText(note.body);
    showToast(__( 'Copied to clipboard', 'noodled' ));
  }
}

async function deleteNote(noteId) {
  const note = filteredNotes.find(n => n.id === noteId);
  if (!note) return;
  await api.delete_note(note.notebook, noteId);
  if (activeNote && activeNote.id === noteId) {
    activeNote = null;
    renderContent();
  }
  await loadNotebooks();
  await loadNotes();
  updateTrashCount();
  showUndoToast(__( 'Note moved to trash', 'noodled' ), () => undoDelete(noteId));
}

async function moveNote(noteId, toNotebook) {
  const note = notes.find(n => n.id === noteId) || filteredNotes.find(n => n.id === noteId);
  if (!note) return;
  const fromNotebook = note.notebook;
  if (fromNotebook === toNotebook) return;
  // Optimistic: drop the row from the current notebook view immediately (unless
  // we're in an All/Search view that still includes it), then reconcile.
  if (activeNotebook && activeNotebook !== toNotebook) {
    notes = notes.filter(n => n.id !== noteId);
  } else {
    note.notebook = toNotebook;
  }
  filterNotes();
  try {
    await api.move_note(fromNotebook, noteId, toNotebook);
    loadNotebooks(); // refresh notebook counts in the background
    /* translators: %s is the destination notebook name */
    showToast(sprintf( __( 'Moved to %s', 'noodled' ), toNotebook ));
    return;
  } catch (e) {
    showToast(__( 'Could not move note', 'noodled' ));
    await loadNotes();
  }
}

async function selectTrash() {
  viewingTrash = true;
  activeNotebook = null;
  activeNote = null;
  notes = await api.get_trash();
  filterNotes();
  renderContent();
  document.getElementById('nbAll').className = 'nb-all';
  document.querySelectorAll('.nb-item').forEach(el => el.classList.remove('active'));
  document.getElementById('nbTrash').classList.add('active');
}

async function restoreNote(noteId) {
  await api.restore_note(noteId);
  if (activeNote && activeNote.id === noteId) { activeNote = null; renderContent(); }
  notes = await api.get_trash();
  filterNotes();
  await updateTrashCount();
  await loadNotebooks();
  showToast(__( 'Restored', 'noodled' ));
}

async function permanentDelete(noteId) {
  if (!(await showConfirm(__( 'Permanently delete this note? This cannot be undone.', 'noodled' ), { danger: true, okLabel: __( 'Delete permanently', 'noodled' ) }))) return;
  await api.permanent_delete(noteId);
  if (activeNote && activeNote.id === noteId) { activeNote = null; renderContent(); }
  notes = await api.get_trash();
  filterNotes();
  await updateTrashCount();
}

async function updateTrashCount() {
  try {
    const count = await api.trash_count();
    document.getElementById('trashCount').textContent = count;
  } catch (e) {}
}

// ── Content rendering ──
function renderContent() {
  const el = document.getElementById('colContent');
  if (!activeNote) {
    el.innerHTML = '<div class="empty-state"><div class="icon">&#127837;</div><div>' + esc(__( 'Select a note or create a new one', 'noodled' )) + '</div></div>';
    return;
  }

  const n = activeNote;
  el.innerHTML = `
    <div class="content-toolbar">
      <input class="content-title-input" id="titleInput" value="${escAttr(n.title)}"
             placeholder="${escAttr(__( 'Note title', 'noodled' ))}" onchange="saveTitleOnly()" onkeydown="if(event.key==='Tab'){event.preventDefault();document.getElementById(showRawMarkdown?'noteBodyRaw':'noteBody')?.focus();}">
      <span class="save-indicator" id="saveIndicator"></span>
      <div class="editor-actions">
        <button class="btn btn-sm" onclick="insertBullet()" title="${escAttr(__( 'Bullet list', 'noodled' ))}" aria-label="${escAttr(__( 'Bullet list', 'noodled' ))}"><span aria-hidden="true">&#9679;</span></button>
        <button class="btn btn-sm" onclick="insertChecklistItem()" title="${escAttr(__( 'Checklist', 'noodled' ))}" aria-label="${escAttr(__( 'Checklist', 'noodled' ))}"><span aria-hidden="true">&#9744;</span></button>
        <button class="btn btn-sm" onclick="insertHeading()" title="${escAttr(__( 'Heading', 'noodled' ))}" aria-label="${escAttr(__( 'Heading', 'noodled' ))}">H</button>
        <span class="toolbar-divider" aria-hidden="true"></span>
        <button class="btn btn-sm" onclick="copyBody()" title="${escAttr(__( 'Copy', 'noodled' ))}" aria-label="${escAttr(__( 'Copy note', 'noodled' ))}"><span aria-hidden="true">&#128203;</span></button>
        <button class="btn btn-sm" onclick="uploadFiles()" title="${escAttr(__( 'Add files', 'noodled' ))}" aria-label="${escAttr(__( 'Add files', 'noodled' ))}"><span aria-hidden="true">&#128206;</span></button>
        <button class="btn btn-sm" onclick="toggleVoiceMemo()" id="voiceBtn" title="${escAttr(__( 'Dictate', 'noodled' ))}" aria-label="${escAttr(__( 'Dictate', 'noodled' ))}"><span aria-hidden="true">&#127908;</span></button>
        <span class="toolbar-divider" aria-hidden="true"></span>
        <div class="toolbar-menu-wrap">
          <button class="btn btn-sm" id="editorMenuBtn" onclick="toggleEditorMenu()" title="${escAttr(__( 'More tools', 'noodled' ))}" aria-label="${escAttr(__( 'More tools', 'noodled' ))}" aria-haspopup="true" aria-expanded="false"><span aria-hidden="true">&#8943;</span></button>
          <div class="toolbar-dropdown" id="editorMenu" role="menu" aria-labelledby="editorMenuBtn">
            <div class="dropdown-item" role="menuitem" tabindex="0" onclick="toggleMarkdownView()">${showRawMarkdown ? esc(__( 'Preview mode', 'noodled' )) : esc(__( 'Source mode', 'noodled' ))}</div>
            <div class="dropdown-item" role="menuitem" tabindex="0" onclick="toggleFindReplace()">${esc(__( 'Find & replace', 'noodled' ))}</div>
            <div class="dropdown-item" role="menuitem" tabindex="0" onclick="showTOC()">${esc(__( 'Table of contents', 'noodled' ))}</div>
            <div class="dropdown-item" role="menuitem" tabindex="0" onclick="exportNote()">${esc(__( 'Export as HTML', 'noodled' ))}</div>
            <div class="dropdown-item" role="menuitem" tabindex="0" onclick="downloadNoteMd()">${esc(__( 'Download as Markdown', 'noodled' ))}</div>
            <div class="dropdown-item" role="menuitem" tabindex="0" onclick="printNote()">${esc(__( 'Print / Save as PDF', 'noodled' ))}</div>
            <div class="dropdown-sep"></div>
            <div class="dropdown-item" role="menuitem" tabindex="0" onclick="toggleReadingMode()">${esc(__( 'Reading mode', 'noodled' ))}</div>
            <div class="dropdown-item" role="menuitem" tabindex="0" onclick="toggleTypewriter()">${esc(__( 'Typewriter mode', 'noodled' ))}</div>
            <div class="dropdown-item" role="menuitem" tabindex="0" onclick="toggleZenMode()">${esc(__( 'Zen mode', 'noodled' ))}</div>
            <div class="dropdown-sep"></div>
            <div class="dropdown-item" role="menuitem" tabindex="0" onclick="showHistory()">${esc(__( 'Version history', 'noodled' ))}</div>
            <div class="dropdown-item" role="menuitem" tabindex="0" onclick="setWordGoal()">${esc(__( 'Word count goal', 'noodled' ))}</div>
            <div class="dropdown-item" role="menuitem" tabindex="0" onclick="saveAsTemplate()">${esc(__( 'Save as template', 'noodled' ))}</div>
            <div class="dropdown-item" role="menuitem" tabindex="0" onclick="showReadingPrefs()">${esc(__( 'Reading preferences', 'noodled' ))}</div>
            <div class="dropdown-sep"></div>
            <div class="dropdown-item dropdown-danger" onclick="deleteCurrentNote()">${esc(__( 'Delete note', 'noodled' ))}</div>
          </div>
        </div>
      </div>
    </div>
    <div class="content-body" id="dropZone">
      ${showRawMarkdown
        ? `<textarea id="noteBodyRaw" class="raw-markdown" oninput="schedSave(); updateWordCount()">${escAttr(n.body || '')}</textarea>`
        : `<div class="rendered-content" id="noteBody" contenteditable="true"
               role="textbox" aria-multiline="true" aria-label="${escAttr(__( 'Note body', 'noodled' ))}"
               oninput="schedSave(); updateWordCount()" onkeydown="handleContentKey(event)"
               >${renderMarkdown(n.body)}</div>`
      }
    </div>
    <div class="editor-footer" id="editorFooter"></div>
  `;
  setupDropZone();
  setupLinkClicks();
  updateWordCount();
  renderBacklinks();
  renderAttachmentGallery();
  // Opened from a search? Briefly highlight the matches in the body.
  if ((searchQuery || '').trim()) setTimeout(highlightSearchInBody, 40);
  // Fade in transition
  el.classList.remove('note-fade');
  void el.offsetWidth; // trigger reflow
  el.classList.add('note-fade');
  // Remember last note + update page title
  if (activeNote) {
    localStorage.setItem('noodled_last_note', activeNote.id);
    document.title = activeNote.title + ' — ' + (noodledConfig.brandName || 'noodled');
  }
}

function setupLinkClicks() {
  const el = document.getElementById('noteBody');
  if (!el) return;
  el.addEventListener('click', e => {
    const a = e.target.closest('a[href]');
    if (a && !a.classList.contains('wikilink')) {
      e.preventDefault();
      if (/\.html?$/i.test(a.href)) {
        // Open HTML attachments in the same window (no top bar) — swipe back to return.
        window.location.href = a.href;
      } else {
        window.open(a.href, '_blank', 'noopener');
      }
    }
  });
}

function updateWordCount() {
  const el = document.getElementById('editorFooter');
  if (!el || !activeNote) return;
  const body = document.getElementById('noteBody') || document.getElementById('noteBodyRaw');
  if (!body) return;
  const text = body.innerText || body.value || '';
  const words = text.trim().split(/\s+/).filter(w => w.length > 0);
  const wordCount = words.length;
  const charCount = text.length;
  const readTime = Math.max(1, Math.ceil(wordCount / 200));
  const goals = config.word_goals || {};
  const goal = goals[activeNote.id];
  let goalText = '';
  if (goal) {
    const pct = Math.min(100, Math.round(wordCount / goal * 100));
    /* translators: %1$d is the percentage complete, %2$d is the word-count goal */
    goalText = ' \u00b7 ' + sprintf( __( '%1$d%% of %2$d goal', 'noodled' ), pct, goal );
  }
  // Checklist progress \u2014 read checkboxes straight from the DOM when rendered
  // (cheap on every keystroke), else fall back to parsing the markdown.
  let prog = null;
  const nb = document.getElementById('noteBody');
  if (nb) {
    const boxes = nb.querySelectorAll('.checklist input[type="checkbox"]');
    if (boxes.length) prog = { total: boxes.length, done: [...boxes].filter(b => b.checked).length };
  } else {
    prog = checklistProgress(activeNote.body || '');
  }
  /* translators: %1$d is the word count, %2$d is the character count, %3$d is the estimated read time in minutes */
  el.innerHTML = esc(sprintf( __( '%1$d words \u00b7 %2$d chars \u00b7 %3$d min read', 'noodled' ), wordCount, charCount, readTime )) + (goalText ? esc(goalText) : '');
  if (prog) {
    el.innerHTML += ` <span class="footer-tasks">\u00b7 \u2713 ${prog.done}/${prog.total}<span class="ftbar"><span style="width:${Math.round(prog.done / prog.total * 100)}%"></span></span></span>`;
  }
}

// Count checklist items in a markdown string \u2192 {done,total} or null.
function checklistProgress(md) {
  if (!md) return null;
  let total = 0, done = 0;
  (md.split('\n')).forEach(line => {
    const m = line.trim().match(/^-\s+\[([ xX])\]/);
    if (m) { total++; if (m[1].toLowerCase() === 'x') done++; }
  });
  return total ? { done, total } : null;
}

function setupDropZone() {
  const dz = document.getElementById('dropZone');
  if (!dz) return;
  dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drop-active'); });
  dz.addEventListener('dragleave', () => dz.classList.remove('drop-active'));
  dz.addEventListener('drop', e => { e.preventDefault(); dz.classList.remove('drop-active'); handleDrop(e); });
}

async function saveTitleOnly() {
  if (!activeNote) return;
  const title = document.getElementById('titleInput')?.value || activeNote.title;
  const body = htmlToMarkdown(document.getElementById('noteBody'));
  const result = await api.save_note(activeNote.notebook, activeNote.id, title, body);
  if (!result.error) {
    activeNote = result;
    await loadNotebooks();
    await loadNotes();
  }
}

// ── Keyboard handling in contenteditable ──
function handleContentKey(e) {
  if (e.ctrlKey && e.key === 'b') { e.preventDefault(); document.execCommand('bold'); schedSave(); return; }
  if (e.ctrlKey && e.key === 'i') { e.preventDefault(); document.execCommand('italic'); schedSave(); return; }

  if (e.key === 'Enter') {
    const sel = window.getSelection();
    if (!sel.rangeCount) return;
    let node = sel.anchorNode;
    let li = null, ul = null;
    while (node && node.id !== 'noteBody') {
      if (node.nodeName === 'LI') li = node;
      if (node.nodeName === 'UL' || node.nodeName === 'OL') ul = node;
      node = node.parentNode;
    }
    if (li && ul) {
      const isChecklist = ul.classList.contains('checklist');
      const text = li.textContent.trim();
      if (text === '') {
        e.preventDefault();
        const p = document.createElement('p');
        p.innerHTML = '<br>';
        ul.parentNode.insertBefore(p, ul.nextSibling);
        li.remove();
        if (ul.children.length === 0) ul.remove();
        const r = document.createRange();
        r.setStart(p, 0); r.collapse(true);
        sel.removeAllRanges(); sel.addRange(r);
        schedSave();
        return;
      }
      e.preventDefault();
      const newLi = document.createElement('li');
      if (isChecklist) {
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.onclick = function(evt) { evt.stopPropagation(); const md = htmlToMarkdown(document.getElementById('noteBody')); activeNote.body = md; doSave(); };
        const span = document.createElement('span');
        span.innerHTML = '<br>';
        newLi.appendChild(cb);
        newLi.appendChild(span);
      } else {
        newLi.innerHTML = '<br>';
      }
      if (li.nextSibling) ul.insertBefore(newLi, li.nextSibling); else ul.appendChild(newLi);
      const r = document.createRange();
      const target = isChecklist ? newLi.querySelector('span') : newLi;
      r.setStart(target, 0); r.collapse(true);
      sel.removeAllRanges(); sel.addRange(r);
      schedSave();
      return;
    }
  }
}

function insertBullet() {
  const el = document.getElementById('noteBody');
  if (!el) return;
  const li = document.createElement('li');
  li.textContent = __( 'item', 'noodled' );
  const sel = window.getSelection();
  let ul = document.createElement('ul');
  ul.appendChild(li);
  if (sel.rangeCount) { const range = sel.getRangeAt(0); range.collapse(false); range.insertNode(ul); }
  else el.appendChild(ul);
  const r = document.createRange();
  r.selectNodeContents(li);
  sel.removeAllRanges(); sel.addRange(r);
  schedSave();
}

function insertChecklistItem() {
  const el = document.getElementById('noteBody');
  if (!el) return;
  const md = htmlToMarkdown(el);
  activeNote.body = md + '\n- [ ] ';
  saveAndRerender();
}

function insertHeading() {
  const el = document.getElementById('noteBody');
  if (!el) return;
  const sel = window.getSelection();
  if (sel.rangeCount) {
    const range = sel.getRangeAt(0);
    let node = range.startContainer;
    while (node && node !== el && !['H1','H2','H3','P','DIV'].includes(node.nodeName)) node = node.parentNode;
    if (node && node !== el) {
      const tag = node.nodeName;
      let newTag = 'H1';
      if (tag === 'H1') newTag = 'H2'; else if (tag === 'H2') newTag = 'H3'; else if (tag === 'H3') newTag = 'P';
      const newEl = document.createElement(newTag);
      newEl.innerHTML = node.innerHTML;
      node.replaceWith(newEl);
      const r = document.createRange();
      r.selectNodeContents(newEl); r.collapse(false);
      sel.removeAllRanges(); sel.addRange(r);
      schedSave();
      return;
    }
  }
  const md = htmlToMarkdown(el);
  activeNote.body = md + '\n## ';
  saveAndRerender();
}

// save_note returns a note row WITHOUT its attachments array. Carry the existing
// one forward so the gallery doesn't vanish after an autosave / rerender.
function adoptSaved(result) {
  if (result && !result.error) {
    if (result.attachments === undefined && activeNote && activeNote.attachments !== undefined) {
      result.attachments = activeNote.attachments;
    }
    activeNote = result;
  }
  return result;
}

async function saveAndRerender() {
  const title = document.getElementById('titleInput')?.value || activeNote.title;
  const result = await api.save_note(activeNote.notebook, activeNote.id, title, activeNote.body);
  if (!result.error) { adoptSaved(result); await loadNotes(); }
  renderContent();
  setTimeout(() => {
    const el = document.getElementById('noteBody');
    if (el) { el.focus(); const r = document.createRange(); r.selectNodeContents(el); r.collapse(false); const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r); }
  }, 50);
}

function toggleToolbarMore() {
  document.querySelector('.content-toolbar')?.classList.toggle('expanded');
}

// Expose dropdown items as keyboard-operable menu items and focus the first.
function armDropdownMenu(menu) {
  menu.setAttribute('role', 'menu');
  const items = menu.querySelectorAll('.dropdown-item');
  items.forEach(it => { it.setAttribute('role', 'menuitem'); it.tabIndex = 0; });
  menu.querySelectorAll('.dropdown-sep').forEach(s => s.setAttribute('role', 'separator'));
  setTimeout(() => { if (items[0]) items[0].focus(); }, 0);
}

function toggleAppMenu() {
  const menu = document.getElementById('appMenu');
  if (menu) menu.classList.toggle('show');
  const open = !!menu?.classList.contains('show');
  document.getElementById('appMenuBtn')?.setAttribute('aria-expanded', open ? 'true' : 'false');
  // Close on next click
  if (open) {
    armDropdownMenu(menu);
    setTimeout(() => document.addEventListener('click', () => { menu.classList.remove('show'); document.getElementById('appMenuBtn')?.setAttribute('aria-expanded', 'false'); }, { once: true }), 10);
  }
}

function toggleEditorMenu() {
  const menu = document.getElementById('editorMenu');
  if (menu) menu.classList.toggle('show');
  const open = !!menu?.classList.contains('show');
  document.getElementById('editorMenuBtn')?.setAttribute('aria-expanded', open ? 'true' : 'false');
  if (open) {
    armDropdownMenu(menu);
    setTimeout(() => document.addEventListener('click', () => { menu.classList.remove('show'); document.getElementById('editorMenuBtn')?.setAttribute('aria-expanded', 'false'); }, { once: true }), 10);
  }
}

async function deleteCurrentNote() {
  if (!activeNote) return;
  if (typeof dictating !== 'undefined' && dictating) stopDictation();
  /* translators: %s is the note title */
  if (!(await showConfirm(sprintf( __( 'Delete "%s"?', 'noodled' ), activeNote.title ), { danger: true, okLabel: __( 'Delete', 'noodled' ) }))) return;
  const deletedId = activeNote.id;
  await api.delete_note(activeNote.notebook, activeNote.id);
  activeNote = null;
  renderContent();
  document.querySelector('.col-content')?.classList.remove('open');
  await loadNotebooks();
  await loadNotes();
  updateTrashCount();
  showUndoToast(__( 'Note moved to trash', 'noodled' ), () => undoDelete(deletedId));
  // Push to GitHub so delete syncs across devices — fire-and-forget so the UI
  // doesn't block on the (multi-second) shell-out.
  api.git_push().catch(() => {});
}

function toggleMarkdownView() {
  if (!activeNote) return;
  // Save current content first
  if (showRawMarkdown) {
    const raw = document.getElementById('noteBodyRaw');
    if (raw) activeNote.body = raw.value;
  } else {
    const el = document.getElementById('noteBody');
    if (el) activeNote.body = htmlToMarkdown(el);
  }
  showRawMarkdown = !showRawMarkdown;
  renderContent();
}

// ── Auto-save ──
function schedSave() {
  hasUnsavedChanges = true;
  clearTimeout(saveTimer);
  saveTimer = setTimeout(doSave, 2000);
}

async function doSave() {
  if (!activeNote || viewingTrash || _conflictOpen) return;
  saveHistory(activeNote);
  const title = document.getElementById('titleInput')?.value || activeNote.title;
  let body;
  if (showRawMarkdown) {
    const raw = document.getElementById('noteBodyRaw');
    if (!raw) return;
    body = raw.value;
  } else {
    const el = document.getElementById('noteBody');
    if (!el) return;
    body = htmlToMarkdown(el);
  }
  activeNote.body = body;
  // Pass the version we last saw so the server can flag a clobber (another
  // device edited this note since we loaded it).
  const base = activeNote.modified || '';
  const result = await queuedSave(activeNote.notebook, activeNote.id, title, body, base);
  if (result && result.conflict) { showConflict(result.server, { title, body }); return; }
  if (!result.error) {
    adoptSaved(result);
    // Update local note in array instead of full reload
    const idx = notes.findIndex(n => n.id === result.id);
    if (idx >= 0) {
      notes[idx] = { ...notes[idx], title: result.title, body: result.body, modified: result.modified };
    }
    // Update just the note item in the DOM
    const item = document.querySelector(`.note-item[data-note-id="${result.id}"]`);
    if (item) {
      const titleEl = item.querySelector('.title');
      const metaEl = item.querySelector('.meta span');
      if (titleEl) titleEl.lastChild.textContent = result.title;
      if (metaEl) metaEl.textContent = relativeTime(result.modified);
    }
  }
}

// ── HTML to Markdown converter ──
function htmlToMarkdown(el) {
  const lines = [];
  function walk(node) {
    if (node.nodeType === 3) { const t = node.textContent; if (t.trim()) lines.push(t); return; }
    if (node.nodeType !== 1) return;
    const tag = node.nodeName;
    if (tag === 'H1') { lines.push('# ' + node.textContent); return; }
    if (tag === 'H2') { lines.push('## ' + node.textContent); return; }
    if (tag === 'H3') { lines.push('### ' + node.textContent); return; }
    if (tag === 'HR') { lines.push('---'); return; }
    if (tag === 'BR') { lines.push(''); return; }
    if (tag === 'BLOCKQUOTE') { lines.push('> ' + node.textContent); return; }
    if (tag === 'PRE') { lines.push('```'); lines.push(node.textContent); lines.push('```'); return; }
    if (tag === 'UL' || tag === 'OL') {
      const isChecklist = node.classList.contains('checklist');
      let num = 1;
      for (const li of node.children) {
        if (li.nodeName !== 'LI') continue;
        if (isChecklist) {
          const cb = li.querySelector('input[type="checkbox"]');
          const checked = cb ? cb.checked : false;
          lines.push(`- [${checked ? 'x' : ' '}] ${li.textContent.trim()}`);
        } else if (tag === 'OL') { lines.push(`${num}. ${li.textContent.trim()}`); num++; }
        else lines.push(`- ${li.textContent.trim()}`);
      }
      return;
    }
    if (tag === 'P') { lines.push(inlineHtmlToMd(node)); return; }
    if (tag === 'IMG') { lines.push(`![${node.getAttribute('alt') || ''}](${node.getAttribute('src') || ''})`); return; }
    for (const child of node.childNodes) walk(child);
  }
  function inlineHtmlToMd(node) {
    let result = '';
    for (const child of node.childNodes) {
      if (child.nodeType === 3) { result += child.textContent; }
      else if (child.nodeType === 1) {
        const t = child.nodeName;
        const inner = inlineHtmlToMd(child);
        if (t === 'STRONG' || t === 'B') result += `**${inner}**`;
        else if (t === 'EM' || t === 'I') result += `*${inner}*`;
        else if (t === 'CODE') result += '`' + inner + '`';
        else if (t === 'A') {
          if (child.classList.contains('wikilink')) result += `[[${inner}]]`;
          else result += `[${inner}](${child.getAttribute('href') || ''})`;
        }
        else if (t === 'SPAN') result += inner;
        else if (t === 'BR') result += '\n';
        else if (t === 'IMG') result += `![${child.getAttribute('alt') || ''}](${child.getAttribute('src') || ''})`;
        else result += inner;
      }
    }
    return result;
  }
  for (const child of el.childNodes) walk(child);
  return lines.join('\n');
}

// ── File attachments ──
function fileToB64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result.split(',')[1]);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

// Shrink big phone photos client-side before upload: faster, smaller, and well
// under the 10 MB cap. (Re-encoding drops EXIF, so we only touch large rasters.)
async function compressImage(file) {
  if (!/^image\//.test(file.type) || /svg|gif/i.test(file.type)) return file;
  if (file.size < 1.2 * 1024 * 1024) return file; // small enough — keep original + EXIF
  try {
    const bitmap = await createImageBitmap(file);
    const max = 1800;
    let { width, height } = bitmap;
    if (width > max || height > max) {
      const s = Math.min(max / width, max / height);
      width = Math.round(width * s); height = Math.round(height * s);
    }
    const canvas = document.createElement('canvas');
    canvas.width = width; canvas.height = height;
    canvas.getContext('2d').drawImage(bitmap, 0, 0, width, height);
    if (bitmap.close) bitmap.close();
    const blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', 0.82));
    if (!blob || blob.size >= file.size) return file; // never inflate
    const name = file.name.replace(/\.(png|webp|bmp|heic|heif|tiff?|jpeg)$/i, '.jpg');
    return new File([blob], name, { type: 'image/jpeg' });
  } catch (e) { return file; }
}

let _tmpAttSeq = 0;
async function attachFile(file) {
  if (!activeNote) return;
  const note = activeNote;
  // Optimistic tile with a spinner so multi-file adds feel instant.
  const placeholder = { id: 'tmp-' + (++_tmpAttSeq), name: file.name, filename: file.name, _pending: true };
  (note.attachments = note.attachments || []).push(placeholder);
  if (activeNote === note) renderAttachmentGallery();
  try {
    const f = await compressImage(file);
    const b64 = await fileToB64(f);
    const result = await api.save_attachment(note.notebook, note.id, f.name, b64);
    const i = note.attachments.indexOf(placeholder);
    if (result && result.filename) {
      if (i >= 0) note.attachments[i] = result; else note.attachments.push(result);
      if (activeNote === note) { renderAttachmentGallery(); syncAttBadge(); }
      /* translators: %s is the attached file name */
      showToast(sprintf( __( 'Attached: %s', 'noodled' ), result.filename ));
    } else {
      if (i >= 0) note.attachments.splice(i, 1);
      if (activeNote === note) renderAttachmentGallery();
      showToast((result && result.error) || __( 'Upload failed', 'noodled' ));
    }
  } catch (e) {
    const i = note.attachments.indexOf(placeholder);
    if (i >= 0) note.attachments.splice(i, 1);
    if (activeNote === note) renderAttachmentGallery();
    showToast(__( 'Upload failed', 'noodled' ));
  }
}

async function handleDrop(e) {
  if (!activeNote || !e.dataTransfer.files.length) return;
  for (const file of e.dataTransfer.files) await attachFile(file);
}

// ── Add files: one flow for any document (images, PDFs, docs, HTML, …) ──
function uploadFiles() {
  const input = document.getElementById('filesInput');
  if (input) { input.value = ''; input.click(); }
}

// Attach to the open note; if none is open, create a note to hold them.
async function handleFiles(input) {
  const files = Array.from(input.files || []);
  if (!files.length) return;

  if (activeNote) {
    let ok = 0;
    for (const f of files) { await attachFile(f); ok++; }
    /* translators: %d is the number of files added */
    showToast(sprintf( _n( '%d file added', '%d files added', ok, 'noodled' ), ok ));
    return;
  }
  await createNoteFromFiles(files);
}

// Quick-add: always start a NEW note for the upload, even if one is already open.
function quickAddFiles() {
  const input = document.getElementById('quickFilesInput');
  if (input) { input.value = ''; input.click(); }
}
async function quickHandleFiles(input) {
  const files = Array.from(input.files || []);
  if (files.length) await createNoteFromFiles(files);
}

// Create a fresh note titled with the date/time it was started, upload every
// file into it, then drop the user straight into the editor to add text.
async function createNoteFromFiles(files) {
  await doSave();
  const nb = activeNotebook || 'General';
  /* translators: %d is the number of files being uploaded */
  showToast(sprintf( _n( 'Uploading %d file…', 'Uploading %d files…', files.length, 'noodled' ), files.length ));
  const note = await api.create_note(nb, dateTimeTitle(), '');
  if (!note || note.error) { showToast(__( 'Could not create note', 'noodled' )); return; }
  let ok = 0;
  for (const f of files) {
    try { const cf = await compressImage(f); const b64 = await fileToB64(cf); const r = await api.save_attachment(note.notebook, note.id, cf.name, b64); if (r && r.filename) ok++; } catch (e) {}
  }
  activeNote = await api.get_note(null, note.id);
  await loadNotebooks();
  await loadNotes();
  renderNoteList();
  renderContent();
  document.querySelector('.col-content')?.classList.add('open');
  closeSidebar();
  setTimeout(focusNoteBody, 100);
  /* translators: %d is the number of files added */
  showToast(sprintf( _n( '%d file added', '%d files added', ok, 'noodled' ), ok ));
}

// Delete an attachment (from a gallery × or the lightbox trash button).
async function deleteAttachment(id) {
  if (!(await showConfirm(__( 'Delete this attachment?', 'noodled' ), { danger: true, okLabel: __( 'Delete', 'noodled' ) }))) return;
  try { await api.delete_attachment(id); }
  catch (e) { showToast(__( 'Delete failed', 'noodled' )); return; }
  if (activeNote && activeNote.attachments) activeNote.attachments = activeNote.attachments.filter(a => a.id !== id);
  renderAttachmentGallery();
  syncAttBadge();
  showToast(__( 'Deleted', 'noodled' ));
}

// Keep the note-list paperclip count in sync after an attach/delete on the open
// note (the server att_count only refreshes on a full reload otherwise).
function syncAttBadge() {
  if (!activeNote) return;
  const c = (activeNote.attachments || []).length;
  for (const arr of [typeof notes !== 'undefined' ? notes : null, typeof filteredNotes !== 'undefined' ? filteredNotes : null]) {
    if (!arr) continue;
    const it = arr.find(n => n.id === activeNote.id);
    if (it) it.att = c;
  }
  renderNoteList();
}

// ── Evernote import (per-user, from the app) ──
function importEvernote() {
  const input = document.getElementById('enexImportInput');
  if (input) { input.value = ''; input.click(); }
}

async function handleEvernoteImport(input) {
  const file = input.files && input.files[0];
  if (!file) return;
  showToast(__( 'Importing from Evernote…', 'noodled' ));
  const fd = new FormData();
  fd.append('file', file);
  try {
    const res = await api.import_evernote(fd);
    /* translators: %s is the error message */
    if (res && res.error) { showToast(sprintf( __( 'Import failed: %s', 'noodled' ), res.error )); return; }
    await loadNotebooks();
    await loadNotes();
    const n = res.imported || 0;
    /* translators: %d is the number of notes imported */
    let m = sprintf( _n( 'Imported %d note', 'Imported %d notes', n, 'noodled' ), n );
    /* translators: %d is the number of notes skipped during import */
    if (res.skipped) m += ', ' + sprintf( _n( '%d skipped', '%d skipped', res.skipped, 'noodled' ), res.skipped );
    showToast(m);
  } catch (e) { showToast(__( 'Import failed', 'noodled' )); }
}

// ── Owner: manage people (mobile-friendly user management, owner-only) ──
function manageUsers() {
  const menu = document.getElementById('appMenu'); if (menu) menu.classList.remove('show');
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal" style="max-width:540px">
      <h3>${esc(__( 'People', 'noodled' ))}</h3>
      <div id="muBody" style="max-height:62vh;overflow:auto;margin-top:8px">${esc(__( 'Loading…', 'noodled' ))}</div>
      <div class="modal-buttons" style="margin-top:12px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">${esc(__( 'Close', 'noodled' ))}</button>
      </div>
    </div></div>`;
  renderManageUsers();
}

async function renderManageUsers() {
  const body = document.getElementById('muBody');
  if (!body) return;
  let users;
  try { users = await api.admin_users(); } catch (e) { body.textContent = __( 'Could not load people.', 'noodled' ); return; }
  if (users.error) { body.textContent = users.error; return; }
  const pending = users.filter(u => u.role === 'pending');
  const members = users.filter(u => u.role !== 'pending');
  let h = '';
  if (pending.length) {
    h += '<div class="mu-head">' + esc(__( 'Pending requests', 'noodled' )) + '</div>';
    pending.forEach(u => {
      h += `<div class="mu-row"><div class="mu-info"><b>${esc(u.display_name || u.email)}</b><span>${esc(u.email)}</span></div>
        <div class="mu-actions"><button class="btn btn-sm btn-accent" onclick="muApprove(${u.id})">${esc(__( 'Approve', 'noodled' ))}</button><button class="btn btn-sm" onclick="muRemove(${u.id})">${esc(__( 'Deny', 'noodled' ))}</button></div></div>`;
    });
  }
  h += '<div class="mu-head">' + esc(__( 'Members', 'noodled' )) + '</div>';
  members.forEach(u => {
    const drop = u.role === 'member'
      ? `<label class="mu-drop"><input type="checkbox" ${u.drop ? 'checked' : ''} onchange="muDrop(${u.id}, this.checked)"> ${esc(__( 'drop', 'noodled' ))}</label>` : '';
    /* translators: %s is the member name or email */
    const removeAria = escAttr(sprintf( __( 'Remove %s', 'noodled' ), u.display_name || u.email || __( 'member', 'noodled' ) ));
    h += `<div class="mu-row"><div class="mu-info"><b>${esc(u.display_name || u.email)}</b><span>${esc(u.email)} · ${esc(u.role)}</span><span class="mu-pin" id="mu-pin-${u.id}"></span></div>
      <div class="mu-actions">${drop}<button class="btn btn-sm" onclick="muPin(${u.id})">${esc(__( 'PIN', 'noodled' ))}</button><button class="btn btn-sm" aria-label="${removeAria}" onclick="muRemove(${u.id})">&#10005;</button></div></div>`;
  });
  h += `<div class="mu-head">${esc(__( 'Invite someone', 'noodled' ))}</div>
    <div class="mu-invite">
      <input id="muEmail" type="email" inputmode="email" placeholder="${escAttr(__( 'email', 'noodled' ))}" aria-label="${escAttr(__( 'Member email', 'noodled' ))}" class="login-input" style="margin:0">
      <input id="muName" type="text" placeholder="${escAttr(__( 'name (optional)', 'noodled' ))}" aria-label="${escAttr(__( 'Member name (optional)', 'noodled' ))}" class="login-input" style="margin:0">
      <div class="mu-invite-row">
        <label class="mu-drop"><input type="checkbox" id="muInviteDrop"> ${esc(__( 'drop folder', 'noodled' ))}</label>
        <button class="btn btn-sm btn-accent" onclick="muInvite()">${esc(__( 'Invite', 'noodled' ))}</button>
        <span id="muInviteStatus" class="mu-status"></span>
      </div>
    </div>`;
  body.innerHTML = h;
}

async function muApprove(id) { try { await api.admin_approve(id); } catch (e) { showToast(__( 'Failed', 'noodled' )); } renderManageUsers(); }
async function muRemove(id) { if (!(await showConfirm(__( 'Remove this person? Their notes stay but they lose access.', 'noodled' ), { danger: true, okLabel: __( 'Remove', 'noodled' ) }))) return; try { await api.admin_delete_user(id); } catch (e) { showToast(__( 'Failed', 'noodled' )); } renderManageUsers(); }
async function muDrop(id, on) { try { const r = await api.admin_set_drop(id, on); if (r && r.error) showToast(r.error); } catch (e) { showToast(__( 'Failed', 'noodled' )); } }
async function muPin(id) {
  const out = document.getElementById('mu-pin-' + id); if (out) out.textContent = '…';
  /* translators: %s is the generated PIN code */
  try { const d = await api.admin_send_pin(id); if (out) out.innerHTML = d.error ? esc(d.error) : (sprintf( __( ' PIN %s', 'noodled' ), '<b>' + esc(d.pin) + '</b>' ) + (d.emailed ? ' ✓' : '')); }
  catch (e) { if (out) out.textContent = __( 'failed', 'noodled' ); }
}
async function muInvite() {
  const email = document.getElementById('muEmail').value.trim();
  const name = document.getElementById('muName').value.trim();
  const drop = document.getElementById('muInviteDrop').checked;
  const st = document.getElementById('muInviteStatus');
  if (!email) { st.textContent = __( 'email?', 'noodled' ); return; }
  st.textContent = '…';
  let d;
  try { d = await api.admin_invite(email, name, 'member', drop); } catch (e) { st.textContent = __( 'failed', 'noodled' ); return; }
  if (d.error) { st.textContent = d.error; return; }
  document.getElementById('muEmail').value = '';
  document.getElementById('muName').value = '';
  document.getElementById('muInviteDrop').checked = false;
  st.textContent = __( 'invited ✓', 'noodled' );
  renderManageUsers();
}

// ── Checkbox toggle ──
async function toggleCheck(checkIndex) {
  if (!activeNote) return;
  haptic();
  const el = document.getElementById('noteBody');
  if (el) activeNote.body = htmlToMarkdown(el);
  const lines = activeNote.body.split('\n');
  // Find the nth checkbox line
  let count = 0;
  for (let i = 0; i < lines.length; i++) {
    if (/^-\s+\[[ xX]\]/.test(lines[i].trim())) {
      if (count === checkIndex) {
        if (/- \[ \]/.test(lines[i])) lines[i] = lines[i].replace('- [ ]', '- [x]');
        else lines[i] = lines[i].replace(/- \[[xX]\]/, '- [ ]');
        break;
      }
      count++;
    }
  }
  activeNote.body = lines.join('\n');
  await api.save_note(activeNote.notebook, activeNote.id, activeNote.title, activeNote.body);
  renderContent();
}

// ── Copy ──
async function copyBody() {
  if (!activeNote) return;
  const text = activeNote.body || '';
  try { await navigator.clipboard.writeText(text); showToast(__( 'Copied to clipboard', 'noodled' )); }
  catch { showToast(__( 'Copy failed', 'noodled' )); }
}

// ── Global keyboard shortcuts ──
function handleGlobalKey(e) {
  const tag = e.target.tagName;
  const isEditable = e.target.isContentEditable || tag === 'INPUT' || tag === 'TEXTAREA';
  if (e.ctrlKey && e.key === 'n') { e.preventDefault(); createNote(); return; }
  if (e.ctrlKey && e.key === 's') { e.preventDefault(); doSave(); showToast(__( 'Saved', 'noodled' )); return; }
  if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K') && !e.shiftKey) { e.preventDefault(); showCommandPalette(); return; }
  if (e.ctrlKey && e.shiftKey && (e.key === 'F' || e.key === 'f')) { e.preventDefault(); document.getElementById('searchInput').focus(); return; }
  if (e.ctrlKey && e.key === 'p') { e.preventDefault(); showQuickOpen(); return; }
  if (e.ctrlKey && e.key === 'j') { e.preventDefault(); openDailyJournal(); return; }
  if (e.ctrlKey && e.key === 'e') { e.preventDefault(); showRecentSwitcher(); return; }
  if (e.ctrlKey && e.key === '.') { e.preventDefault(); toggleQuickCapture(); return; }
  if (e.ctrlKey && e.key === 'h') { e.preventDefault(); toggleFindReplace(); return; }
  if (e.key === 'Escape' && readingMode) { toggleReadingMode(); return; }
  if (e.key === '?' && !isEditable) { e.preventDefault(); showShortcutsHelp(); return; }
}

// ── Quick-open ──
function showQuickOpen() {
  const el = document.getElementById('modalContainer');
  const allNotes = notes;
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}"><div class="modal" style="min-width:400px"><h3>${esc(__( 'Quick Open', 'noodled' ))}</h3><input id="qoInput" placeholder="${escAttr(__( 'Type to search notes...', 'noodled' ))}" aria-label="${escAttr(__( 'Search notes to open', 'noodled' ))}" autofocus><div class="quick-open-list" id="qoList"></div></div></div>`;
  let qoHighlight = 0, qoResults = [];
  function qoFilter() {
    const q = document.getElementById('qoInput').value.toLowerCase();
    qoResults = allNotes.filter(n => n.title.toLowerCase().includes(q)).slice(0, 10);
    qoHighlight = 0; qoRender();
  }
  function qoRender() {
    const list = document.getElementById('qoList');
    if (!qoResults.length) { list.innerHTML = '<div style="padding:8px;color:var(--text-muted);font-size:12px">' + esc(__( 'No matches', 'noodled' )) + '</div>'; return; }
    list.innerHTML = qoResults.map((n, i) => `<div class="quick-open-item ${i === qoHighlight ? 'highlight' : ''}" onclick="selectNote(${n.id}); document.getElementById('modalContainer').innerHTML='';"><span>${esc(n.title)}</span><span class="qo-notebook">${esc(n.notebook)}</span></div>`).join('');
  }
  const inp = document.getElementById('qoInput');
  inp.addEventListener('input', qoFilter);
  inp.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown') { e.preventDefault(); qoHighlight = Math.min(qoHighlight + 1, qoResults.length - 1); qoRender(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); qoHighlight = Math.max(qoHighlight - 1, 0); qoRender(); }
    else if (e.key === 'Enter' && qoResults.length) { e.preventDefault(); selectNote(qoResults[qoHighlight].id); document.getElementById('modalContainer').innerHTML = ''; }
    else if (e.key === 'Escape') { document.getElementById('modalContainer').innerHTML = ''; }
  });
  qoFilter(); inp.focus();
}

// ── Shortcuts help ──
function showShortcutsHelp() {
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}"><div class="modal"><h3>${esc(__( 'Keyboard Shortcuts', 'noodled' ))}</h3><div class="shortcuts-grid"><kbd>Ctrl+N</kbd> <span>${esc(__( 'New note', 'noodled' ))}</span><kbd>Ctrl+S</kbd> <span>${esc(__( 'Force save', 'noodled' ))}</span><kbd>Ctrl+P</kbd> <span>${esc(__( 'Quick open', 'noodled' ))}</span><kbd>Ctrl+Shift+F</kbd> <span>${esc(__( 'Focus search', 'noodled' ))}</span><kbd>Ctrl+B</kbd> <span>${esc(__( 'Bold', 'noodled' ))}</span><kbd>Ctrl+I</kbd> <span>${esc(__( 'Italic', 'noodled' ))}</span><kbd>?</kbd> <span>${esc(__( 'This help', 'noodled' ))}</span></div><div class="modal-buttons" style="margin-top:14px"><button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">${esc(__( 'Close', 'noodled' ))}</button></div></div></div>`;
}

// ── Markdown renderer ──
function renderMarkdown(text) {
  if (!text) return '';
  const lines = text.split('\n');
  const out = [];
  let inCodeBlock = false, codeBuffer = [], inList = false, listType = '', codeLang = '';

  function closeList() { if (inList) { out.push(listType === 'check' ? '</ul>' : `</${listType}>`); inList = false; listType = ''; } }
  function escLine(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  // Markdown URLs land in href/src attributes — block script-bearing schemes and
  // escape the quote char so a crafted URL can't break out of the attribute.
  // (s is already escLine'd, so &/</> are escaped; we only neutralise " here.)
  function safeUrl(u) {
    const t = u.trim().replace(/&amp;/g, '&'); // undo escLine for the scheme test
    if (/^(javascript|vbscript|file|data):/i.test(t) && !/^data:image\//i.test(t)) return '#';
    return u.trim().replace(/"/g, '&quot;');
  }
  function attrText(s) { return s.replace(/"/g, '&quot;'); }
  function inlineFormat(s) {
    return s
      .replace(/!\[([^\]]*)\]\(([^)]+)\)/g, (m, alt, url) => `<img src="${safeUrl(url)}" alt="${attrText(alt)}">`)
      .replace(/\[\[([^\]]+)\]\]/g, (match, title) => {
        const target = notes.find(n => n.title.toLowerCase() === title.toLowerCase().trim());
        if (target) return `<a href="#" class="wikilink" onclick="event.preventDefault(); selectNote(${target.id})">${esc(title)}</a>`;
        return `<a href="#" class="wikilink broken" onclick="event.preventDefault()">${esc(title)}</a>`;
      })
      .replace(/\[([^\]]+)\]\(([^)]+)\)/g, (m, txt, url) => `<a href="${safeUrl(url)}" target="_blank" rel="noopener noreferrer" title="${escAttr(__( 'Opens in a new tab', 'noodled' ))}">${txt}</a>`)
      // Autolink bare URLs (not already inside a markdown link's href/text).
      .replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, (m, pre, url) => `${pre}<a href="${url.replace(/"/g, '&quot;')}" target="_blank" rel="noopener noreferrer" title="${escAttr(__( 'Opens in a new tab', 'noodled' ))}">${url}</a>`)
      .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g, '<em>$1</em>')
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/\[(\d+:\d+(?::\d+)?)\]\s*/g, '<span class="timestamp">[$1]</span> ')
      /* translators: %s is the tag name */
      .replace(/(^|\s)#([a-zA-Z][\w-]*)/g, (m, pre, tag) => `${pre}<span class="tag" role="button" tabindex="0" aria-label="${escAttr(sprintf( __( 'Filter by tag %s', 'noodled' ), tag ))}" onclick="filterByTag('${tag}')">#${tag}</span>`);
  }

  for (let i = 0; i < lines.length; i++) {
    const raw = lines[i];
    if (raw.trimStart().startsWith('```')) {
      if (inCodeBlock) { out.push(codeBlockHtml(codeBuffer, codeLang)); codeBuffer = []; inCodeBlock = false; codeLang = ''; }
      else { closeList(); inCodeBlock = true; codeLang = raw.trim().slice(3).trim(); }
      continue;
    }
    if (inCodeBlock) { codeBuffer.push(raw); continue; }
    const trimmed = raw.trim();
    if (trimmed === '') { closeList(); out.push('<br>'); continue; }
    if (/^-{3,}$/.test(trimmed) || /^\*{3,}$/.test(trimmed)) { closeList(); out.push('<hr>'); continue; }
    const hMatch = trimmed.match(/^(#{1,3})\s+(.+)$/);
    if (hMatch) { closeList(); out.push(`<h${hMatch[1].length}>${inlineFormat(escLine(hMatch[2]))}</h${hMatch[1].length}>`); continue; }
    const checkMatch = trimmed.match(/^-\s+\[([ xX])\]\s*(.*)$/);
    if (checkMatch) {
      if (!inList || listType !== 'check') { closeList(); out.push('<ul class="checklist">'); inList = true; listType = 'check'; }
      const checked = checkMatch[1].toLowerCase() === 'x';
      const checkIdx = out.filter(l => l.includes('type="checkbox"')).length;
      // preventDefault stops the browser's native toggle so toggleCheck() is the
      // single source of truth — otherwise the native flip + the markdown flip
      // cancel out and the change never persists.
      out.push(`<li><input type="checkbox" ${checked ? 'checked' : ''} aria-label="${escAttr(checkMatch[2])}" contenteditable="false" onmousedown="event.stopPropagation(); event.stopImmediatePropagation();" onclick="event.preventDefault(); event.stopPropagation(); toggleCheck(${checkIdx})"><span class="${checked ? 'check-done' : ''}">${inlineFormat(escLine(checkMatch[2]))}</span></li>`);
      continue;
    }
    const bulletMatch = trimmed.match(/^-\s+(.+)$/);
    if (bulletMatch) { if (!inList || listType !== 'ul') { closeList(); out.push('<ul>'); inList = true; listType = 'ul'; } out.push(`<li>${inlineFormat(escLine(bulletMatch[1]))}</li>`); continue; }
    const numMatch = trimmed.match(/^\d+\.\s+(.+)$/);
    if (numMatch) { if (!inList || listType !== 'ol') { closeList(); out.push('<ol>'); inList = true; listType = 'ol'; } out.push(`<li>${inlineFormat(escLine(numMatch[1]))}</li>`); continue; }
    if (trimmed.startsWith('> ')) { closeList(); out.push(`<blockquote>${inlineFormat(escLine(trimmed.substring(2)))}</blockquote>`); continue; }
    // Table rows
    if (trimmed.startsWith('|') && trimmed.endsWith('|')) {
      closeList();
      // Check if this is start of a table
      if (!out.length || !out[out.length - 1].includes('<td>') && !out[out.length - 1].includes('<th>')) {
        // Check if next line is separator
        const nextLine = (lines[i + 1] || '').trim();
        const isSep = /^\|[\s:-]+\|/.test(nextLine);
        const cells = trimmed.split('|').filter((_, j, a) => j > 0 && j < a.length - 1).map(c => c.trim());
        if (isSep) {
          out.push('<table><thead><tr>' + cells.map(c => `<th scope="col">${inlineFormat(escLine(c))}</th>`).join('') + '</tr></thead><tbody>');
          i++; // skip separator line
        } else {
          // No separator row: treat the first row as the header so the table still
          // exposes column headers to assistive tech.
          out.push('<table><thead><tr>' + cells.map(c => `<th scope="col">${inlineFormat(escLine(c))}</th>`).join('') + '</tr></thead><tbody>');
        }
      } else {
        const cells = trimmed.split('|').filter((_, j, a) => j > 0 && j < a.length - 1).map(c => c.trim());
        out.push('<tr>' + cells.map(c => `<td>${inlineFormat(escLine(c))}</td>`).join('') + '</tr>');
      }
      // Check if next line is NOT a table row
      const nextTrimmed = (lines[i + 1] || '').trim();
      if (!nextTrimmed.startsWith('|') || !nextTrimmed.endsWith('|')) {
        out.push('</tbody></table>');
      }
      continue;
    }
    closeList();
    // Check for standalone media URLs
    const mediaEmbed = /^https?:\/\/\S+$/.test(trimmed) ? embedMedia(trimmed) : null;
    if (mediaEmbed) { out.push(mediaEmbed); continue; }
    out.push(`<p>${inlineFormat(escLine(raw))}</p>`);
  }
  closeList();
  if (inCodeBlock) out.push(codeBlockHtml(codeBuffer, codeLang));
  let html = out.join('\n');
  html = html.replace(/<\/blockquote>\n<blockquote>/g, '<br>');
  html = html.replace(/<\/(h[1-3]|ul|ol|pre|blockquote|hr)>\n<br>\n/g, '</$1>\n');
  return html;
}

// ── UI helpers ──
function esc(s) { if (!s) return ''; return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escAttr(s) { return (s || '').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

function relativeTime(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr.replace(' ', 'T') + (dateStr.includes('Z') ? '' : 'Z'));
  const now = new Date();
  const diff = Math.floor((now - d) / 1000);
  if (diff < 60) return __( 'just now', 'noodled' );
  /* translators: %d is the number of minutes ago */
  if (diff < 3600) return sprintf( __( '%dm ago', 'noodled' ), Math.floor(diff / 60) );
  /* translators: %d is the number of hours ago */
  if (diff < 86400) return sprintf( __( '%dh ago', 'noodled' ), Math.floor(diff / 3600) );
  if (diff < 172800) return __( 'yesterday', 'noodled' );
  /* translators: %d is the number of days ago */
  if (diff < 604800) return sprintf( __( '%dd ago', 'noodled' ), Math.floor(diff / 86400) );
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function showToast(msg) {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.classList.add('show');
  setTimeout(() => el.classList.remove('show'), 2000);
}

function setStatus(msg) {
  const el = document.getElementById('status');
  el.textContent = msg;
  setTimeout(() => { if (el.textContent === msg) el.textContent = ''; }, 4000);
}

// ── Modal prompt ──
function showPrompt(title, label, defaultVal = '') {
  return new Promise(resolve => {
    const el = document.getElementById('modalContainer');
    el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';resolve_modal(null);}"><div class="modal"><h3>${title}</h3><label for="modalInput" style="font-size:12px;color:var(--text-muted)">${label}</label><input id="modalInput" value="${escAttr(defaultVal)}" autofocus><div class="modal-buttons"><button class="btn btn-sm" onclick="resolve_modal(null)">${esc(__( 'Cancel', 'noodled' ))}</button><button class="btn btn-sm btn-accent" onclick="resolve_modal(document.getElementById('modalInput').value)">${esc(__( 'OK', 'noodled' ))}</button></div></div></div>`;
    window.resolve_modal = (val) => { el.innerHTML = ''; resolve(val); };
    const inp = document.getElementById('modalInput');
    inp.focus();
    inp.addEventListener('keydown', e => {
      if (e.key === 'Enter') window.resolve_modal(inp.value);
      if (e.key === 'Escape') window.resolve_modal(null);
    });
  });
}

// Themed confirm dialog (replaces the browser's native confirm()). Resolves true
// on OK, false on Cancel/Escape/overlay-click.
function showConfirm(message, opts = {}) {
  return new Promise(resolve => {
    const el = document.getElementById('modalContainer');
    const okLabel = opts.okLabel || __( 'OK', 'noodled' );
    const cancelLabel = opts.cancelLabel || __( 'Cancel', 'noodled' );
    const okClass = opts.danger ? 'btn-danger' : 'btn-accent';
    const done = (val) => { el.innerHTML = ''; document.removeEventListener('keydown', onKey, true); resolve(val); };
    window.resolve_confirm = done;
    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); done(false); }
      else if (e.key === 'Enter') { e.preventDefault(); done(true); }
    }
    el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){resolve_confirm(false);}">
      <div class="confirm-box" role="alertdialog" aria-modal="true" aria-label="${escAttr(message)}">
        <p>${esc(message)}</p>
        <div class="modal-buttons">
          <button class="btn btn-sm" onclick="resolve_confirm(false)">${esc(cancelLabel)}</button>
          <button class="btn btn-sm ${okClass}" onclick="resolve_confirm(true)">${esc(okLabel)}</button>
        </div>
      </div>
    </div>`;
    document.addEventListener('keydown', onKey, true);
    setTimeout(() => { const b = el.querySelector('.btn-accent, .btn-danger'); if (b) b.focus(); }, 0);
  });
}

// ── Context menu ──
function showContextMenu(event, items) {
  closeContextMenu();
  const el = document.getElementById('ctxContainer');
  const menu = document.createElement('div');
  menu.className = 'ctx-menu';
  menu.style.left = event.clientX + 'px';
  menu.style.top = event.clientY + 'px';
  items.forEach(item => {
    if (item.sep) { const sep = document.createElement('div'); sep.className = 'ctx-sep'; menu.appendChild(sep); return; }
    const div = document.createElement('div');
    div.className = 'ctx-item' + (item.danger ? ' danger' : '');
    div.textContent = item.label;
    div.setAttribute('role', 'menuitem');
    div.tabIndex = 0;
    div.addEventListener('click', () => { closeContextMenu(); item.action(); });
    menu.appendChild(div);
  });
  menu.setAttribute('role', 'menu');
  el.appendChild(menu);
  // Focus the first item so the menu is keyboard navigable on open.
  setTimeout(() => { const f = menu.querySelector('.ctx-item'); if (f) f.focus(); }, 0);
  document.addEventListener('click', closeContextMenu, { once: true });
}

function closeContextMenu() { document.getElementById('ctxContainer').innerHTML = ''; }
document.addEventListener('scroll', closeContextMenu, true);

// ── Offline indicator ──
let _isOnline = true;
function setOnline(online) {
  const was = _isOnline;
  _isOnline = online;
  updateOfflineBanner();
  // Coming back online with queued edits → flush them now.
  if (online && !was && saveQueue.length) retrySaveQueue();
}

// Single source of truth for the banner: offline message, else pending-sync
// count, else hidden.
function updateOfflineBanner() {
  const el = document.getElementById('offlineBanner');
  updateSyncPill();
  if (!el) return;
  const n = saveQueue.length;
  if (!_isOnline) {
    el.textContent = __( "You're offline — changes are saved on this device and will sync when you reconnect", 'noodled' );
    el.classList.add('show');
  } else if (n) {
    /* translators: %d is the number of changes waiting to sync */
    el.textContent = sprintf( _n( '%d change waiting to sync…', '%d changes waiting to sync…', n, 'noodled' ), n );
    el.classList.add('show');
  } else {
    el.classList.remove('show');
  }
}

// ── Sync-status pill (toolbar): Offline / Saving… / Saved <time ago> ──
let _saving = false;
let _lastSavedAt = null;
let _syncPillTimer = null;
function markSaved() { _lastSavedAt = Date.now(); _saving = false; updateSyncPill(); }
function updateSyncPill() {
  const el = document.getElementById('syncPill');
  if (!el) return;
  el.classList.remove('offline', 'saving', 'saved');
  let label;
  if (!_isOnline) { el.classList.add('offline'); label = __( 'Offline', 'noodled' ); }
  else if (_saving || saveQueue.length) { el.classList.add('saving'); label = __( 'Saving…', 'noodled' ); }
  else {
    el.classList.add('saved');
    /* translators: %s is a relative time like "2m ago" */
    label = _lastSavedAt ? sprintf( __( 'Saved %s', 'noodled' ), relativeTime(new Date(_lastSavedAt).toISOString()) ) : __( 'Saved', 'noodled' );
  }
  el.innerHTML = '<span class="sync-dot" aria-hidden="true"></span>' + esc(label);
  // Keep the "x ago" fresh without spamming work.
  if (!_syncPillTimer) _syncPillTimer = setInterval(updateSyncPill, 30000);
}

const SAVE_QUEUE_KEY = 'noodled_save_queue';
function persistSaveQueue() {
  try { localStorage.setItem(SAVE_QUEUE_KEY, JSON.stringify(saveQueue)); } catch (e) {}
  updateOfflineBanner();
}
function loadSaveQueue() {
  try { const q = JSON.parse(localStorage.getItem(SAVE_QUEUE_KEY) || '[]'); if (Array.isArray(q)) saveQueue = q; } catch (e) {}
  if (saveQueue.length && !saveRetryTimer) saveRetryTimer = setInterval(retrySaveQueue, 15000);
  updateOfflineBanner();
}

// ── Sync pull ──
async function syncPull() {
  const btn = document.getElementById('syncPullBtn');
  btn.disabled = true;
  btn.textContent = __( 'Syncing...', 'noodled' );
  try {
    const r = await api._fetch('/sync/pull', { method: 'POST' });
    if (r.error) {
      /* translators: %s is the error message */
      showToast(sprintf( __( 'Sync failed: %s', 'noodled' ), r.error ));
    } else {
      /* translators: %d is the number of notes synced */
      showToast(sprintf( _n( 'Synced: %d note', 'Synced: %d notes', r.notes || 0, 'noodled' ), r.notes || 0 ));
      await loadNotebooks();
      await loadNotes();
      updateTrashCount();
    }
  } catch (e) {
    showToast(__( 'Sync failed', 'noodled' ));
  }
  btn.disabled = false;
  btn.textContent = __( 'Sync', 'noodled' );
}

// ── Plaud sync ──
async function syncPlaud() {
  const btn = document.getElementById('plaudSyncBtn');
  if (btn) { btn.disabled = true; btn.textContent = __( 'Syncing Plaud...', 'noodled' ); }
  try {
    const r = await api.sync_plaud();
    if (r.error) {
      /* translators: %s is the error message */
      showToast(sprintf( __( 'Plaud sync failed: %s', 'noodled' ), r.error ));
    } else {
      /* translators: %d is the number of new recordings imported */
      showToast(sprintf( _n( 'Plaud: %d new recording imported', 'Plaud: %d new recordings imported', r.downloaded, 'noodled' ), r.downloaded ));
      if (r.downloaded > 0) {
        await loadNotebooks();
        await loadNotes();
      }
    }
  } catch (e) {
    showToast(__( 'Plaud sync failed', 'noodled' ));
  }
  if (btn) { btn.disabled = false; btn.textContent = __( 'Plaud', 'noodled' ); }
}

// ── Pull to refresh ──
(function() {
  let startY = 0, pulling = false, pullEl = null;
  const noteList = () => document.querySelector('.note-list');

  document.addEventListener('touchstart', e => {
    const nl = noteList();
    if (!nl || nl.scrollTop > 0) return;
    if (document.querySelector('.col-content.open')) return;
    startY = e.touches[0].clientY;
    pulling = true;
  }, { passive: true });

  document.addEventListener('touchmove', e => {
    if (!pulling) return;
    const dy = e.touches[0].clientY - startY;
    if (dy < 0) { pulling = false; return; }
    if (dy > 20) {
      if (!pullEl) {
        pullEl = document.getElementById('pullIndicator');
        if (pullEl) pullEl.classList.add('show');
      }
    }
  }, { passive: true });

  document.addEventListener('touchend', async () => {
    if (pullEl && pullEl.classList.contains('show')) {
      pullEl.textContent = __( 'Syncing...', 'noodled' );
      try {
        await loadNotebooks();
        await loadNotes();
        showToast(__( 'Refreshed', 'noodled' ));
      } catch (e) {}
      pullEl.classList.remove('show');
      pullEl.textContent = __( '↓ Pull to refresh', 'noodled' );
    }
    pulling = false;
    pullEl = null;
  });
})();

// ── Swipe between notes ──
(function() {
  let sx = 0, sy = 0, tracking = false;
  document.addEventListener('touchstart', e => {
    const col = document.querySelector('.col-content.open');
    if (!col) return;
    sx = e.touches[0].clientX;
    sy = e.touches[0].clientY;
    tracking = true;
  }, { passive: true });
  document.addEventListener('touchmove', e => {
    if (!tracking) return;
    const dy = Math.abs(e.touches[0].clientY - sy);
    if (dy > 30) { tracking = false; return; }
    const dx = e.touches[0].clientX - sx;
    if (Math.abs(dx) > 80 && activeNote) {
      tracking = false;
      const idx = filteredNotes.findIndex(n => n.id === activeNote.id);
      if (idx === -1) return;
      const nextIdx = dx < 0 ? idx + 1 : idx - 1;
      if (nextIdx >= 0 && nextIdx < filteredNotes.length) {
        selectNote(filteredNotes[nextIdx].id);
      }
    }
  }, { passive: true });
  document.addEventListener('touchend', () => { tracking = false; });
})();

// ── Starred notes ──
async function toggleStar(noteId) {
  // Stars stored in config as array of note IDs
  const stars = config.starred || [];
  const idx = stars.indexOf(noteId);
  if (idx >= 0) stars.splice(idx, 1);
  else stars.push(noteId);
  config.starred = stars;
  try { await api.set_config('starred', stars); } catch (e) {}
  if (viewingStarred) await showStarred();
  else renderNoteList();
}

function isStarred(noteId) {
  return (config.starred || []).includes(noteId);
}

async function showStarred() {
  viewingStarred = true;
  viewingTrash = false;
  activeNotebook = null;
  const stars = config.starred || [];
  const allNotes = await api.get_notes(null);
  notes = allNotes.filter(n => stars.includes(n.id));
  filterNotes();
  renderNotebooks();
  document.getElementById('nbAll').className = 'nb-all';
  document.querySelectorAll('.nb-item').forEach(el => el.classList.remove('active'));
  document.getElementById('nbStarred').classList.add('active');
}

// ── Duplicate note ──
async function duplicateNote(noteId) {
  const note = await api.get_note(null, noteId);
  if (!note) return;
  /* translators: %s is the original note title */
  const newNote = await api.create_note(note.notebook, sprintf( __( '%s (copy)', 'noodled' ), note.title ), note.body);
  await loadNotebooks();
  await loadNotes();
  if (newNote && !newNote.error) {
    selectNote(newNote.id);
    showToast(__( 'Duplicated', 'noodled' ));
  }
}

// ── Export note as HTML ──
function exportNote() {
  if (!activeNote) return;
  const html = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>${esc(activeNote.title)}</title>
<style>body{font-family:system-ui,sans-serif;max-width:720px;margin:40px auto;padding:0 20px;line-height:1.7;color:#333}
h1,h2,h3{color:#111}code{background:#f4f4f4;padding:2px 5px;border-radius:3px}
pre{background:#f4f4f4;padding:16px;border-radius:6px;overflow-x:auto}
blockquote{border-left:3px solid #3b82f6;padding-left:12px;color:#666;margin:1em 0}
img{max-width:100%}hr{border:none;border-top:1px solid #ddd;margin:2em 0}</style></head>
<body><h1>${esc(activeNote.title)}</h1>${renderMarkdown(activeNote.body)}</body></html>`;
  const blob = new Blob([html], { type: 'text/html' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = (activeNote.title || 'note') + '.html';
  a.click();
  URL.revokeObjectURL(a.href);
  showToast(__( 'Exported', 'noodled' ));
}

// ── Reading mode ──
function toggleReadingMode() {
  readingMode = !readingMode;
  document.body.classList.toggle('reading-mode', readingMode);
  if (readingMode) {
    const el = document.getElementById('noteBody');
    if (el) el.contentEditable = 'false';
  } else {
    const el = document.getElementById('noteBody');
    if (el) el.contentEditable = 'true';
  }
}

// ── Auto-link URLs on paste ──
document.addEventListener('paste', e => {
  const el = document.getElementById('noteBody');
  if (!el || document.activeElement !== el) return;
  const text = (e.clipboardData || window.clipboardData).getData('text');
  if (/^https?:\/\/\S+$/.test(text.trim())) {
    e.preventDefault();
    const url = text.trim();
    document.execCommand('insertHTML', false, `<a href="${url}" target="_blank" rel="noopener noreferrer" title="${escAttr(__( 'Opens in a new tab', 'noodled' ))}">${url}</a>`);
    schedSave();
    return;
  }
  // Image paste from clipboard
  const items = e.clipboardData?.items;
  if (!items || !activeNote) return;
  for (const item of items) {
    if (item.type.startsWith('image/')) {
      e.preventDefault();
      const file = item.getAsFile();
      if (file) {
        const name = 'paste-' + Date.now() + '.' + (file.type.split('/')[1] || 'png');
        const renamed = new File([file], name, { type: file.type });
        attachFile(renamed);
      }
      return;
    }
  }
});

// ── Note history (stored in meta_json) ──
function saveHistory(note) {
  if (!note || !note.body) return;
  const history = note._history || [];
  history.unshift({ body: note.body, title: note.title, time: new Date().toISOString() });
  if (history.length > 5) history.length = 5;
  note._history = history;
}

function showHistory() {
  if (!activeNote) return;
  const history = activeNote._history || [];
  if (!history.length) { showToast(__( 'No history yet', 'noodled' )); return; }
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal" style="max-width:500px">
      <h3>${esc(__( 'Note History', 'noodled' ))}</h3>
      <div style="max-height:300px;overflow-y:auto">
        ${history.map((h, i) => `<div class="history-item" role="button" tabindex="0" onclick="restoreHistory(${i})" style="padding:10px;cursor:pointer;border-bottom:1px solid var(--border)">
          <div style="font-size:12px;color:var(--text-muted)">${relativeTime(h.time)} — ${h.title}</div>
          <div style="font-size:11px;color:var(--text);margin-top:4px">${esc((h.body || '').substring(0, 100))}...</div>
        </div>`).join('')}
      </div>
      <div class="modal-buttons" style="margin-top:12px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">${esc(__( 'Close', 'noodled' ))}</button>
      </div>
    </div>
  </div>`;
}

function restoreHistory(index) {
  if (!activeNote) return;
  const history = activeNote._history || [];
  if (!history[index]) return;
  activeNote.body = history[index].body;
  activeNote.title = history[index].title;
  document.getElementById('modalContainer').innerHTML = '';
  renderContent();
  doSave();
  showToast(__( 'Restored from history', 'noodled' ));
}

// ── Bulk select mode ──
function toggleBulkMode() {
  bulkMode = !bulkMode;
  bulkSelected.clear();
  renderNoteList();
  const bar = document.getElementById('bulkBar');
  if (bar) bar.classList.toggle('show', bulkMode);
}

function toggleBulkSelect(noteId, e) {
  if (e) e.stopPropagation();
  if (bulkSelected.has(noteId)) bulkSelected.delete(noteId);
  else bulkSelected.add(noteId);
  renderNoteList();
  const countEl = document.getElementById('bulkCount');
  if (countEl) countEl.textContent = bulkSelected.size;
}

async function bulkDelete() {
  if (!bulkSelected.size) return;
  /* translators: %d is the number of notes selected for deletion */
  if (!(await showConfirm(sprintf( _n( 'Delete %d note?', 'Delete %d notes?', bulkSelected.size, 'noodled' ), bulkSelected.size ), { danger: true, okLabel: __( 'Delete', 'noodled' ) }))) return;
  for (const id of bulkSelected) {
    const note = notes.find(n => n.id === id);
    if (note) await api.delete_note(note.notebook, id);
  }
  bulkSelected.clear();
  toggleBulkMode();
  await loadNotebooks();
  await loadNotes();
  updateTrashCount();
  showToast(__( 'Deleted', 'noodled' ));
}

async function bulkMove() {
  if (!bulkSelected.size) return;
  const target = await showPrompt(__( 'Move Notes', 'noodled' ), __( 'Move to notebook:', 'noodled' ));
  if (!target) return;
  for (const id of bulkSelected) {
    const note = notes.find(n => n.id === id);
    if (note) await api.move_note(note.notebook, id, target);
  }
  bulkSelected.clear();
  toggleBulkMode();
  await loadNotebooks();
  await loadNotes();
  /* translators: %s is the destination notebook name */
  showToast(sprintf( __( 'Moved to %s', 'noodled' ), target ));
}

// ── Notebook drag reorder ──
function setupNotebookDrag() {
  const list = document.getElementById('nbList');
  if (!list) return;
  let dragEl = null;
  list.querySelectorAll('.nb-item').forEach(item => {
    item.setAttribute('draggable', 'true');
    item.addEventListener('dragstart', e => {
      dragEl = item;
      item.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
    });
    item.addEventListener('dragend', () => {
      item.classList.remove('dragging');
      dragEl = null;
      // Save new order
      const items = list.querySelectorAll('.nb-item');
      const order = Array.from(items).map(el => {
        const nameEl = el.querySelector('.nb-name');
        if (!nameEl) return '';
        // Get only the first text node to avoid shared/read-only icon text
        const textNode = Array.from(nameEl.childNodes).find(n => n.nodeType === 3);
        return textNode ? textNode.textContent.trim() : nameEl.textContent.trim();
      });
      api.set_config('notebook_order', order).catch(() => {});
      config.notebook_order = order;
    });
    item.addEventListener('dragover', e => {
      e.preventDefault();
      if (!dragEl || dragEl === item) return;
      const rect = item.getBoundingClientRect();
      const mid = rect.top + rect.height / 2;
      if (e.clientY < mid) list.insertBefore(dragEl, item);
      else list.insertBefore(dragEl, item.nextSibling);
    });
  });
}

// ── Word count goal ──
async function setWordGoal() {
  if (!activeNote) return;
  const goals = config.word_goals || {};
  const current = goals[activeNote.id] || '';
  const goal = await showPrompt(__( 'Word Count Goal', 'noodled' ), __( 'Target words:', 'noodled' ), current.toString());
  if (goal === null) return;
  activeNote.wordGoal = parseInt(goal) || 0;
  if (activeNote.wordGoal > 0) goals[activeNote.id] = activeNote.wordGoal;
  else delete goals[activeNote.id];
  config.word_goals = goals;
  api.set_config('word_goals', goals).catch(() => {});
  updateWordCount();
}

// ── Tag system ──
function filterByTag(tag) {
  document.getElementById('searchInput').value = '#' + tag;
  onSearch();
}

function getAllTags() {
  const tags = new Set();
  notes.forEach(n => {
    const matches = (n.body || '').match(/(^|\s)#([a-zA-Z][\w-]*)/g);
    if (matches) matches.forEach(m => tags.add(m.trim().substring(1)));
  });
  return Array.from(tags).sort();
}

async function showTagCloud() {
  await ensureBodies(); // tags live in note bodies, hydrate them first
  const tags = getAllTags();
  if (!tags.length) { showToast(__( 'No tags found', 'noodled' )); return; }
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal"><h3>${esc(__( 'Tags', 'noodled' ))}</h3>
      <div style="display:flex;flex-wrap:wrap;gap:8px;max-height:300px;overflow-y:auto">
        ${tags.map(t => `<span class="tag-chip" role="button" tabindex="0" aria-label="${escAttr(sprintf( /* translators: %s is the tag name */ __( 'Filter by tag %s', 'noodled' ), t ))}" onclick="document.getElementById('modalContainer').innerHTML=''; document.getElementById('searchInput').value='#${t}'; onSearch();">#${esc(t)}</span>`).join('')}
      </div>
      <div class="modal-buttons" style="margin-top:14px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">${esc(__( 'Close', 'noodled' ))}</button>
      </div>
    </div>
  </div>`;
}

// ── Note templates ──
const defaultTemplates = [
  { name: __( 'Meeting Notes', 'noodled' ), body: __( '## Meeting Notes\n\n**Date:** \n**Attendees:** \n\n### Agenda\n- \n\n### Action Items\n- [ ] \n\n### Notes\n', 'noodled' ) },
  { name: __( 'Journal Entry', 'noodled' ), body: '## ' + new Date().toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) + __( '\n\n### Gratitude\n- \n\n### Today\n\n\n### Reflection\n', 'noodled' ) },
  { name: __( 'Project Plan', 'noodled' ), body: __( '## Project: \n\n### Goal\n\n\n### Tasks\n- [ ] \n- [ ] \n- [ ] \n\n### Timeline\n\n| Phase | Start | End |\n|-------|-------|-----|\n| Planning | | |\n| Execution | | |\n| Review | | |\n\n### Notes\n', 'noodled' ) },
  { name: __( 'Quick List', 'noodled' ), body: __( '## List\n\n- [ ] \n- [ ] \n- [ ] \n', 'noodled' ) },
];

function showTemplates() {
  const templates = config.templates || defaultTemplates;
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal"><h3>${esc(__( 'New from Template', 'noodled' ))}</h3>
      <div style="max-height:300px;overflow-y:auto">
        ${templates.map((t, i) => `<div class="template-item" style="display:flex;align-items:center;gap:6px"><span style="flex:1" role="button" tabindex="0" aria-label="${escAttr(sprintf( /* translators: %s is the template name */ __( 'Create note from %s template', 'noodled' ), t.name ))}" onclick="createFromTemplate(${i})">${esc(t.name)}</span>${t.custom ? `<button class="btn btn-sm" title="${escAttr(__( 'Delete template', 'noodled' ))}" onclick="event.stopPropagation(); deleteTemplate(${i})" style="padding:2px 8px">&#10005;</button>` : ''}</div>`).join('')}
      </div>
      <div class="modal-buttons" style="margin-top:14px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">${esc(__( 'Cancel', 'noodled' ))}</button>
      </div>
    </div>
  </div>`;
}

async function createFromTemplate(index) {
  const templates = config.templates || defaultTemplates;
  const t = templates[index];
  if (!t) return;
  document.getElementById('modalContainer').innerHTML = '';
  const nb = activeNotebook || 'General';
  const note = await api.create_note(nb, t.name, t.body);
  await loadNotebooks();
  await loadNotes();
  if (note && !note.error) selectNote(note.id);
}

// ── Focus timer (Pomodoro) ──
let focusInterval = null;
let focusRemaining = 0;

function startFocusTimer(minutes = 25) {
  if (focusInterval) { clearInterval(focusInterval); focusInterval = null; }
  focusRemaining = minutes * 60;
  const el = document.getElementById('focusTimer');
  if (el) el.classList.add('show');
  updateFocusDisplay();
  focusInterval = setInterval(() => {
    focusRemaining--;
    updateFocusDisplay();
    if (focusRemaining <= 0) {
      clearInterval(focusInterval);
      focusInterval = null;
      showToast(__( 'Focus session complete!', 'noodled' ));
      if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
      const el = document.getElementById('focusTimer');
      if (el) el.classList.remove('show');
    }
  }, 1000);
}

function stopFocusTimer() {
  if (focusInterval) { clearInterval(focusInterval); focusInterval = null; }
  const el = document.getElementById('focusTimer');
  if (el) el.classList.remove('show');
}

function updateFocusDisplay() {
  const el = document.getElementById('focusTime');
  if (!el) return;
  const m = Math.floor(focusRemaining / 60);
  const s = focusRemaining % 60;
  el.textContent = `${m}:${s.toString().padStart(2, '0')}`;
}

function showFocusOptions() {
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal"><h3>${esc(__( 'Focus Timer', 'noodled' ))}</h3>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''; startFocusTimer(15)">${esc(sprintf( /* translators: %d is the number of minutes */ __( '%d min', 'noodled' ), 15 ))}</button>
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''; startFocusTimer(25)">${esc(sprintf( /* translators: %d is the number of minutes */ __( '%d min', 'noodled' ), 25 ))}</button>
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''; startFocusTimer(45)">${esc(sprintf( /* translators: %d is the number of minutes */ __( '%d min', 'noodled' ), 45 ))}</button>
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''; startFocusTimer(60)">${esc(sprintf( /* translators: %d is the number of minutes */ __( '%d min', 'noodled' ), 60 ))}</button>
      </div>
      <div class="modal-buttons" style="margin-top:14px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">${esc(__( 'Cancel', 'noodled' ))}</button>
      </div>
    </div>
  </div>`;
}

// ── Quick capture ──
function toggleQuickCapture() {
  const el = document.getElementById('quickCapture');
  if (!el) return;
  el.classList.toggle('show');
  if (el.classList.contains('show')) {
    document.getElementById('qcInput')?.focus();
  }
}

async function submitQuickCapture() {
  const input = document.getElementById('qcInput');
  if (!input || !input.value.trim()) return;
  const text = input.value.trim();
  const nb = activeNotebook || 'General';
  const title = text.substring(0, 50);
  await api.create_note(nb, title, text);
  input.value = '';
  toggleQuickCapture();
  await loadNotebooks();
  await loadNotes();
  showToast(__( 'Captured!', 'noodled' ));
}

// ── Note linking graph ──
async function showLinkGraph() {
  await ensureBodies(); // wiki-links live in note bodies
  const links = {};
  const allNotes = notes;
  allNotes.forEach(n => {
    const matches = (n.body || '').match(/\[\[([^\]]+)\]\]/g) || [];
    links[n.title] = matches.map(m => m.slice(2, -2));
  });

  // Build adjacency display
  const connected = Object.entries(links).filter(([_, targets]) => targets.length > 0);
  const orphans = allNotes.filter(n => !links[n.title]?.length && !Object.values(links).flat().includes(n.title));

  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal" style="max-width:500px"><h3>${esc(__( 'Note Links', 'noodled' ))}</h3>
      <div style="max-height:350px;overflow-y:auto;font-size:13px">
        ${connected.length ? connected.map(([title, targets]) =>
          `<div style="margin-bottom:12px"><strong>${esc(title)}</strong><div style="padding-left:16px;color:var(--text-muted)">${targets.map(t => {
            const found = allNotes.find(n => n.title.toLowerCase() === t.toLowerCase());
            return found ? `<a href="#" style="color:var(--accent)" onclick="event.preventDefault();document.getElementById('modalContainer').innerHTML='';selectNote(${found.id})">${esc(t)}</a>` : `<span style="color:var(--red)">${esc(t)}</span>`;
          }).join(', ')}</div></div>`
        ).join('') : '<div style="color:var(--text-muted)">' + esc(__( 'No wiki-links found. Use [[Note Title]] to link notes.', 'noodled' )) + '</div>'}
        ${orphans.length ? `<div style="margin-top:12px;border-top:1px solid var(--border);padding-top:8px"><div style="color:var(--text-muted);font-size:11px;margin-bottom:4px">${esc(sprintf( /* translators: %d is the number of unlinked notes */ _n( '%d unlinked note', '%d unlinked notes', orphans.length, 'noodled' ), orphans.length ))}</div></div>` : ''}
      </div>
      <div class="modal-buttons" style="margin-top:12px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">${esc(__( 'Close', 'noodled' ))}</button>
      </div>
    </div>
  </div>`;
}

// ── Color-coded notes ──
const noteColors = ['', '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
const noteColorNames = [__( 'None', 'noodled' ), __( 'Blue', 'noodled' ), __( 'Green', 'noodled' ), __( 'Yellow', 'noodled' ), __( 'Red', 'noodled' ), __( 'Purple', 'noodled' ), __( 'Pink', 'noodled' )];

function showColorPicker(noteId) {
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal"><h3>${esc(__( 'Note Color', 'noodled' ))}</h3>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        ${noteColors.map((c, i) => `<div role="button" tabindex="0" aria-label="${escAttr(noteColorNames[i])}" onclick="setNoteColor(${noteId},'${c}');document.getElementById('modalContainer').innerHTML='';" style="width:36px;height:36px;border-radius:50%;cursor:pointer;border:2px solid var(--border);${c ? 'background:' + c : 'background:var(--bg-input)'}" title="${escAttr(noteColorNames[i])}"></div>`).join('')}
      </div>
      <div class="modal-buttons" style="margin-top:14px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">${esc(__( 'Cancel', 'noodled' ))}</button>
      </div>
    </div>
  </div>`;
}

async function setNoteColor(noteId, color) {
  const colors = config.note_colors || {};
  if (color) colors[noteId] = color;
  else delete colors[noteId];
  config.note_colors = colors;
  api.set_config('note_colors', colors).catch(() => {});
  renderNoteList();
}

function getNoteColor(noteId) {
  return (config.note_colors || {})[noteId] || '';
}

// ── Split view ──
let splitNote = null;

function openInSplit(noteId) {
  api.get_note(null, noteId).then(note => {
    splitNote = note;
    renderSplitView();
  });
}

function renderSplitView() {
  if (!splitNote) return;
  const el = document.getElementById('splitView');
  if (!el) return;
  el.classList.add('show');
  el.innerHTML = `
    <div class="content-toolbar">
      <span class="content-title-input" style="border:none;background:none;font-weight:600">${esc(splitNote.title)}</span>
      <span class="spacer"></span>
      <button class="btn btn-sm" onclick="closeSplitView()">${esc(__( 'Close', 'noodled' ))}</button>
    </div>
    <div class="content-body"><div class="rendered-content">${renderMarkdown(splitNote.body)}</div></div>
  `;
}

function closeSplitView() {
  splitNote = null;
  const el = document.getElementById('splitView');
  if (el) { el.classList.remove('show'); el.innerHTML = ''; }
}

// ── Daily journal ──
async function openDailyJournal() {
  const today = new Date();
  const title = today.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  const nbName = 'Journal';

  // Check if today's entry exists
  const allNotes = await api.get_notes(nbName);
  const existing = allNotes.find(n => n.title === title);
  if (existing) {
    selectNote(existing.id);
    return;
  }

  // Create new journal entry
  const body = `## ${title}` + __( '\n\n### Today\n\n\n### Notes\n\n', 'noodled' );
  const note = await api.create_note(nbName, title, body);
  await loadNotebooks();
  await loadNotes();
  if (note && !note.error) selectNote(note.id);
}

// ── Dictation (speech-to-text) ──
let recognition = null;
let dictating = false;

function setDictateBtn(on) {
  const btn = document.getElementById('voiceBtn');
  if (btn) {
    if (on) { btn.textContent = __( 'Stop', 'noodled' ); btn.classList.add('recording'); }
    else { btn.innerHTML = '&#127908;'; btn.classList.remove('recording'); }
  }
  if (on) showDictationBar(); else hideDictationBar();
}

// A prominent "Listening…" bar with a big Stop button, shown while dictating so
// it's obvious the mic is live and easy to stop. The interim line shows the
// words being recognized live before they're committed to the note.
function showDictationBar() {
  let bar = document.getElementById('dictationBar');
  if (!bar) {
    bar = document.createElement('div');
    bar.id = 'dictationBar';
    bar.className = 'dictation-bar';
    bar.setAttribute('role', 'status');
    bar.setAttribute('aria-live', 'polite');
    bar.innerHTML =
      '<span class="dict-dot" aria-hidden="true"></span>' +
      '<span class="dict-status"><span class="dict-label">' + esc(__( 'Listening…', 'noodled' )) + '</span>' +
      '<span class="dict-interim" id="dictInterim"></span></span>' +
      '<button type="button" class="dict-stop" onclick="stopDictation()">' + esc(__( 'Stop', 'noodled' )) + '</button>';
    document.body.appendChild(bar);
  }
  requestAnimationFrame(() => bar.classList.add('show'));
}

function hideDictationBar() {
  const bar = document.getElementById('dictationBar');
  if (!bar) return;
  bar.classList.remove('show');
  const i = document.getElementById('dictInterim');
  if (i) i.textContent = '';
  setTimeout(() => { if (!dictating) bar.remove(); }, 260);
}

function setDictationInterim(text) {
  const i = document.getElementById('dictInterim');
  if (i) i.textContent = text || '';
}

// Insert dictated text at the caret of whichever editor is active.
function insertDictation(text) {
  text = text.trim();
  if (!text) return;
  const chunk = text + ' ';

  const raw = document.getElementById('noteBodyRaw');
  if (raw) {
    const start = raw.selectionStart != null ? raw.selectionStart : raw.value.length;
    const end = raw.selectionEnd != null ? raw.selectionEnd : raw.value.length;
    raw.value = raw.value.slice(0, start) + chunk + raw.value.slice(end);
    const pos = start + chunk.length;
    raw.setSelectionRange(pos, pos);
    raw.focus();
    if (typeof schedSave === 'function') schedSave();
    if (typeof updateWordCount === 'function') updateWordCount();
    return;
  }

  const el = document.getElementById('noteBody');
  if (el) {
    el.focus();
    const sel = window.getSelection();
    let range;
    if (sel.rangeCount && el.contains(sel.anchorNode)) {
      range = sel.getRangeAt(0);
    } else {
      range = document.createRange();
      range.selectNodeContents(el);
      range.collapse(false);
    }
    range.deleteContents();
    const node = document.createTextNode(chunk);
    range.insertNode(node);
    range.setStartAfter(node);
    range.collapse(true);
    sel.removeAllRanges();
    sel.addRange(range);
    if (typeof schedSave === 'function') schedSave();
    if (typeof updateWordCount === 'function') updateWordCount();
    return;
  }

  // Fallback: append to the note body directly. Guard against a late async
  // recognition result arriving after the note was closed/deleted.
  if (!activeNote) return;
  activeNote.body = (activeNote.body || '') + chunk;
  if (typeof saveAndRerender === 'function') saveAndRerender();
}

function toggleVoiceMemo() {
  if (dictating) { stopDictation(); return; }
  if (!activeNote) { showToast(__( 'Select a note first', 'noodled' )); return; }

  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR) { showToast(__( 'Dictation is not supported in this browser', 'noodled' )); return; }

  recognition = new SR();
  recognition.lang = navigator.language || 'en-US';
  recognition.continuous = true;
  recognition.interimResults = true;

  // The latest not-yet-finalized words, so a Stop mid-sentence can still commit
  // them before the listening bar closes.
  let pendingInterim = '';

  recognition.onresult = (event) => {
    let finalText = '', interim = '';
    for (let i = event.resultIndex; i < event.results.length; i++) {
      const r = event.results[i];
      if (r.isFinal) finalText += r[0].transcript;
      else interim += r[0].transcript;
    }
    if (finalText) insertDictation(finalText);
    pendingInterim = interim;
    setDictationInterim(interim);
  };

  recognition.onerror = (e) => {
    if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
      dictating = false;
      showToast(__( 'Microphone access denied', 'noodled' ));
    } else if (e.error !== 'no-speech' && e.error !== 'aborted') {
      /* translators: %s is the dictation error code */
      showToast(sprintf( __( 'Dictation error: %s', 'noodled' ), e.error ));
    }
  };

  // continuous mode can still end on a long pause — restart unless the user stopped.
  recognition.onend = () => {
    if (dictating) { try { recognition.start(); return; } catch (_) {} }
    // The user stopped: commit any half-spoken sentence the engine didn't
    // finalize, THEN close the bar — so nothing in flight is lost.
    if (pendingInterim && pendingInterim.trim()) { insertDictation(pendingInterim); pendingInterim = ''; }
    dictating = false;
    setDictateBtn(false);
  };

  try {
    recognition.start();
    dictating = true;
    setDictateBtn(true);
    showToast(__( 'Listening…', 'noodled' ));
  } catch (e) {
    dictating = false;
    showToast(__( 'Could not start dictation', 'noodled' ));
  }
}

function stopDictation() {
  dictating = false;
  // Let recognition.stop() finalize the current phrase and fire onend, which
  // flushes any pending words and then closes the bar. Only close immediately
  // if there's nothing to stop.
  if (recognition) { try { recognition.stop(); } catch (_) { setDictateBtn(false); } }
  else setDictateBtn(false);
}

// ── Bookmark sections / TOC ──
function showTOC() {
  if (!activeNote) return;
  const headings = (activeNote.body || '').split('\n')
    .map((line, i) => {
      const m = line.trim().match(/^(#{1,3})\s+(.+)$/);
      return m ? { level: m[1].length, text: m[2], line: i } : null;
    }).filter(Boolean);
  if (!headings.length) { showToast(__( 'No headings found', 'noodled' )); return; }
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal"><h3>${esc(__( 'Table of Contents', 'noodled' ))}</h3>
      <div style="max-height:350px;overflow-y:auto">
        ${headings.map(h => `<div class="toc-item" role="button" tabindex="0" style="padding-left:${(h.level - 1) * 16}px" onclick="jumpToHeading(${h.line});document.getElementById('modalContainer').innerHTML='';">${esc(h.text)}</div>`).join('')}
      </div>
      <div class="modal-buttons" style="margin-top:12px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">${esc(__( 'Close', 'noodled' ))}</button>
      </div>
    </div>
  </div>`;
}

function jumpToHeading(lineIndex) {
  const el = document.getElementById('noteBody');
  if (!el) return;
  const headings = el.querySelectorAll('h1, h2, h3');
  // Count headings in the markdown to match with DOM headings
  let hIdx = 0;
  const lines = (activeNote.body || '').split('\n');
  for (let i = 0; i < lineIndex && i < lines.length; i++) {
    if (/^#{1,3}\s+/.test(lines[i].trim())) hIdx++;
  }
  if (headings[hIdx]) {
    headings[hIdx].scrollIntoView({ behavior: 'smooth', block: 'center' });
    // Brief highlight
    headings[hIdx].style.background = 'var(--accent-glow, rgba(59,130,246,0.2))';
    setTimeout(() => headings[hIdx].style.background = '', 2000);
  }
}

// ── Note sharing via public link ──
async function shareNoteLink(noteId) {
  try {
    const r = await api._fetch('/notes/' + noteId + '/share', { method: 'POST' });
    if (r.error) { showToast(r.error); return; }
    const url = r.url;
    await navigator.clipboard.writeText(url);
    showToast(__( 'Public link copied!', 'noodled' ));
  } catch (e) {
    showToast(__( 'Share failed', 'noodled' ));
  }
}

// ── Embed media ──
function embedMedia(url) {
  // YouTube
  let m = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]+)/);
  if (m) return `<div class="embed-container"><iframe title="${escAttr(__( 'Embedded YouTube video', 'noodled' ))}" src="https://www.youtube.com/embed/${m[1]}" frameborder="0" allowfullscreen style="width:100%;aspect-ratio:16/9;border-radius:8px"></iframe></div>`;
  // Vimeo
  m = url.match(/vimeo\.com\/(\d+)/);
  if (m) return `<div class="embed-container"><iframe title="${escAttr(__( 'Embedded Vimeo video', 'noodled' ))}" src="https://player.vimeo.com/video/${m[1]}" frameborder="0" allowfullscreen style="width:100%;aspect-ratio:16/9;border-radius:8px"></iframe></div>`;
  // OpenStreetMap location link (e.g. a location note) → embedded map with a pin,
  // rendered with whichever provider the admin chose in WordPress settings.
  if (/openstreetmap\.org/i.test(url)) {
    const latM = url.match(/[?&#]mlat=(-?\d+(?:\.\d+)?)/i);
    const lonM = url.match(/[?&#]mlon=(-?\d+(?:\.\d+)?)/i);
    if (latM && lonM) return mapEmbedHtml(parseFloat(latM[1]), parseFloat(lonM[1]), url);
  }
  return null;
}

// Build the embedded map for a location note. The note body always stores a
// portable OpenStreetMap link; this just picks how to DISPLAY it based on the
// admin's chosen provider (Mapbox/Google need a key, else falls back to OSM).
function mapEmbedHtml(lat, lng, linkUrl) {
  const cfg = (typeof noodledConfig !== 'undefined' && noodledConfig.map) || {};
  const provider = cfg.provider || 'osm';
  // Tapping the button offers Apple Maps / Google Maps / Waze (see openMapNav).
  const openLink = `<button type="button" class="map-nav-btn" contenteditable="false" onclick="event.preventDefault(); event.stopPropagation(); openMapNav(${lat}, ${lng})"><span aria-hidden="true">&#129517;</span> ${esc(__( 'Directions', 'noodled' ))}</button>`;
  const title = escAttr(__( 'Map location', 'noodled' ));

  if (provider === 'gmaps' && cfg.gmapsKey) {
    const src = `https://www.google.com/maps/embed/v1/place?key=${encodeURIComponent(cfg.gmapsKey)}&q=${lat},${lng}&zoom=16`;
    return `<div class="embed-container map-embed"><iframe title="${title}" src="${src}" frameborder="0" loading="lazy" allowfullscreen style="width:100%;aspect-ratio:16/10;border-radius:8px"></iframe>${openLink}</div>`;
  }
  if (provider === 'mapbox' && cfg.mapboxToken) {
    const tok = encodeURIComponent(cfg.mapboxToken);
    const src = `https://api.mapbox.com/styles/v1/mapbox/streets-v12/static/pin-l+e5484d(${lng},${lat})/${lng},${lat},15,0/640x400@2x?access_token=${tok}`;
    return `<div class="embed-container map-embed"><img class="map-static" src="${src}" alt="${title}" loading="lazy" style="width:100%;border-radius:8px">${openLink}</div>`;
  }
  // Default: OpenStreetMap embed iframe with a marker (no key required).
  const d = 0.008; // ~1 km box around the pin
  const bbox = `${lng - d},${lat - d},${lng + d},${lat + d}`;
  const src = `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${lat},${lng}`;
  return `<div class="embed-container map-embed"><iframe title="${title}" src="${src}" frameborder="0" loading="lazy" style="width:100%;aspect-ratio:16/10;border-radius:8px"></iframe>${openLink}</div>`;
}

// Tapping a location-note map's Directions button: choose which app to open the
// coordinates in. Universal deep links so each app opens natively on mobile.
function openMapNav(lat, lng) {
  const apple  = `https://maps.apple.com/?ll=${lat},${lng}&q=${encodeURIComponent('Location')}`;
  const google = `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;
  const waze   = `https://waze.com/ul?ll=${lat}%2C${lng}&navigate=yes`;
  const app = (href, icon, label) =>
    `<a class="map-nav-app" href="${href}" target="_blank" rel="noopener noreferrer" onclick="closeMapNav()"><span class="mn-icon" aria-hidden="true">${icon}</span>${esc(label)}</a>`;
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){closeMapNav();}">
    <div class="modal map-nav-modal">
      <h3>${esc(__( 'Open in…', 'noodled' ))}</h3>
      <div class="map-nav-list">
        ${app(apple,  '🍎', __( 'Apple Maps', 'noodled' ))}
        ${app(google, '🗺️', __( 'Google Maps', 'noodled' ))}
        ${app(waze,   '🚗', __( 'Waze', 'noodled' ))}
      </div>
      <div class="modal-buttons" style="margin-top:14px">
        <button class="btn btn-sm" onclick="closeMapNav()">${esc(__( 'Cancel', 'noodled' ))}</button>
      </div>
    </div>
  </div>`;
}
function closeMapNav() { const el = document.getElementById('modalContainer'); if (el) el.innerHTML = ''; }

// ── Typewriter mode ──
let typewriterMode = false;

function toggleTypewriter() {
  typewriterMode = !typewriterMode;
  document.body.classList.toggle('typewriter-mode', typewriterMode);
  showToast(typewriterMode ? __( 'Typewriter mode on', 'noodled' ) : __( 'Typewriter mode off', 'noodled' ));
}

// Keep cursor centered in typewriter mode
document.addEventListener('selectionchange', () => {
  if (!typewriterMode) return;
  const sel = window.getSelection();
  if (!sel.rangeCount) return;
  const range = sel.getRangeAt(0);
  const rect = range.getBoundingClientRect();
  if (rect.top === 0 && rect.left === 0) return;
  const body = document.querySelector('.content-body');
  if (!body) return;
  const bodyRect = body.getBoundingClientRect();
  const targetY = bodyRect.top + bodyRect.height / 2;
  const offset = rect.top - targetY;
  if (Math.abs(offset) > 20) {
    body.scrollBy({ top: offset, behavior: 'smooth' });
  }
});

// ── Note statistics dashboard ──
async function showStats() {
  await ensureBodies(); // word counts need the full bodies
  const totalNotes = notes.length;
  const totalWords = notes.reduce((sum, n) => {
    const w = (n.body || '').trim().split(/\s+/).filter(w => w.length > 0).length;
    return sum + w;
  }, 0);
  const nbCounts = {};
  notes.forEach(n => { nbCounts[n.notebook] = (nbCounts[n.notebook] || 0) + 1; });
  const tags = getAllTags();

  // Activity: notes per day (last 14 days)
  const days = {};
  const now = new Date();
  for (let d = 0; d < 14; d++) {
    const date = new Date(now);
    date.setDate(date.getDate() - d);
    const key = date.toISOString().slice(0, 10);
    days[key] = 0;
  }
  notes.forEach(n => {
    const mod = (n.modified || n.created || '').slice(0, 10);
    if (days.hasOwnProperty(mod)) days[mod]++;
  });
  const maxActivity = Math.max(1, ...Object.values(days));
  const heatmap = Object.entries(days).reverse().map(([date, count]) => {
    const pct = Math.round(count / maxActivity * 100);
    const label = date.slice(5);
    /* translators: %1$s is the date, %2$d is the number of notes on that date */
    const hmLabel = escAttr(sprintf( _n( '%1$s: %2$d note', '%1$s: %2$d notes', count, 'noodled' ), date, count ));
    return `<div title="${hmLabel}" aria-label="${hmLabel}" style="width:20px;height:20px;border-radius:3px;background:var(--accent);opacity:${Math.max(0.1, pct / 100)}"></div>`;
  }).join('');

  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal" style="max-width:450px"><h3>${esc(__( 'Statistics', 'noodled' ))}</h3>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
        <div class="stat-card"><div class="stat-num">${totalNotes}</div><div class="stat-label">${esc(__( 'Notes', 'noodled' ))}</div></div>
        <div class="stat-card"><div class="stat-num">${totalWords.toLocaleString()}</div><div class="stat-label">${esc(__( 'Words', 'noodled' ))}</div></div>
        <div class="stat-card"><div class="stat-num">${notebooks.length}</div><div class="stat-label">${esc(__( 'Notebooks', 'noodled' ))}</div></div>
        <div class="stat-card"><div class="stat-num">${tags.length}</div><div class="stat-label">${esc(__( 'Tags', 'noodled' ))}</div></div>
      </div>
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">${esc(__( 'Notes per notebook', 'noodled' ))}</div>
      ${Object.entries(nbCounts).map(([name, count]) => `<div style="display:flex;justify-content:space-between;font-size:12px;padding:3px 0"><span>${esc(name)}</span><span style="color:var(--text-muted)">${count}</span></div>`).join('')}
      <div style="font-size:12px;color:var(--text-muted);margin:12px 0 6px">${esc(__( 'Activity (14 days)', 'noodled' ))}</div>
      <div style="display:flex;gap:3px;flex-wrap:wrap">${heatmap}</div>
      <div class="modal-buttons" style="margin-top:14px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">${esc(__( 'Close', 'noodled' ))}</button>
      </div>
    </div>
  </div>`;
}

// ── Find and replace ──
let findReplaceOpen = false;

function toggleFindReplace() {
  findReplaceOpen = !findReplaceOpen;
  const existing = document.getElementById('findReplaceBar');
  if (existing) { existing.remove(); findReplaceOpen = false; return; }

  const bar = document.createElement('div');
  bar.id = 'findReplaceBar';
  bar.className = 'find-replace-bar';
  bar.innerHTML = `
    <input id="frFind" placeholder="${escAttr(__( 'Find...', 'noodled' ))}" aria-label="${escAttr(__( 'Find text', 'noodled' ))}" oninput="highlightFindsInContent()">
    <input id="frReplace" placeholder="${escAttr(__( 'Replace...', 'noodled' ))}" aria-label="${escAttr(__( 'Replace with', 'noodled' ))}">
    <button class="btn btn-sm" onclick="doReplace(false)">${esc(__( 'Replace', 'noodled' ))}</button>
    <button class="btn btn-sm" onclick="doReplace(true)">${esc(__( 'All', 'noodled' ))}</button>
    <button class="btn btn-sm" onclick="toggleFindReplace()">&#10005;</button>
  `;
  const toolbar = document.querySelector('.content-toolbar');
  if (toolbar) toolbar.after(bar);
  document.getElementById('frFind')?.focus();
}

function doReplace(all) {
  const find = document.getElementById('frFind')?.value;
  const replace = document.getElementById('frReplace')?.value;
  if (!find || !activeNote) return;
  const el = document.getElementById('noteBody');
  if (el) activeNote.body = htmlToMarkdown(el);
  if (all) {
    activeNote.body = activeNote.body.split(find).join(replace);
  } else {
    const idx = activeNote.body.indexOf(find);
    if (idx >= 0) activeNote.body = activeNote.body.substring(0, idx) + replace + activeNote.body.substring(idx + find.length);
  }
  renderContent();
  doSave();
  showToast(__( 'Replaced', 'noodled' ));
}

// ── Zen writing mode ──
let zenMode = false;

function toggleZenMode() {
  zenMode = !zenMode;
  document.body.classList.toggle('zen-mode', zenMode);
  showToast(zenMode ? __( 'Zen mode on', 'noodled' ) : __( 'Zen mode off', 'noodled' ));
}

// ── Auto-save indicator ──
let saveIndicatorTimer = null;

function showSaveIndicator(state) {
  // Drive the toolbar sync pill regardless of whether the inline indicator exists.
  if (state === 'saving') { _saving = true; updateSyncPill(); }
  else markSaved();
  const el = document.getElementById('saveIndicator');
  if (!el) return;
  if (state === 'saving') {
    el.className = 'save-indicator saving';
    el.textContent = '';
  } else {
    el.className = 'save-indicator saved';
    // Retrigger the little pulse each save.
    void el.offsetWidth;
    el.classList.add('saved-flash');
    el.textContent = '✓';
    clearTimeout(saveIndicatorTimer);
    saveIndicatorTimer = setTimeout(() => { el.className = 'save-indicator'; }, 3000);
  }
}

// ── Backlinks panel ──
// Resolved server-side (a targeted [[title]] query) so opening a note never
// needs every other note's body on the client.
async function renderBacklinks() {
  if (!activeNote) return;
  const note = activeNote;
  let backlinks = [];
  try { backlinks = await api.get_backlinks(note.title); } catch (e) { return; }
  if (activeNote !== note) return; // user navigated away while we were fetching
  const el = document.getElementById('editorFooter');
  if (!el || !Array.isArray(backlinks) || !backlinks.length) return;
  const links = backlinks
    .filter(n => n.id !== note.id)
    .map(n => `<a href="#" class="backlink" onclick="event.preventDefault();selectNote(${n.id})">${esc(n.title)}</a>`)
    .join(', ');
  if (links) {
    /* translators: %s is a comma-separated list of note links */
    el.innerHTML += `<div class="backlinks-line">${sprintf( __( 'Linked from: %s', 'noodled' ), links )}</div>`;
  }
}

// ── Notebook cover image ──
async function setNotebookCover(nbName) {
  const url = await showPrompt(__( 'Notebook Cover', 'noodled' ), __( 'Image URL:', 'noodled' ), (config.nb_covers || {})[nbName] || '');
  if (url === null) return;
  const covers = config.nb_covers || {};
  if (url) covers[nbName] = url;
  else delete covers[nbName];
  config.nb_covers = covers;
  api.set_config('nb_covers', covers).catch(() => {});
  renderNoteList();
}

function getNotebookCover(nbName) {
  return (config.nb_covers || {})[nbName] || '';
}

// ── Attachment gallery + lightbox ──
function isImageAttachment(a) {
  return /^image\//.test(a.mime || '') || /\.(png|jpe?g|gif|webp|bmp|svg|heic|heif)$/i.test(a.filename || a.name || '');
}

function renderAttachmentGallery() {
  document.querySelectorAll('.attachment-gallery').forEach(g => g.remove());
  if (!activeNote) return;
  const atts = activeNote.attachments || [];
  if (!atts.length) return;
  // Render at the TOP of the scrollable note body (before the editor) so photos
  // and files lead the note, and the gallery scrolls with the content.
  const host = document.getElementById('dropZone');
  if (!host) return;
  const images = atts.filter(a => !a._pending && isImageAttachment(a));
  const gallery = document.createElement('div');
  gallery.className = 'attachment-gallery';
  atts.forEach(a => {
    const name = a.filename || a.name;
    const item = document.createElement('div');
    item.className = 'gal-item';
    if (a._pending) {
      // Still uploading — show a spinner tile, no delete / no click.
      item.classList.add('pending');
      const sp = document.createElement('div'); sp.className = 'gal-spinner';
      const label = document.createElement('div'); label.className = 'gf-name'; label.textContent = name;
      item.appendChild(sp); item.appendChild(label);
      gallery.appendChild(item);
      return;
    }
    if (isImageAttachment(a)) {
      const img = document.createElement('img');
      img.className = 'gallery-thumb';
      img.src = a.url; img.alt = name; img.loading = 'lazy';
      img.onclick = () => openLightbox(images, images.indexOf(a));
      item.appendChild(img);
    } else {
      // Non-image attachments (incl. HTML) show as an icon tile in the same bar.
      const div = document.createElement('div');
      div.className = 'gallery-file-tile';
      const ext = (name.split('.').pop() || '').toLowerCase();
      const glyph = /^html?$/.test(ext) ? '🌐' : (ext === 'pdf' ? '📕' : (/^(docx?|odt|rtf|txt|md|pages)$/.test(ext) ? '📄' : '📎'));
      const icon = document.createElement('div'); icon.className = 'gf-icon'; icon.textContent = glyph;
      const label = document.createElement('div'); label.className = 'gf-name'; label.textContent = name;
      div.appendChild(icon); div.appendChild(label);
      div.title = name;
      // Open in the same window — mobile users just swipe back to return.
      div.onclick = () => { window.location.href = a.url; };
      item.appendChild(div);
    }
    // Intuitive delete: × on each item (always shown on touch, on hover on desktop).
    const del = document.createElement('button');
    del.className = 'gal-del'; del.type = 'button'; del.title = __( 'Delete', 'noodled' ); del.setAttribute('aria-label', __( 'Delete attachment', 'noodled' )); del.innerHTML = '&#10005;';
    del.onclick = (e) => { e.stopPropagation(); deleteAttachment(a.id); };
    item.appendChild(del);
    gallery.appendChild(item);
  });
  host.insertBefore(gallery, host.firstChild);
}

// Lightbox for image attachments, captioned with stored EXIF.
let _lbImages = [], _lbIndex = 0;
function openLightbox(images, index) {
  if (!images || !images.length) return;
  _lbImages = images; _lbIndex = Math.max(0, index || 0);
  let lb = document.getElementById('noodledLightbox');
  if (!lb) {
    lb = document.createElement('div');
    lb.id = 'noodledLightbox';
    lb.className = 'noodled-lightbox';
    lb.innerHTML = `
      <button class="lb-close" onclick="closeLightbox()" aria-label="${escAttr(__( 'Close', 'noodled' ))}">&#10005;</button>
      <button class="lb-del" onclick="deleteLightboxImage()" aria-label="${escAttr(__( 'Delete', 'noodled' ))}">&#128465;&#65039;</button>
      <button class="lb-nav lb-prev" onclick="lightboxStep(-1)" aria-label="${escAttr(__( 'Previous', 'noodled' ))}">&#8249;</button>
      <div class="lb-stage"><img id="lbImg" alt=""><div class="lb-caption" id="lbCaption"></div></div>
      <button class="lb-nav lb-next" onclick="lightboxStep(1)" aria-label="${escAttr(__( 'Next', 'noodled' ))}">&#8250;</button>`;
    lb.onclick = (e) => { if (e.target === lb) closeLightbox(); };
    document.body.appendChild(lb);
    document.addEventListener('keydown', lightboxKey);
    setupLightboxGestures(lb);
  }
  lb.style.display = 'flex';
  renderLightbox();
}
function renderLightbox() {
  const a = _lbImages[_lbIndex];
  if (!a) return;
  lbResetZoom();
  const lbImg = document.getElementById('lbImg');
  lbImg.src = a.url;
  lbImg.alt = a.filename || a.name || __( 'Attachment image', 'noodled' );
  document.getElementById('lbCaption').innerHTML = lightboxCaption(a);
  const nav = _lbImages.length > 1;
  document.querySelectorAll('#noodledLightbox .lb-nav').forEach(b => b.style.display = nav ? '' : 'none');
}
function lightboxCaption(a) {
  const parts = ['<strong>' + esc(a.filename || a.name || '') + '</strong>'];
  const e = a.exif;
  if (e) {
    const bits = [];
    if (e.DateTimeOriginal) bits.push(esc(String(e.DateTimeOriginal)));
    if (e.Make || e.Model) bits.push(esc([e.Make, e.Model].filter(Boolean).join(' ')));
    if (e.FNumber) bits.push('f/' + esc(String(evalFrac(e.FNumber))));
    if (e.ExposureTime) bits.push(esc(String(e.ExposureTime)) + 's');
    if (e.ISO) bits.push('ISO ' + esc(String(e.ISO)));
    if (e.FocalLength) bits.push(esc(String(evalFrac(e.FocalLength))) + 'mm');
    if (e.GPSLatitude && e.GPSLongitude) bits.push('<a href="https://maps.google.com/?q=' + e.GPSLatitude + ',' + e.GPSLongitude + '" target="_blank" rel="noopener">📍 ' + esc(__( 'map', 'noodled' )) + '</a>');
    if (bits.length) parts.push('<span class="lb-exif">' + bits.join(' &middot; ') + '</span>');
  }
  return parts.join('<br>');
}
function evalFrac(v) {
  if (typeof v === 'string' && v.includes('/')) { const p = v.split('/'); return p[1] ? +(parseFloat(p[0]) / parseFloat(p[1])).toFixed(1) : v; }
  return v;
}
function lightboxStep(d) {
  if (!_lbImages.length) return;
  _lbIndex = (_lbIndex + d + _lbImages.length) % _lbImages.length;
  renderLightbox();
}
function closeLightbox() {
  const lb = document.getElementById('noodledLightbox');
  if (lb) lb.style.display = 'none';
}
async function deleteLightboxImage() {
  const a = _lbImages[_lbIndex];
  if (!a) return;
  if (!(await showConfirm(__( 'Delete this image?', 'noodled' ), { danger: true, okLabel: __( 'Delete', 'noodled' ) }))) return;
  try { await api.delete_attachment(a.id); }
  catch (e) { showToast(__( 'Delete failed', 'noodled' )); return; }
  if (activeNote && activeNote.attachments) activeNote.attachments = activeNote.attachments.filter(x => x.id !== a.id);
  _lbImages.splice(_lbIndex, 1);
  renderAttachmentGallery();
  if (!_lbImages.length) { closeLightbox(); showToast(__( 'Deleted', 'noodled' )); return; }
  _lbIndex = _lbIndex % _lbImages.length;
  renderLightbox();
  showToast(__( 'Deleted', 'noodled' ));
}
function lightboxKey(e) {
  const lb = document.getElementById('noodledLightbox');
  if (!lb || lb.style.display === 'none') return;
  if (e.key === 'Escape') closeLightbox();
  else if (e.key === 'ArrowLeft') lightboxStep(-1);
  else if (e.key === 'ArrowRight') lightboxStep(1);
}

// ── Remember last note on load ──
function restoreLastNote() {
  const lastId = localStorage.getItem('noodled_last_note');
  if (lastId) {
    const id = parseInt(lastId);
    const found = notes.find(n => n.id === id);
    if (found) selectNote(id);
  }
}

// ── Unsaved changes warning ──
let hasUnsavedChanges = false;

window.addEventListener('beforeunload', e => {
  if (hasUnsavedChanges) {
    e.preventDefault();
    e.returnValue = '';
  }
});

// ── Auto-sync on interval ──
let autoSyncInterval = null;

function startAutoSync(minutes = 5) {
  if (autoSyncInterval) clearInterval(autoSyncInterval);
  autoSyncInterval = setInterval(async () => {
    try {
      await api._fetch('/sync/pull', { method: 'POST' });
      await loadNotebooks();
      await loadNotes();
    } catch (e) {}
  }, minutes * 60 * 1000);
}

// ── Save queue for error recovery ──
let saveQueue = [];
let saveRetryTimer = null;

async function queuedSave(notebook, noteId, title, body, base) {
  try {
    showSaveIndicator('saving');
    const result = await api.save_note(notebook, noteId, title, body, { base });
    showSaveIndicator('saved');
    hasUnsavedChanges = false;
    return result;
  } catch (e) {
    // Offline / network error → persist the edit so it survives a reload and
    // flushes on reconnect. Keep only the latest pending edit per note.
    saveQueue = saveQueue.filter(q => q.noteId !== noteId);
    saveQueue.push({ notebook, noteId, title, body, time: Date.now() });
    persistSaveQueue();
    const indicator = document.getElementById('saveIndicator');
    if (indicator) indicator.className = 'save-indicator save-queued';
    if (!saveRetryTimer) {
      saveRetryTimer = setInterval(retrySaveQueue, 15000);
    }
    return { error: e.message };
  }
}

async function retrySaveQueue() {
  if (!saveQueue.length) {
    clearInterval(saveRetryTimer);
    saveRetryTimer = null;
    updateOfflineBanner();
    return;
  }
  const item = saveQueue[0];
  try {
    await api.save_note(item.notebook, item.noteId, item.title, item.body, { force: true });
    saveQueue.shift();
    persistSaveQueue();
    showSaveIndicator('saved');
    if (!saveQueue.length) {
      clearInterval(saveRetryTimer);
      saveRetryTimer = null;
      showToast(__( 'Synced pending changes', 'noodled' ));
    } else {
      retrySaveQueue();
    }
  } catch (e) {}
}

// ── Improved find with highlights ──
function highlightFindsInContent() {
  const q = document.getElementById('frFind')?.value;
  const el = document.getElementById('noteBody');
  if (!q || !el) return;
  // Remove old highlights
  el.querySelectorAll('.find-highlight').forEach(h => {
    h.replaceWith(document.createTextNode(h.textContent));
  });
  el.normalize();
  // Walk text nodes and wrap matches
  const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
  const matches = [];
  while (walker.nextNode()) {
    const node = walker.currentNode;
    const idx = node.textContent.toLowerCase().indexOf(q.toLowerCase());
    if (idx >= 0) matches.push({ node, idx, len: q.length });
  }
  let count = 0;
  matches.reverse().forEach(({ node, idx, len }) => {
    const range = document.createRange();
    range.setStart(node, idx);
    range.setEnd(node, idx + len);
    const span = document.createElement('span');
    span.className = 'find-highlight' + (count === 0 ? ' find-highlight-active' : '');
    range.surroundContents(span);
    count++;
  });
  /* translators: %d is the number of matches found */
  showToast(sprintf( _n( '%d match', '%d matches', matches.length, 'noodled' ), matches.length ));
  // Scroll to first match
  const first = el.querySelector('.find-highlight-active');
  if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// ── Loading skeletons ──
function showLoadingSkeleton() {
  const nb = document.getElementById('nbList');
  const nl = document.getElementById('noteList');
  if (nb) nb.innerHTML = Array(4).fill('<div class="skeleton skeleton-line"></div>').join('');
  if (nl) nl.innerHTML = Array(5).fill('<div style="padding:10px 12px"><div class="skeleton skeleton-title"></div><div class="skeleton skeleton-line" style="width:40%"></div><div class="skeleton skeleton-line" style="width:80%"></div></div>').join('');
}

// ── Logout ──
async function doLogout() {
  try { await api._fetch('/auth/logout', { method: 'POST' }); } catch (e) {}
  document.cookie = 'noodled_session=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  // Also log out of WordPress
  window.location.href = noodledConfig.appUrl || '/';
}

// ── Mobile helpers ──
function toggleSidebar() {
  const nb = document.querySelector('.col-notebooks');
  const ov = document.getElementById('sidebarOverlay');
  const isOpen = nb?.classList.toggle('open');
  ov?.classList.toggle('show', isOpen);
}

function closeSidebar() {
  document.querySelector('.col-notebooks')?.classList.remove('open');
  document.getElementById('sidebarOverlay')?.classList.remove('show');
}

function closeNote() {
  if (typeof dictating !== 'undefined' && dictating) stopDictation();
  document.querySelector('.col-content')?.classList.remove('open');
  activeNote = null;
  renderNoteList();
}

// Prevent note selection during scroll on touch devices
let _touchStartY = 0, _touchMoved = false;
document.addEventListener('touchstart', e => { _touchStartY = e.touches[0].clientY; _touchMoved = false; }, { passive: true });
document.addEventListener('touchmove', e => { if (Math.abs(e.touches[0].clientY - _touchStartY) > 10) _touchMoved = true; }, { passive: true });
function handleNoteTap(e, noteId) {
  if (_touchMoved) return;
  selectNote(noteId);
}

// ── Confirm dialog (replaces browser confirm) ──

// Override selectNote for mobile — show content panel
const _origSelectNote = selectNote;
selectNote = async function(noteId) {
  await _origSelectNote(noteId);
  document.querySelector('.col-content')?.classList.add('open');
  closeSidebar();
};

// Override selectNotebook for mobile — close sidebar
const _origSelectNotebook = selectNotebook;
selectNotebook = async function(name) {
  await _origSelectNotebook(name);
  closeSidebar();
};

// ── Swipe right to go back from note view ──
(function() {
  let sx = 0, sy = 0, tracking = false;
  document.addEventListener('touchstart', e => {
    const col = document.querySelector('.col-content.open');
    if (!col) return;
    sx = e.touches[0].clientX;
    sy = e.touches[0].clientY;
    tracking = sx < 40; // Only from left edge
  }, { passive: true });
  document.addEventListener('touchmove', e => {
    if (!tracking) return;
    const dx = e.touches[0].clientX - sx;
    const dy = Math.abs(e.touches[0].clientY - sy);
    if (dx > 80 && dy < 60) {
      tracking = false;
      closeNote();
    }
  }, { passive: true });
})();

/* ============================================================
   POLISH FEATURES
   ============================================================ */

// ── Command palette (⌘/Ctrl-K) ───────────────────────────────
function fuzzyScore(q, text) {
  q = (q || '').toLowerCase(); text = (text || '').toLowerCase();
  if (!q) return 1;
  let ti = 0, score = 0;
  for (let qi = 0; qi < q.length; qi++) {
    let found = -1;
    for (let k = ti; k < text.length; k++) { if (text[k] === q[qi]) { found = k; break; } }
    if (found < 0) return 0;
    score += (found === ti) ? 2 : 1;
    ti = found + 1;
  }
  return score;
}

function showCommandPalette() {
  const el = document.getElementById('modalContainer');
  const owner = !!(noodledConfig.user && noodledConfig.user.owner);
  const cmds = [
    { icon: '📝', label: __( 'New note', 'noodled' ), run: () => createNote() },
    { icon: '📄', label: __( 'New from template', 'noodled' ), run: () => showTemplates() },
    { icon: '📎', label: __( 'Add files', 'noodled' ), run: () => uploadFiles() },
    { icon: '🔍', label: __( 'Search notes', 'noodled' ), run: () => document.getElementById('searchInput').focus() },
    { icon: '🗓️', label: __( 'Daily journal', 'noodled' ), run: () => openDailyJournal() },
    { icon: '⚡', label: __( 'Quick capture', 'noodled' ), run: () => toggleQuickCapture() },
    { icon: '⏱️', label: __( 'Focus timer', 'noodled' ), run: () => showFocusOptions() },
    { icon: '🔄', label: __( 'Sync now', 'noodled' ), run: () => syncPull() },
    { icon: '🌓', label: __( 'Toggle theme', 'noodled' ), run: () => toggleTheme() },
    { icon: '⬇️', label: __( 'Export backup', 'noodled' ), run: () => exportBackup() },
    { icon: '⬆️', label: __( 'Import backup', 'noodled' ), run: () => importBackup() },
    { icon: '⌨️', label: __( 'Keyboard shortcuts', 'noodled' ), run: () => showShortcutsHelp() },
  ];
  if (owner) cmds.splice(3, 0, { icon: '👥', label: __( 'Manage people', 'noodled' ), run: () => manageUsers() });

  el.innerHTML = `<div class="modal-overlay cmdp-overlay"><div class="cmdp">
    <input id="cmdpInput" placeholder="${escAttr(__( 'Type a command or note…', 'noodled' ))}" autocomplete="off" aria-label="${escAttr(__( 'Command palette', 'noodled' ))}">
    <div class="cmdp-list" id="cmdpList"></div></div></div>`;
  const input = el.querySelector('#cmdpInput');
  const list = el.querySelector('#cmdpList');
  let results = [], hi = 0;

  function build() {
    const q = input.value.trim();
    let cm = cmds.map(c => ({ type: 'cmd', item: c, score: q ? fuzzyScore(q, c.label) : 1 })).filter(x => x.score > 0);
    cm.sort((a, b) => b.score - a.score);
    let nm = [];
    if (q) nm = notes.map(n => ({ type: 'note', item: n, score: fuzzyScore(q, n.title) }))
      .filter(x => x.score > 0).sort((a, b) => b.score - a.score).slice(0, 6);
    results = q ? [...cm.slice(0, 8), ...nm] : cm;
    hi = 0; render();
  }
  function render() {
    list.innerHTML = results.length ? results.map((r, i) => {
      const label = r.type === 'cmd' ? `${r.item.icon || ''} ${esc(r.item.label)}` : `📄 ${esc(r.item.title)}`;
      const sub = r.type === 'note' ? `<span class="cmdp-sub">${esc(r.item.notebook || '')}</span>` : '';
      return `<div class="cmdp-i ${i === hi ? 'on' : ''}" data-i="${i}">${label}${sub}</div>`;
    }).join('') : '<div class="cmdp-empty">' + esc(__( 'No matches', 'noodled' )) + '</div>';
    const on = list.querySelector('.cmdp-i.on');
    if (on) on.scrollIntoView({ block: 'nearest' });
  }
  function run(r) { document.getElementById('modalContainer').innerHTML = ''; if (!r) return; if (r.type === 'cmd') r.item.run(); else selectNote(r.item.id); }

  input.addEventListener('input', build);
  input.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, results.length - 1); render(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); render(); }
    else if (e.key === 'Enter') { e.preventDefault(); run(results[hi]); }
  });
  list.addEventListener('mousedown', e => { const it = e.target.closest('.cmdp-i'); if (it) run(results[+it.dataset.i]); });
  build();
  setTimeout(() => input.focus(), 30);
}

// ── Conflict resolution (last-write-wins safety net) ─────────
let _conflictOpen = false;
function showConflict(server, mine) {
  if (!server || _conflictOpen) return;
  _conflictOpen = true;
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay"><div class="modal" style="max-width:460px">
    <h3>${esc(__( 'This note changed elsewhere', 'noodled' ))}</h3>
    <p style="font-size:13px;color:var(--text-muted);line-height:1.5">${sprintf( /* translators: %s is the note title */ __( 'Another device or tab saved "%s" after you opened it. Which version do you want to keep?', 'noodled' ), '<b>' + esc(server.title || '') + '</b>' )}</p>
    <div class="modal-buttons">
      <button class="btn btn-sm" id="cfTheirs">${esc(__( 'Load theirs', 'noodled' ))}</button>
      <button class="btn btn-sm btn-accent" id="cfMine">${esc(__( 'Keep mine', 'noodled' ))}</button>
    </div></div></div>`;
  el.querySelector('#cfMine').onclick = async () => {
    el.innerHTML = ''; _conflictOpen = false;
    const r = await api.save_note(activeNote.notebook, activeNote.id, mine.title, mine.body, { force: true });
    if (r && !r.error) { adoptSaved(r); await loadNotes(); renderContent(); showToast(__( 'Kept your version', 'noodled' )); }
  };
  el.querySelector('#cfTheirs').onclick = async () => {
    el.innerHTML = ''; _conflictOpen = false;
    try { activeNote = await api.get_note(null, server.id); } catch (e) {}
    await loadNotes(); renderContent(); showToast(__( 'Loaded the other version', 'noodled' ));
  };
}

// ── Note-list swipe gestures (left = trash, right = pin) ─────
function setupNoteSwipe() {
  const list = document.getElementById('noteList');
  if (!list || list._swipeBound) return;
  list._swipeBound = true;
  let item = null, id = 0, sx = 0, sy = 0, dx = 0, active = false;
  list.addEventListener('touchstart', e => {
    if (bulkMode) return;
    item = e.target.closest('.note-item'); if (!item) return;
    id = +item.dataset.noteId; sx = e.touches[0].clientX; sy = e.touches[0].clientY; dx = 0; active = true;
    item.style.transition = 'none';
  }, { passive: true });
  list.addEventListener('touchmove', e => {
    if (!active || !item) return;
    dx = e.touches[0].clientX - sx;
    const dy = Math.abs(e.touches[0].clientY - sy);
    if (Math.abs(dx) < 8 && dy < 8) return;
    if (dy > Math.abs(dx)) { active = false; item.style.transform = ''; item.classList.remove('swipe-del', 'swipe-pin'); return; }
    item.style.transform = `translateX(${Math.max(-130, Math.min(130, dx))}px)`;
    item.classList.toggle('swipe-del', dx < -45);
    item.classList.toggle('swipe-pin', dx > 45);
  }, { passive: true });
  function end() {
    if (!active || !item) { active = false; return; }
    const el = item, nid = id, d = dx;
    el.style.transition = 'transform .18s ease';
    el.style.transform = '';
    el.classList.remove('swipe-del', 'swipe-pin');
    active = false; item = null;
    if (d < -95) { _touchMoved = true; swipeTrash(nid); }
    else if (d > 95) { _touchMoved = true; swipePin(nid); }
  }
  list.addEventListener('touchend', end);
  list.addEventListener('touchcancel', end);
}
async function swipeTrash(id) {
  const n = notes.find(x => x.id === id); if (!n) return;
  try { await api.delete_note(n.notebook, id); } catch (e) { showToast(__( 'Delete failed', 'noodled' )); return; }
  if (activeNote && activeNote.id === id) { activeNote = null; renderContent(); document.querySelector('.col-content')?.classList.remove('open'); }
  await loadNotebooks(); await loadNotes(); updateTrashCount();
  showUndoToast(__( 'Note moved to trash', 'noodled' ), () => undoDelete(id));
}
async function swipePin(id) {
  const n = notes.find(x => x.id === id); if (!n) return;
  try { await api.toggle_pin(n.notebook, id); } catch (e) { return; }
  showToast(n.pinned ? __( 'Unpinned', 'noodled' ) : __( 'Pinned', 'noodled' ));
  await loadNotes();
}

// ── Modal accessibility: focus trap, ESC, restore focus ──────
let _lastModalFocus = null;
function setupModalA11y() {
  const mc = document.getElementById('modalContainer');
  if (!mc || mc._a11yBound) return;
  mc._a11yBound = true;
  const obs = new MutationObserver(() => {
    const overlay = mc.querySelector('.modal-overlay');
    if (overlay && !overlay._a11y) {
      overlay._a11y = true;
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      _lastModalFocus = document.activeElement;
      const f = overlay.querySelector('input,textarea,button,select,[tabindex]');
      if (f) setTimeout(() => { try { f.focus(); } catch (e) {} }, 30);
    } else if (!overlay && _lastModalFocus) {
      try { _lastModalFocus.focus(); } catch (e) {}
      _lastModalFocus = null;
    }
  });
  obs.observe(mc, { childList: true });
  document.addEventListener('keydown', e => {
    const overlay = mc.querySelector('.modal-overlay');
    if (!overlay) return;
    if (e.key === 'Escape') { mc.innerHTML = ''; _conflictOpen = false; return; }
    if (e.key !== 'Tab') return;
    const f = [...overlay.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),textarea:not([disabled]),select:not([disabled]),[tabindex]:not([tabindex="-1"])')].filter(el => el.offsetParent !== null);
    if (!f.length) return;
    const first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });
}

// ── Lightbox pinch-zoom / pan / swipe ────────────────────────
let _lbZoom = { scale: 1, tx: 0, ty: 0 };
function lbApply() { const img = document.getElementById('lbImg'); if (img) img.style.transform = `translate(${_lbZoom.tx}px,${_lbZoom.ty}px) scale(${_lbZoom.scale})`; }
function lbResetZoom() { _lbZoom = { scale: 1, tx: 0, ty: 0 }; lbApply(); }
function setupLightboxGestures(lb) {
  const stage = lb.querySelector('.lb-stage');
  const img = lb.querySelector('#lbImg');
  if (!stage || !img || stage._gesturesBound) return;
  stage._gesturesBound = true;
  img.style.touchAction = 'none';
  const pts = new Map();
  let startDist = 0, startScale = 1, lastX = 0, lastY = 0, swipeX = 0, panning = false;
  img.addEventListener('dblclick', () => { _lbZoom.scale = _lbZoom.scale > 1 ? 1 : 2; _lbZoom.tx = 0; _lbZoom.ty = 0; lbApply(); });
  stage.addEventListener('pointerdown', e => {
    pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
    try { img.setPointerCapture(e.pointerId); } catch (_) {}
    if (pts.size === 2) { const a = [...pts.values()]; startDist = Math.hypot(a[0].x - a[1].x, a[0].y - a[1].y); startScale = _lbZoom.scale; }
    else { lastX = e.clientX; lastY = e.clientY; swipeX = e.clientX; panning = _lbZoom.scale > 1; }
  });
  stage.addEventListener('pointermove', e => {
    if (!pts.has(e.pointerId)) return;
    pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pts.size === 2) {
      const a = [...pts.values()]; const d = Math.hypot(a[0].x - a[1].x, a[0].y - a[1].y);
      if (startDist) { _lbZoom.scale = Math.max(1, Math.min(4, startScale * (d / startDist))); lbApply(); }
    } else if (panning) {
      _lbZoom.tx += e.clientX - lastX; _lbZoom.ty += e.clientY - lastY; lastX = e.clientX; lastY = e.clientY; lbApply();
    }
  });
  function up(e) {
    const had2 = pts.size === 2;
    pts.delete(e.pointerId);
    if (!had2 && _lbZoom.scale <= 1) {
      const dx = e.clientX - swipeX;
      if (Math.abs(dx) > 60) lightboxStep(dx < 0 ? 1 : -1);
      _lbZoom.tx = 0; _lbZoom.ty = 0; lbApply();
    }
    if (pts.size < 2) startDist = 0;
    if (pts.size === 0 && _lbZoom.scale <= 1) panning = false;
  }
  stage.addEventListener('pointerup', up);
  stage.addEventListener('pointercancel', e => { pts.delete(e.pointerId); });
}

// ── Search depth: recent searches dropdown ───────────────────
const RECENT_SEARCH_KEY = 'noodled_recent_searches';
function getRecentSearches() { try { return JSON.parse(localStorage.getItem(RECENT_SEARCH_KEY) || '[]'); } catch (e) { return []; } }
function addRecentSearch(q) {
  q = (q || '').trim(); if (q.length < 2) return;
  let r = getRecentSearches().filter(x => x.toLowerCase() !== q.toLowerCase());
  r.unshift(q); r = r.slice(0, 6);
  try { localStorage.setItem(RECENT_SEARCH_KEY, JSON.stringify(r)); } catch (e) {}
}
function setupSearchRecents() {
  const inp = document.getElementById('searchInput');
  if (!inp || inp._recentsBound) return;
  inp._recentsBound = true;
  const box = inp.closest('.search-box') || inp.parentNode;
  box.style.position = 'relative';
  const dd = document.createElement('div'); dd.className = 'search-recent'; dd.id = 'searchRecent';
  box.appendChild(dd);
  function render() {
    const r = getRecentSearches();
    if (!r.length || inp.value) { dd.classList.remove('show'); return; }
    dd.innerHTML = '<div class="sr-h">' + esc(__( 'Recent searches', 'noodled' )) + '</div>' + r.map(q => `<div class="sr-i" data-q="${escAttr(q)}">${esc(q)}</div>`).join('');
    dd.classList.add('show');
  }
  inp.addEventListener('focus', render);
  inp.addEventListener('input', () => dd.classList.remove('show'));
  inp.addEventListener('blur', () => setTimeout(() => dd.classList.remove('show'), 160));
  inp.addEventListener('keydown', e => { if (e.key === 'Enter') addRecentSearch(inp.value); });
  dd.addEventListener('mousedown', e => {
    const it = e.target.closest('.sr-i'); if (!it) return;
    inp.value = it.dataset.q; filterNotes(); addRecentSearch(it.dataset.q); dd.classList.remove('show');
  });
}

// ── Full backup export / import ──────────────────────────────
function exportBackup() {
  const url = api._base + '/export?_wpnonce=' + encodeURIComponent(api._nonce);
  const a = document.createElement('a');
  a.href = url; a.rel = 'noopener';
  document.body.appendChild(a); a.click(); a.remove();
  showToast(__( 'Preparing your backup…', 'noodled' ));
}
function importBackup() {
  let inp = document.getElementById('backupImportInput');
  if (!inp) {
    inp = document.createElement('input');
    inp.type = 'file'; inp.accept = '.zip'; inp.id = 'backupImportInput'; inp.style.display = 'none';
    inp.onchange = () => handleBackupImport(inp);
    document.body.appendChild(inp);
  }
  inp.value = ''; inp.click();
}
async function handleBackupImport(input) {
  const f = input.files && input.files[0];
  if (!f) return;
  showToast(__( 'Importing backup…', 'noodled' ));
  const fd = new FormData(); fd.append('file', f);
  try {
    const res = await fetch(api._base + '/import/zip', { method: 'POST', headers: { 'X-WP-Nonce': api._nonce }, credentials: 'same-origin', body: fd }).then(r => r.json());
    /* translators: %s is the error message */
    if (res && res.error) { showToast(sprintf( __( 'Import failed: %s', 'noodled' ), res.error )); return; }
    await loadNotebooks(); await loadNotes();
    const n = (res && res.imported) || 0;
    /* translators: %d is the number of notes imported */
    showToast(sprintf( _n( 'Imported %d note', 'Imported %d notes', n, 'noodled' ), n ));
  } catch (e) { showToast(__( 'Import failed', 'noodled' )); }
}

// ── Wire up the polish features once the DOM is ready ────────
function noodledPolishInit() {
  loadSaveQueue();
  setupModalA11y();
  setupNoteSwipe();
  setupSearchRecents();
  const toast = document.getElementById('toast');
  if (toast) { toast.setAttribute('role', 'status'); toast.setAttribute('aria-live', 'polite'); }
  const status = document.getElementById('status');
  if (status) status.setAttribute('aria-live', 'polite');
  window.addEventListener('online', () => setOnline(true));
  window.addEventListener('offline', () => setOnline(false));
  // Editor slash-commands + autocomplete (delegated so they survive rerenders).
  document.addEventListener('keydown', editorSlashKey);
  document.addEventListener('keydown', acNav, true); // capture: handle before the editor's own Enter
  document.addEventListener('input', editorAutocomplete);
  document.addEventListener('keydown', noteNavKey);   // arrow-key note-list navigation
  // Make any element we expose with role=button/menuitem/option operable by
  // keyboard: Enter or Space fires its click (skips native controls).
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
    const t = e.target;
    if (!t || typeof t.matches !== 'function') return;
    if (!t.matches('[role="button"], [role="menuitem"], [role="option"]')) return;
    if (t.matches('a[href], button, input, textarea, select')) return;
    e.preventDefault();
    t.click();
  });
  setupInstallPrompt();
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', noodledPolishInit);
else noodledPolishInit();

/* ============================================================
   POLISH FEATURES — ROUND 3
   ============================================================ */

// ── Code blocks: lightweight highlighting + copy button ──────
function codeBlockHtml(lines, lang) {
  const code = lines.join('\n');
  const label = lang ? `<span class="cb-lang">${esc(lang)}</span>` : '<span class="cb-lang"></span>';
  return `<div class="code-block"><div class="cb-head" contenteditable="false">${label}`
    + `<button class="cb-copy" type="button" onclick="copyCodeBlock(this)">${esc(__( 'Copy', 'noodled' ))}</button></div>`
    + `<pre><code>${highlightCode(code)}</code></pre></div>`;
}
function highlightCode(code) {
  const e = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const KW = /^(function|const|let|var|return|if|else|elif|for|while|do|switch|case|break|continue|class|new|this|typeof|instanceof|import|export|from|as|default|async|await|try|catch|finally|throw|extends|super|yield|public|private|protected|static|void|int|string|bool|boolean|float|double|def|lambda|None|True|False|nil|fn|use|pub|mut|match|impl|struct|enum|interface|type|namespace|echo|print|require|require_once|include|foreach|endforeach|endif|endwhile|null|true|false|undefined|in|of|is|not|and|or)$/;
  const re = /(\/\/[^\n]*|\/\*[\s\S]*?\*\/)|("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|`(?:\\.|[^`\\])*`)|(\b\d[\d_.]*\b)|([A-Za-z_$][\w$]*)/g;
  let out = '', last = 0, m;
  while ((m = re.exec(code))) {
    out += e(code.slice(last, m.index));
    if (m[1]) out += `<span class="tok-com">${e(m[1])}</span>`;
    else if (m[2]) out += `<span class="tok-str">${e(m[2])}</span>`;
    else if (m[3]) out += `<span class="tok-num">${e(m[3])}</span>`;
    else out += KW.test(m[4]) ? `<span class="tok-kw">${e(m[4])}</span>` : e(m[4]);
    last = re.lastIndex;
  }
  out += e(code.slice(last));
  return out;
}
function copyCodeBlock(btn) {
  const block = btn.closest('.code-block');
  const code = block && block.querySelector('code');
  if (!code) return;
  navigator.clipboard.writeText(code.innerText).then(() => {
    btn.textContent = __( 'Copied', 'noodled' ); setTimeout(() => btn.textContent = __( 'Copy', 'noodled' ), 1500);
  }).catch(() => showToast(__( 'Copy failed', 'noodled' )));
}

// ── Note-list keyboard navigation (↑/↓ select, Enter open, Del trash) ──
let _navIndex = -1;
function noteNavKey(e) {
  const tag = e.target.tagName;
  if (e.target.isContentEditable || tag === 'INPUT' || tag === 'TEXTAREA') return;
  if (e.ctrlKey || e.metaKey || e.altKey) return;
  if (document.querySelector('#modalContainer .modal-overlay')) return; // a dialog owns the keys
  if (!filteredNotes.length) return;
  if (_navIndex >= filteredNotes.length) _navIndex = filteredNotes.length - 1;
  if (e.key === 'ArrowDown') { e.preventDefault(); _navIndex = Math.min((_navIndex < 0 ? -1 : _navIndex) + 1, filteredNotes.length - 1); paintNav(); }
  else if (e.key === 'ArrowUp') { e.preventDefault(); _navIndex = Math.max((_navIndex < 0 ? 0 : _navIndex) - 1, 0); paintNav(); }
  else if (e.key === 'Enter' && _navIndex >= 0) { e.preventDefault(); selectNote(filteredNotes[_navIndex].id); }
  else if ((e.key === 'Delete' || e.key === 'Backspace') && _navIndex >= 0) { e.preventDefault(); deleteNote(filteredNotes[_navIndex].id); }
}
function paintNav() {
  document.querySelectorAll('.note-item.nav-focus').forEach(el => el.classList.remove('nav-focus'));
  const n = filteredNotes[_navIndex]; if (!n) return;
  const el = document.querySelector(`.note-item[data-note-id="${n.id}"]`);
  if (el) { el.classList.add('nav-focus'); el.scrollIntoView({ block: 'nearest' }); }
}

// ── Editor reading preferences ───────────────────────────────
function applyReadingPrefs() {
  const p = (config && config.editor_prefs) || {};
  const root = document.documentElement;
  root.style.setProperty('--editor-font', (p.size || 16) + 'px');
  root.style.setProperty('--editor-lh', p.lh || 1.7);
  document.body.classList.toggle('editor-serif', !!p.serif);
  document.body.classList.toggle('editor-narrow', !!p.narrow);
}
function showReadingPrefs() {
  const p = (config.editor_prefs = config.editor_prefs || {});
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal" style="min-width:320px"><h3>${esc(__( 'Reading preferences', 'noodled' ))}</h3>
      <label style="display:block;font-size:12px;color:var(--text-muted);margin:10px 0 4px">${esc(__( 'Font size', 'noodled' ))}</label>
      <input type="range" id="rpSize" aria-label="${escAttr(__( 'Font size', 'noodled' ))}" min="13" max="22" step="1" value="${p.size || 16}" style="width:100%">
      <label style="display:block;font-size:12px;color:var(--text-muted);margin:10px 0 4px">${esc(__( 'Line height', 'noodled' ))}</label>
      <input type="range" id="rpLh" aria-label="${escAttr(__( 'Line height', 'noodled' ))}" min="1.3" max="2.2" step="0.05" value="${p.lh || 1.7}" style="width:100%">
      <label style="display:flex;align-items:center;gap:8px;margin:14px 0 6px"><input type="checkbox" id="rpSerif" ${p.serif ? 'checked' : ''}> ${esc(__( 'Serif body font', 'noodled' ))}</label>
      <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" id="rpNarrow" ${p.narrow ? 'checked' : ''}> ${esc(__( 'Narrow reading column', 'noodled' ))}</label>
      <div class="modal-buttons" style="margin-top:16px"><button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">${esc(__( 'Done', 'noodled' ))}</button></div>
    </div></div>`;
  const upd = () => {
    p.size = +document.getElementById('rpSize').value;
    p.lh = +document.getElementById('rpLh').value;
    p.serif = document.getElementById('rpSerif').checked;
    p.narrow = document.getElementById('rpNarrow').checked;
    applyReadingPrefs();
    clearTimeout(showReadingPrefs._t);
    showReadingPrefs._t = setTimeout(() => api.set_config('editor_prefs', p).catch(() => {}), 400);
  };
  ['rpSize', 'rpLh', 'rpSerif', 'rpNarrow'].forEach(id => document.getElementById(id).addEventListener('input', upd));
}

// ── Tag manager (rename / delete a tag across all notes) ─────
function manageTags() {
  const menu = document.getElementById('appMenu'); if (menu) menu.classList.remove('show');
  const tags = getAllTags();
  const counts = {};
  notes.forEach(n => ((n.body || '').match(/(^|\s)#([a-zA-Z][\w-]*)/g) || []).forEach(t => { const k = t.trim().slice(1); counts[k] = (counts[k] || 0) + 1; }));
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal" style="min-width:360px"><h3>${esc(__( 'Manage tags', 'noodled' ))}</h3>
      <div style="max-height:50vh;overflow:auto">${tags.length ? tags.map(t => `
        <div class="mt-row" style="display:flex;align-items:center;gap:8px;padding:7px 2px;border-bottom:1px solid var(--border)">
          <span style="flex:1">#${esc(t)} <span style="color:var(--text-muted);font-size:11px">${counts[t] || 0}</span></span>
          <button class="btn btn-sm" onclick="renameTag('${esc(t)}')">${esc(__( 'Rename', 'noodled' ))}</button>
          <button class="btn btn-sm" style="color:var(--red)" onclick="deleteTag('${esc(t)}')">${esc(__( 'Delete', 'noodled' ))}</button>
        </div>`).join('') : '<p style="color:var(--text-muted);font-size:13px">' + esc(__( 'No tags yet.', 'noodled' )) + '</p>'}</div>
      <div class="modal-buttons" style="margin-top:14px"><button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">${esc(__( 'Close', 'noodled' ))}</button></div>
    </div></div>`;
}
function _tagRe(tag) { return new RegExp('(^|\\s)#' + tag.replace(/[.*+?^${}()|[\]\\-]/g, '\\$&') + '(?![\\w-])', 'g'); }
async function _rewriteTag(tag, replacer, doneMsg) {
  const hits = notes.filter(n => _tagRe(tag).test(n.body || ''));
  if (!hits.length) { showToast(__( 'No notes use that tag', 'noodled' )); return; }
  document.getElementById('modalContainer').innerHTML = '';
  /* translators: %d is the number of notes being updated */
  showToast(sprintf( _n( 'Updating %d note…', 'Updating %d notes…', hits.length, 'noodled' ), hits.length ));
  for (const n of hits) {
    const body = (n.body || '').replace(_tagRe(tag), replacer);
    try { await api.save_note(n.notebook, n.id, n.title, body, { force: true }); } catch (e) {}
  }
  await loadNotes();
  if (activeNote && hits.some(h => h.id === activeNote.id)) { try { activeNote = await api.get_note(null, activeNote.id); renderContent(); } catch (e) {} }
  showToast(doneMsg);
}
async function renameTag(tag) {
  /* translators: %s is the tag name */
  const next = (await showPrompt(__( 'Rename tag', 'noodled' ), sprintf( __( 'New name for #%s', 'noodled' ), tag ), tag) || '').trim().replace(/^#/, '');
  if (!next || next === tag) return;
  const clean = next.replace(/[^\w-]/g, '');
  /* translators: %1$s is the old tag name, %2$s is the new tag name */
  await _rewriteTag(tag, (m, pre) => pre + '#' + clean, sprintf( __( 'Renamed #%1$s → #%2$s', 'noodled' ), tag, clean ));
}
async function deleteTag(tag) {
  /* translators: %s is the tag name */
  if (!(await showConfirm(sprintf( __( 'Remove #%s from all notes? (The notes stay; only the tag is removed.)', 'noodled' ), tag ), { danger: true, okLabel: __( 'Remove', 'noodled' ) }))) return;
  /* translators: %s is the tag name */
  await _rewriteTag(tag, (m, pre) => pre, sprintf( __( 'Removed #%s', 'noodled' ), tag ));
}

// ── Save the current note as a reusable template ─────────────
async function saveAsTemplate() {
  if (!activeNote) { showToast(__( 'Open a note first', 'noodled' )); return; }
  const name = (await showPrompt(__( 'Save as template', 'noodled' ), __( 'Template name:', 'noodled' ), activeNote.title || '') || '').trim();
  if (!name) return;
  const tpls = (config.templates && config.templates.length) ? config.templates.slice() : defaultTemplates.slice();
  tpls.push({ name, body: activeNote.body || '', custom: true });
  config.templates = tpls;
  api.set_config('templates', tpls).catch(() => {});
  showToast(__( 'Template saved', 'noodled' ));
}
function deleteTemplate(i) {
  const tpls = (config.templates && config.templates.length) ? config.templates.slice() : defaultTemplates.slice();
  if (!tpls[i]) return;
  tpls.splice(i, 1);
  config.templates = tpls;
  api.set_config('templates', tpls).catch(() => {});
  showTemplates();
}

// ── PWA install prompt ───────────────────────────────────────
let _deferredInstall = null;
function setupInstallPrompt() {
  window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    _deferredInstall = e;
    if (localStorage.getItem('noodled_install_dismissed')) return;
    showInstallBanner();
  });
  window.addEventListener('appinstalled', () => { hideInstallBanner(); _deferredInstall = null; });
}
function showInstallBanner() {
  let b = document.getElementById('installBanner');
  if (!b) { b = document.createElement('div'); b.id = 'installBanner'; b.className = 'install-banner'; document.body.appendChild(b); }
  /* translators: %s is the app/brand name */
  b.innerHTML = `<span>${sprintf( __( 'Install %s for quick access & offline notes', 'noodled' ), esc(noodledConfig.brandName || 'noodled') )}</span>
    <button class="btn btn-sm btn-accent" id="installYes">${esc(__( 'Install', 'noodled' ))}</button>
    <button class="btn btn-sm" id="installNo">${esc(__( 'Not now', 'noodled' ))}</button>`;
  b.querySelector('#installYes').onclick = doInstall;
  b.querySelector('#installNo').onclick = () => { localStorage.setItem('noodled_install_dismissed', '1'); hideInstallBanner(); };
  b.classList.add('show');
}
function hideInstallBanner() { const b = document.getElementById('installBanner'); if (b) b.classList.remove('show'); }
async function doInstall() {
  if (!_deferredInstall) return;
  _deferredInstall.prompt();
  try { await _deferredInstall.userChoice; } catch (e) {}
  _deferredInstall = null; hideInstallBanner();
}

/* ── Undo-delete toast ─────────────────────────────────────── */
function showUndoToast(msg, onUndo) {
  let t = document.getElementById('undoToast');
  if (!t) { t = document.createElement('div'); t.id = 'undoToast'; t.className = 'undo-toast'; document.body.appendChild(t); }
  t.innerHTML = `<span class="ut-msg"></span><button type="button" class="ut-btn">${esc(__( 'Undo', 'noodled' ))}</button>`;
  t.querySelector('.ut-msg').textContent = msg;
  let done = false;
  t.querySelector('.ut-btn').onclick = async () => { if (done) return; done = true; t.classList.remove('show'); try { await onUndo(); } catch (e) {} };
  t.classList.add('show');
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove('show'), 6000);
}
async function undoDelete(id) {
  try { await api.restore_note(id); } catch (e) { showToast(__( 'Undo failed', 'noodled' )); return; }
  await loadNotebooks(); await loadNotes(); updateTrashCount();
  showToast(__( 'Restored', 'noodled' ));
}

/* ── Recent-note quick switcher (Ctrl-E) ───────────────────── */
function showRecentSwitcher() {
  const ids = _recentViewed.filter(id => !activeNote || id !== activeNote.id);
  const items = ids.map(id => notes.find(n => n.id === id)).filter(Boolean).slice(0, 8);
  if (!items.length) { showToast(__( 'No recent notes yet', 'noodled' )); return; }
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay cmdp-overlay"><div class="cmdp"><div class="cmdp-head">${esc(__( 'Recent notes', 'noodled' ))}</div><div class="cmdp-list" id="rsList">${items.map((n, i) => `<div class="cmdp-i ${i === 0 ? 'on' : ''}" data-id="${n.id}">📄 ${esc(n.title)}<span class="cmdp-sub">${esc(n.notebook || '')}</span></div>`).join('')}</div></div></div>`;
  const list = el.querySelector('#rsList');
  let hi = 0;
  const rows = [...list.querySelectorAll('.cmdp-i')];
  function paint() { rows.forEach((r, i) => r.classList.toggle('on', i === hi)); }
  function go(i) { const r = rows[i]; document.getElementById('modalContainer').innerHTML = ''; if (r) selectNote(+r.dataset.id); }
  list.addEventListener('mousedown', e => { const it = e.target.closest('.cmdp-i'); if (it) go(rows.indexOf(it)); });
  const onKey = e => {
    if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, rows.length - 1); paint(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); paint(); }
    else if (e.key === 'Enter') { e.preventDefault(); document.removeEventListener('keydown', onKey, true); go(hi); }
    else if (e.key === 'Escape') { document.removeEventListener('keydown', onKey, true); }
  };
  document.addEventListener('keydown', onKey, true);
}

/* ── Editor slash commands ─────────────────────────────────── */
function editorSlashKey(e) {
  if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
  const el = document.getElementById('noteBody');
  if (!el || document.activeElement !== el) return;
  const sel = window.getSelection();
  if (!sel.rangeCount) return;
  const r = sel.getRangeAt(0);
  const node = r.startContainer;
  const before = node.nodeType === 3 ? node.textContent.slice(0, r.startOffset) : '';
  if (before.trim() !== '') return; // only at the start of an empty line
  e.preventDefault();
  let rect; try { rect = r.getBoundingClientRect(); } catch (_) {}
  const x = (rect && rect.left) || 120, y = (rect && rect.bottom) || 160;
  openSlashMenu(x, y);
}
function appendBlock(md) {
  const el = document.getElementById('noteBody');
  if (!el || !activeNote) return;
  const body = htmlToMarkdown(el);
  activeNote.body = (body ? body.replace(/\s+$/, '') + '\n\n' : '') + md + '\n';
  saveAndRerender();
}
function openSlashMenu(x, y) {
  const opts = [
    { label: __( 'Heading', 'noodled' ), icon: 'H', run: () => insertHeading() },
    { label: __( 'Checklist', 'noodled' ), icon: '☑', run: () => insertChecklistItem() },
    { label: __( 'Bullet list', 'noodled' ), icon: '•', run: () => insertBullet() },
    { label: __( 'Divider', 'noodled' ), icon: '―', run: () => appendBlock('---') },
    /* translators: %s is a column header placeholder for a new table */
    { label: __( 'Table', 'noodled' ), icon: '▦', run: () => appendBlock(`| ${__( 'Column', 'noodled' )} | ${__( 'Column', 'noodled' )} |\n| --- | --- |\n|  |  |`) },
    { label: __( "Today's date", 'noodled' ), icon: '🗓', run: () => appendBlock(new Date().toLocaleDateString()) },
  ];
  let menu = document.getElementById('slashMenu');
  if (menu) menu.remove();
  menu = document.createElement('div');
  menu.id = 'slashMenu'; menu.className = 'slash-menu';
  menu.innerHTML = opts.map((o, i) => `<div class="slash-i ${i === 0 ? 'on' : ''}" data-i="${i}"><span class="slash-ic">${o.icon}</span>${o.label}</div>`).join('');
  document.body.appendChild(menu);
  const vw = window.innerWidth, vh = window.innerHeight;
  menu.style.left = Math.min(x, vw - menu.offsetWidth - 12) + 'px';
  menu.style.top = Math.min(y + 6, vh - menu.offsetHeight - 12) + 'px';
  let hi = 0;
  const rows = [...menu.querySelectorAll('.slash-i')];
  const paint = () => rows.forEach((r, i) => r.classList.toggle('on', i === hi));
  const close = () => { menu.remove(); document.removeEventListener('keydown', onKey, true); };
  const pick = i => { close(); opts[i] && opts[i].run(); };
  const onKey = e => {
    if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(hi + 1, rows.length - 1); paint(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(hi - 1, 0); paint(); }
    else if (e.key === 'Enter') { e.preventDefault(); pick(hi); }
    else if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key.length === 1 || e.key === 'Backspace') { close(); } // typing dismisses it
  };
  document.addEventListener('keydown', onKey, true);
  menu.addEventListener('mousedown', e => { const it = e.target.closest('.slash-i'); if (it) { e.preventDefault(); pick(+it.dataset.i); } });
}

/* ── Editor [[wikilink]] / #tag autocomplete ───────────────── */
let _acState = null;
function allTags() {
  const set = new Set();
  notes.forEach(n => { const m = (n.body || '').match(/(^|\s)#([a-zA-Z][\w-]*)/g) || []; m.forEach(t => set.add(t.trim().slice(1))); });
  return [...set];
}
function editorAutocomplete() {
  const el = document.getElementById('noteBody');
  if (!el || document.activeElement !== el) { hideAutocomplete(); return; }
  const sel = window.getSelection();
  if (!sel.rangeCount) { hideAutocomplete(); return; }
  const r = sel.getRangeAt(0);
  const node = r.startContainer;
  if (node.nodeType !== 3) { hideAutocomplete(); return; }
  const before = node.textContent.slice(0, r.startOffset);
  let m;
  if ((m = before.match(/\[\[([^\]]*)$/))) {
    const q = m[1].toLowerCase();
    const matches = notes.map(n => n.title).filter(t => t && t.toLowerCase().includes(q)).slice(0, 6);
    showAutocomplete('link', m[1], matches, r);
  } else if ((m = before.match(/(?:^|\s)#([a-zA-Z][\w-]*)$/))) {
    const q = m[1].toLowerCase();
    const matches = allTags().filter(t => t.toLowerCase().startsWith(q) && t.toLowerCase() !== q).slice(0, 6);
    showAutocomplete('tag', m[1], matches, r);
  } else { hideAutocomplete(); }
}
function showAutocomplete(type, typed, matches, range) {
  if (!matches.length) { hideAutocomplete(); return; }
  let box = document.getElementById('acBox');
  if (!box) { box = document.createElement('div'); box.id = 'acBox'; box.className = 'ac-box'; document.body.appendChild(box); }
  _acState = { type, typed, matches, hi: 0 };
  box.innerHTML = matches.map((mtext, i) => `<div class="ac-i ${i === 0 ? 'on' : ''}" data-i="${i}">${type === 'link' ? '🔗' : '#'} ${esc(mtext)}</div>`).join('');
  let rect; try { rect = range.getBoundingClientRect(); } catch (_) {}
  const x = (rect && rect.left) || 120, y = (rect && rect.bottom) || 160;
  box.style.left = Math.min(x, window.innerWidth - box.offsetWidth - 12) + 'px';
  box.style.top = (y + 6) + 'px';
  box.classList.add('show');
  box.onmousedown = e => { const it = e.target.closest('.ac-i'); if (it) { e.preventDefault(); applyAutocomplete(+it.dataset.i); } };
}
function hideAutocomplete() { const box = document.getElementById('acBox'); if (box) box.classList.remove('show'); _acState = null; }
function applyAutocomplete(i) {
  if (!_acState) return;
  const choice = _acState.matches[i != null ? i : _acState.hi];
  if (choice == null) { hideAutocomplete(); return; }
  // Insert the missing suffix so "[[Dish" → "[[Dishwasher]]" / "#dro" → "#drones ".
  const suffix = _acState.type === 'link'
    ? choice.slice(_acState.typed.length) + ']]'
    : choice.slice(_acState.typed.length) + ' ';
  try { document.execCommand('insertText', false, suffix); } catch (_) {}
  hideAutocomplete();
  if (typeof schedSave === 'function') schedSave();
}
// Capture-phase nav so the editor's own Enter handler doesn't fire while the
// autocomplete popup is open.
function acNav(e) {
  if (!_acState) return;
  if (e.key === 'ArrowDown') { e.preventDefault(); e.stopPropagation(); _acState.hi = Math.min(_acState.hi + 1, _acState.matches.length - 1); paintAc(); }
  else if (e.key === 'ArrowUp') { e.preventDefault(); e.stopPropagation(); _acState.hi = Math.max(_acState.hi - 1, 0); paintAc(); }
  else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); e.stopPropagation(); applyAutocomplete(_acState.hi); }
  else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); hideAutocomplete(); }
}
function paintAc() {
  const box = document.getElementById('acBox'); if (!box || !_acState) return;
  [...box.querySelectorAll('.ac-i')].forEach((r, i) => r.classList.toggle('on', i === _acState.hi));
}

/* ── Print / Save as PDF a single note ─────────────────────── */
function printNote() {
  if (!activeNote) { showToast(__( 'Open a note first', 'noodled' )); return; }
  let area = document.getElementById('printArea');
  if (!area) { area = document.createElement('div'); area.id = 'printArea'; document.body.appendChild(area); }
  area.innerHTML = `<h1 class="print-title">${esc(activeNote.title || __( 'Untitled', 'noodled' ))}</h1><div class="print-body">${renderMarkdown(activeNote.body || '')}</div>`;
  window.print();
}

/* ── Download the current note as a .md file ───────────────── */
function downloadNoteMd() {
  if (!activeNote) { showToast(__( 'Open a note first', 'noodled' )); return; }
  const fm = ['---', 'title: ' + (activeNote.title || ''), 'created: ' + (activeNote.created || ''), 'modified: ' + (activeNote.modified || ''), '---', '', activeNote.body || ''].join('\n');
  const blob = new Blob([fm], { type: 'text/markdown' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = (activeNote.slug || activeNote.title || 'note').replace(/[^\w.-]+/g, '-') + '.md';
  document.body.appendChild(a); a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}
