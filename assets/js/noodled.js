/* noodled for WordPress
   API adapter + UI logic */

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
  save_note(notebook, noteId, title, body) { return this._fetch('/notes/' + noteId, { method: 'PUT', body: JSON.stringify({ title, body }) }); },
  delete_note(notebook, noteId)          { return this._fetch('/notes/' + noteId, { method: 'DELETE' }); },
  move_note(from, noteId, to)            { return this._fetch('/notes/' + noteId + '/move', { method: 'POST', body: JSON.stringify({ notebook: to }) }); },
  toggle_pin(notebook, noteId)           { return this._fetch('/notes/' + noteId + '/pin', { method: 'POST' }); },
  get_trash()                            { return this._fetch('/trash'); },
  restore_note(noteId)                   { return this._fetch('/trash/' + noteId + '/restore', { method: 'POST' }); },
  permanent_delete(noteId)               { return this._fetch('/trash/' + noteId, { method: 'DELETE' }); },
  empty_trash()                          { return this._fetch('/trash', { method: 'DELETE' }); },
  trash_count()                          { return this._fetch('/trash/count'); },
  search(query)                          { return this._fetch('/search?q=' + encodeURIComponent(query)); },
  save_attachment(nb, noteId, name, b64) { return this._fetch('/attachments', { method: 'POST', body: JSON.stringify({ note_id: noteId, filename: name, data: b64 }) }); },
  import_evernote(formData)              { return fetch(this._base + '/import/evernote', { method: 'POST', headers: { 'X-WP-Nonce': this._nonce }, credentials: 'same-origin', body: formData }).then(r => r.json()); },
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

  try {
    config = await api.get_config();
  } catch (e) {
    console.warn('Noodled: config load failed', e.message);
    config = { theme: 'dark' };
  }
  applyTheme(config.theme || 'dark');

  try {
    await loadNotebooks();
    await loadNotes();
    updateTrashCount();
  } catch (e) {
    console.error('Noodled: load failed', e.message);
    setStatus('Failed to load — try refreshing');
  }

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
function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  config.theme = theme;
}

async function toggleTheme() {
  const t = config.theme === 'dark' ? 'light' : 'dark';
  applyTheme(t);
  try { await api.set_config('theme', t); } catch (e) {}
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
    const shared = nb.access !== 'owner' ? '<span style="font-size:9px;color:var(--text-muted);margin-left:4px" title="Shared with you">&#128279;</span>' : '';
    const readOnly = nb.access === 'read' ? '<span style="font-size:9px;color:var(--text-muted);margin-left:2px" title="Read only">&#128274;</span>' : '';
    return `<div class="nb-item${active}" onclick="selectNotebook('${esc(name)}')" oncontextmenu="event.preventDefault(); showNbContext(event, '${esc(name)}')">
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
  await loadNotes();
}

async function createNotebook() {
  const name = await showPrompt('New Notebook', 'Notebook name:');
  if (!name) return;
  await api.create_notebook(name);
  await loadNotebooks();
  selectNotebook(name);
}

function showNbContext(event, name) {
  const nb = notebooks.find(n => n.name === name);
  const items = [
    { label: 'Rename', action: () => renameNotebook(name) },
    { label: 'Set cover image', action: () => setNotebookCover(name) },
  ];
  if (nb && nb.access === 'owner') {
    items.push({ label: 'Share...', action: () => showShareDialog(nb) });
  }
  items.push({ sep: true });
  items.push({ label: 'Delete', danger: true, action: () => deleteNotebook(name) });
  showContextMenu(event, items);
}

async function showShareDialog(nb) {
  const el = document.getElementById('modalContainer');
  el.innerHTML = `
    <div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
      <div class="modal" style="min-width:380px">
        <h3>Share "${esc(nb.name)}"</h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">Enter their email to grant access</p>
        <input id="shareEmail" placeholder="Email address" autofocus>
        <div style="display:flex;gap:8px;margin-bottom:16px">
          <label style="font-size:12px;color:var(--text)"><input type="checkbox" id="shareWrite"> Can edit</label>
        </div>
        <div class="modal-buttons">
          <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">Cancel</button>
          <button class="btn btn-sm btn-accent" onclick="doShare(${nb.id})">Share</button>
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
  if (!email) { msg.textContent = 'Email required'; msg.style.color = 'var(--red)'; return; }

  try {
    const r = await api._fetch('/share', {
      method: 'POST',
      body: JSON.stringify({ notebook_id: notebookId, email, can_write: canWrite })
    });
    if (r.error) { msg.textContent = r.error; msg.style.color = 'var(--red)'; }
    else { msg.textContent = 'Shared!'; msg.style.color = 'var(--green)'; renderNotebookShareList(notebookId); }
  } catch (e) {
    msg.textContent = 'Failed: ' + e.message; msg.style.color = 'var(--red)';
  }
}

async function renderNotebookShareList(notebookId) {
  const list = document.getElementById('nbShareList');
  if (!list) return;
  const shares = await api.notebook_shares(notebookId);
  if (!Array.isArray(shares) || !shares.length) { list.innerHTML = '<div style="font-size:12px;color:var(--text-muted)">Not shared with anyone yet.</div>'; return; }
  list.innerHTML = '<div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Shared with</div>' + shares.map(s =>
    `<div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:13px">
       <span>${esc(s.display_name || s.email)} <span style="color:var(--text-muted)">(${s.can_write == 1 ? 'edit' : 'read'})</span></span>
       <button class="btn btn-sm" onclick="removeNotebookShare(${notebookId}, '${esc(s.email)}')">Remove</button>
     </div>`).join('');
}

async function removeNotebookShare(notebookId, email) {
  await api.notebook_unshare(notebookId, email);
  renderNotebookShareList(notebookId);
}

// ── Note-level user sharing ──
function showNoteShareDialog(noteId) {
  const note = filteredNotes.find(n => n.id === noteId) || activeNote;
  const title = note ? note.title : 'note';
  const el = document.getElementById('modalContainer');
  el.innerHTML = `
    <div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
      <div class="modal" style="min-width:400px">
        <h3>Share "${esc(title)}"</h3>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">Give another noodled user access to just this note.</p>
        <div style="display:flex;gap:8px;align-items:center">
          <input id="nShareEmail" placeholder="Email address" style="flex:1" autofocus>
          <label style="font-size:12px;white-space:nowrap"><input type="checkbox" id="nShareWrite"> Can edit</label>
          <button class="btn btn-sm btn-accent" onclick="doNoteShare(${noteId})">Share</button>
        </div>
        <div id="nShareMsg" style="margin-top:8px;font-size:12px"></div>
        <div id="nShareList" style="margin-top:14px"></div>
        <div class="modal-buttons" style="margin-top:14px">
          <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">Close</button>
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
  if (!Array.isArray(shares) || !shares.length) { list.innerHTML = '<div style="font-size:12px;color:var(--text-muted)">Not shared with anyone yet.</div>'; return; }
  list.innerHTML = '<div style="font-size:11px;color:var(--text-muted);margin-bottom:4px">Shared with</div>' + shares.map(s =>
    `<div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:13px">
       <span>${esc(s.display_name || s.email)} <span style="color:var(--text-muted)">(${s.can_write == 1 ? 'edit' : 'read'})</span></span>
       <button class="btn btn-sm" onclick="removeNoteShare(${noteId}, '${esc(s.email)}')">Remove</button>
     </div>`).join('');
}

async function doNoteShare(noteId) {
  const email = document.getElementById('nShareEmail').value.trim();
  const canWrite = document.getElementById('nShareWrite').checked;
  const msg = document.getElementById('nShareMsg');
  if (!email) { msg.textContent = 'Email required'; msg.style.color = 'var(--red)'; return; }
  try {
    const r = await api.note_share(noteId, email, canWrite);
    if (r.error) { msg.textContent = r.error; msg.style.color = 'var(--red)'; return; }
    msg.textContent = 'Shared!'; msg.style.color = 'var(--green)';
    document.getElementById('nShareEmail').value = '';
    renderNoteShareList(noteId);
  } catch (e) { msg.textContent = 'Failed: ' + e.message; msg.style.color = 'var(--red)'; }
}

async function removeNoteShare(noteId, email) {
  await api.note_unshare(noteId, email);
  renderNoteShareList(noteId);
}

async function renameNotebook(name) {
  const newName = await showPrompt('Rename Notebook', 'New name:', name);
  if (!newName || newName === name) return;
  await api.rename_notebook(name, newName);
  if (activeNotebook === name) activeNotebook = newName;
  await loadNotebooks();
  await loadNotes();
}

async function deleteNotebook(name) {
  if (!confirm(`Delete notebook "${name}" and all its notes?`)) return;
  await api.delete_notebook(name);
  if (activeNotebook === name) activeNotebook = null;
  activeNote = null;
  await loadNotebooks();
  await loadNotes();
  renderContent();
}

// ── Notes ──
async function loadNotes() {
  notes = await api.get_notes(activeNotebook);
  filterNotes();
}

function filterNotes() {
  searchQuery = document.getElementById('searchInput').value.toLowerCase();
  if (searchQuery) {
    filteredNotes = notes.filter(n =>
      n.title.toLowerCase().includes(searchQuery) ||
      n.body.toLowerCase().includes(searchQuery)
    );
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

function onSearch() { filterNotes(); }

function cycleSort() {
  const modes = ['modified', 'created', 'alpha'];
  const labels = { modified: 'Modified', created: 'Created', alpha: 'A-Z' };
  sortMode = modes[(modes.indexOf(sortMode) + 1) % modes.length];
  document.getElementById('sortBtn').textContent = labels[sortMode];
  filterNotes();
}

function highlightMatch(text, query) {
  if (!query) return esc(text);
  const escaped = esc(text);
  const re = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
  return escaped.replace(re, '<mark>$1</mark>');
}

function renderNoteList() {
  const el = document.getElementById('noteList');
  const cover = activeNotebook ? getNotebookCover(activeNotebook) : '';
  const coverHtml = cover ? `<div class="nb-cover" style="background-image:url('${cover}')"></div>` : '';
  el.innerHTML = coverHtml + filteredNotes.map(n => {
    const preview = (n.body || '')
      .replace(/^---[\s\S]*?---\n?/, '')    // strip frontmatter
      .replace(/^#{1,3}\s+/gm, '')          // strip headings
      .replace(/!\[([^\]]*)\]\([^)]+\)/g, '') // strip images
      .replace(/\[([^\]]*)\]\([^)]+\)/g, '$1') // links to text
      .replace(/\*\*(.+?)\*\*/g, '$1')      // bold
      .replace(/\*(.+?)\*/g, '$1')           // italic
      .replace(/`([^`]+)`/g, '$1')           // inline code
      .replace(/^>\s+/gm, '')               // blockquotes
      .replace(/^-\s+\[[ xX]\]\s*/gm, '')   // checklists
      .replace(/^-\s+/gm, '')               // bullets
      .replace(/^\d+\.\s+/gm, '')           // numbered lists
      .replace(/\n+/g, ' ')                 // newlines to spaces
      .trim().substring(0, 120);
    const isActive = activeNote && activeNote.id === n.id;
    const badge = n.source === 'plaud' ? '<span class="source-badge">plaud</span>' : '';
    const pin = n.pinned ? '<span style="margin-right:4px;font-size:11px" title="Pinned">&#128204;</span>' : '';
    const star = isStarred(n.id) ? '<span style="margin-right:4px;font-size:11px;color:#f59e0b" title="Starred">&#9733;</span>' : '';
    const sharedBadge = n.shared ? '<span style="margin-right:4px;font-size:11px" title="Shared with you">&#128279;</span>' : '';
    const time = relativeTime(n.modified || n.created);
    const fullDate = n.modified || n.created || '';
    const checkbox = bulkMode ? `<input type="checkbox" class="bulk-cb" ${bulkSelected.has(n.id) ? 'checked' : ''} onclick="toggleBulkSelect(${n.id}, event)">` : '';
    const noteColor = getNoteColor(n.id);
    const colorStyle = noteColor ? `border-left:3px solid ${noteColor}` : '';
    return `
      <div class="note-item ${isActive ? 'active' : ''}" data-note-id="${n.id}" style="${colorStyle}"
           onclick="${bulkMode ? `toggleBulkSelect(${n.id}, event)` : `handleNoteTap(event, ${n.id})`}"
           oncontextmenu="event.preventDefault(); showNoteContext(event, ${n.id})">
        <div class="title">${checkbox}${pin}${star}${sharedBadge}${highlightMatch(n.title, searchQuery)}${badge}</div>
        <div class="meta">
          <span title="${escAttr(fullDate)}">${esc(time)}</span>
          <span>${esc(n.notebook)}</span>
        </div>
        <div class="preview">${highlightMatch(preview, searchQuery)}</div>
      </div>
    `;
  }).join('');

  document.getElementById('noteCount').textContent = `${filteredNotes.length} note${filteredNotes.length !== 1 ? 's' : ''}`;
}

async function selectNote(noteId) {
  // Show immediate loading feedback
  const item = document.querySelector(`.note-item[data-note-id="${noteId}"]`);
  if (item) item.classList.add('loading');

  await doSave();
  activeNote = await api.get_note(null, noteId);
  renderNoteList();
  renderContent();
}

async function createNote() {
  await doSave();
  const nb = activeNotebook || 'General';
  const note = await api.create_note(nb, 'Untitled Note', '');
  await loadNotebooks();
  await loadNotes();
  activeNote = note;
  renderNoteList();
  renderContent();
  document.querySelector('.col-content')?.classList.add('open');
  closeSidebar();
  setTimeout(() => {
    const input = document.getElementById('titleInput');
    if (input) { input.focus(); input.select(); }
  }, 100);
}

function showNoteContext(event, noteId) {
  if (viewingTrash) {
    showContextMenu(event, [
      { label: 'Restore', action: () => restoreNote(noteId) },
      { sep: true },
      { label: 'Delete permanently', danger: true, action: () => permanentDelete(noteId) },
    ]);
    return;
  }

  const note = filteredNotes.find(n => n.id === noteId);
  const pinLabel = note && note.pinned ? 'Unpin' : 'Pin to top';

  const moveItems = notebooks
    .filter(nb => !note || nb.name !== note.notebook)
    .map(nb => ({
      label: `Move to ${nb.name}`,
      action: () => moveNote(noteId, nb.name)
    }));

  const starLabel = isStarred(noteId) ? 'Unstar' : 'Star';
  showContextMenu(event, [
    { label: pinLabel, action: () => togglePin(noteId) },
    { label: starLabel, action: () => toggleStar(noteId) },
    { label: 'Color', action: () => showColorPicker(noteId) },
    { label: 'Duplicate', action: () => duplicateNote(noteId) },
    { label: 'Open in split', action: () => openInSplit(noteId) },
    { label: 'Copy text', action: () => copyNoteText(noteId) },
    { label: 'Share with people…', action: () => showNoteShareDialog(noteId) },
    { label: 'Public link', action: () => shareNoteLink(noteId) },
    { sep: true },
    ...moveItems,
    { sep: true },
    { label: 'Delete', danger: true, action: () => deleteNote(noteId) },
  ]);
}

async function togglePin(noteId) {
  const note = filteredNotes.find(n => n.id === noteId);
  if (!note) return;
  await api.toggle_pin(note.notebook, noteId);
  await loadNotes();
}

async function copyNoteText(noteId) {
  const note = await api.get_note(null, noteId);
  if (note.body) {
    await navigator.clipboard.writeText(note.body);
    showToast('Copied to clipboard');
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
}

async function moveNote(noteId, toNotebook) {
  const note = filteredNotes.find(n => n.id === noteId);
  if (!note) return;
  await api.move_note(note.notebook, noteId, toNotebook);
  await loadNotebooks();
  await loadNotes();
  showToast(`Moved to ${toNotebook}`);
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
  showToast('Restored');
}

async function permanentDelete(noteId) {
  if (!confirm('Permanently delete this note? This cannot be undone.')) return;
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
    el.innerHTML = '<div class="empty-state"><div class="icon">&#127837;</div><div>Select a note or create a new one</div></div>';
    return;
  }

  const n = activeNote;
  el.innerHTML = `
    <div class="content-toolbar">
      <input class="content-title-input" id="titleInput" value="${escAttr(n.title)}"
             placeholder="Note title" onchange="saveTitleOnly()" onkeydown="if(event.key==='Tab'){event.preventDefault();document.getElementById(showRawMarkdown?'noteBodyRaw':'noteBody')?.focus();}">
      <span class="save-indicator" id="saveIndicator"></span>
      <div class="editor-actions">
        <button class="btn btn-sm" onclick="insertBullet()" title="Bullet list">&#9679;</button>
        <button class="btn btn-sm" onclick="insertChecklistItem()" title="Checklist">&#9744;</button>
        <button class="btn btn-sm" onclick="insertHeading()" title="Heading">H</button>
        <span class="toolbar-divider"></span>
        <button class="btn btn-sm" onclick="copyBody()" title="Copy">&#128203;</button>
        <button class="btn btn-sm" onclick="uploadFile()" title="Upload">&#128206;</button>
        <button class="btn btn-sm" onclick="toggleVoiceMemo()" id="voiceBtn" title="Dictate">&#127908;</button>
        <span class="toolbar-divider"></span>
        <div class="toolbar-menu-wrap">
          <button class="btn btn-sm" onclick="toggleEditorMenu()" title="More tools">&#8943;</button>
          <div class="toolbar-dropdown" id="editorMenu">
            <div class="dropdown-item" onclick="toggleMarkdownView()">${showRawMarkdown ? 'Preview mode' : 'Source mode'}</div>
            <div class="dropdown-item" onclick="toggleFindReplace()">Find & replace</div>
            <div class="dropdown-item" onclick="showTOC()">Table of contents</div>
            <div class="dropdown-item" onclick="exportNote()">Export as HTML</div>
            <div class="dropdown-sep"></div>
            <div class="dropdown-item" onclick="toggleReadingMode()">Reading mode</div>
            <div class="dropdown-item" onclick="toggleTypewriter()">Typewriter mode</div>
            <div class="dropdown-item" onclick="toggleZenMode()">Zen mode</div>
            <div class="dropdown-sep"></div>
            <div class="dropdown-item" onclick="showHistory()">Version history</div>
            <div class="dropdown-item" onclick="setWordGoal()">Word count goal</div>
            <div class="dropdown-sep"></div>
            <div class="dropdown-item dropdown-danger" onclick="deleteCurrentNote()">Delete note</div>
          </div>
        </div>
      </div>
      <input type="file" id="fileUploadInput" style="display:none" onchange="handleFileUpload(this)">
    </div>
    <div class="content-body" id="dropZone">
      ${showRawMarkdown
        ? `<textarea id="noteBodyRaw" class="raw-markdown" oninput="schedSave(); updateWordCount()">${escAttr(n.body || '')}</textarea>`
        : `<div class="rendered-content" id="noteBody" contenteditable="true"
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
        viewAttachment(a.href, a.textContent);
      } else {
        window.open(a.href, '_blank', 'noopener');
      }
    }
  });
}

function viewAttachment(url, name) {
  // Full-screen overlay with floating back button
  let overlay = document.getElementById('attachOverlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'attachOverlay';
    overlay.className = 'attach-overlay';
    document.body.appendChild(overlay);
  }
  overlay.innerHTML = `
    <div class="attach-toolbar">
      <button class="btn btn-sm" onclick="closeAttachment()">&#8592; Back</button>
      <button class="btn btn-sm" onclick="window.open('${url}','_blank','noopener')">New tab</button>
    </div>
    <iframe src="${url}" style="width:100%;height:100%;border:none"></iframe>
  `;
  overlay.classList.add('show');
}

function closeAttachment() {
  const overlay = document.getElementById('attachOverlay');
  if (overlay) { overlay.classList.remove('show'); overlay.innerHTML = ''; }
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
    goalText = ` \u00b7 ${pct}% of ${goal} goal`;
  }
  el.textContent = `${wordCount} words \u00b7 ${charCount} chars \u00b7 ${readTime} min read${goalText}`;
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
  li.textContent = 'item';
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

async function saveAndRerender() {
  const title = document.getElementById('titleInput')?.value || activeNote.title;
  const result = await api.save_note(activeNote.notebook, activeNote.id, title, activeNote.body);
  if (!result.error) { activeNote = result; await loadNotes(); }
  renderContent();
  setTimeout(() => {
    const el = document.getElementById('noteBody');
    if (el) { el.focus(); const r = document.createRange(); r.selectNodeContents(el); r.collapse(false); const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r); }
  }, 50);
}

function toggleToolbarMore() {
  document.querySelector('.content-toolbar')?.classList.toggle('expanded');
}

function toggleAppMenu() {
  const menu = document.getElementById('appMenu');
  if (menu) menu.classList.toggle('show');
  // Close on next click
  if (menu?.classList.contains('show')) {
    setTimeout(() => document.addEventListener('click', () => menu.classList.remove('show'), { once: true }), 10);
  }
}

function toggleEditorMenu() {
  const menu = document.getElementById('editorMenu');
  if (menu) menu.classList.toggle('show');
  if (menu?.classList.contains('show')) {
    setTimeout(() => document.addEventListener('click', () => menu.classList.remove('show'), { once: true }), 10);
  }
}

async function deleteCurrentNote() {
  if (!activeNote) return;
  if (!confirm(`Delete "${activeNote.title}"?`)) return;
  await api.delete_note(activeNote.notebook, activeNote.id);
  activeNote = null;
  renderContent();
  document.querySelector('.col-content')?.classList.remove('open');
  await loadNotebooks();
  await loadNotes();
  updateTrashCount();
  // Push to GitHub so delete syncs across devices
  try { await api.git_push(); } catch (e) {}
  showToast('Deleted');
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
  if (!activeNote || viewingTrash) return;
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
  const result = await queuedSave(activeNote.notebook, activeNote.id, title, body);
  if (!result.error) {
    activeNote = result;
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

async function attachFile(file) {
  if (!activeNote) return;
  try {
    const b64 = await fileToB64(file);
    const result = await api.save_attachment(activeNote.notebook, activeNote.id, file.name, b64);
    if (result && result.filename) {
      // Attachments stay out of the text — they render in the gallery below.
      (activeNote.attachments = activeNote.attachments || []).push(result);
      renderAttachmentGallery();
      showToast(`Attached: ${result.filename}`);
    } else if (result && result.error) {
      showToast(result.error);
    }
  } catch (e) { showToast('Upload failed'); }
}

async function handleDrop(e) {
  if (!activeNote || !e.dataTransfer.files.length) return;
  for (const file of e.dataTransfer.files) await attachFile(file);
}

function uploadFile() {
  const input = document.getElementById('fileUploadInput');
  if (input) { input.value = ''; input.click(); }
}

async function handleFileUpload(input) {
  if (!activeNote || !input.files.length) return;
  for (const file of input.files) await attachFile(file);
}

// ── Photo upload: creates a new "Image Upload …" note, images shown in the gallery ──
function uploadPhotos() {
  const input = document.getElementById('photoUploadInput');
  if (input) { input.value = ''; input.click(); }
}

async function handlePhotoUpload(input) {
  const files = Array.from(input.files || []).filter(f => /^image\//.test(f.type) || /\.(png|jpe?g|gif|webp|bmp|heic|heif)$/i.test(f.name));
  if (!files.length) return;
  await doSave();
  const nb = activeNotebook || 'General';
  const title = 'Image Upload ' + new Date().toLocaleString();
  showToast(`Uploading ${files.length} photo${files.length !== 1 ? 's' : ''}…`);
  const note = await api.create_note(nb, title, '');
  if (!note || note.error) { showToast('Could not create note'); return; }
  let ok = 0;
  for (const f of files) {
    try {
      const b64 = await fileToB64(f);
      const r = await api.save_attachment(note.notebook, note.id, f.name, b64);
      if (r && r.filename) ok++;
    } catch (e) {}
  }
  activeNote = await api.get_note(null, note.id);
  await loadNotebooks();
  await loadNotes();
  renderNoteList();
  renderContent();
  document.querySelector('.col-content')?.classList.add('open');
  closeSidebar();
  showToast(`${ok} photo${ok !== 1 ? 's' : ''} added`);
}

// ── Evernote import (per-user, from the app) ──
function importEvernote() {
  const input = document.getElementById('enexImportInput');
  if (input) { input.value = ''; input.click(); }
}

async function handleEvernoteImport(input) {
  const file = input.files && input.files[0];
  if (!file) return;
  showToast('Importing from Evernote…');
  const fd = new FormData();
  fd.append('file', file);
  try {
    const res = await api.import_evernote(fd);
    if (res && res.error) { showToast('Import failed: ' + res.error); return; }
    await loadNotebooks();
    await loadNotes();
    const n = res.imported || 0;
    showToast(`Imported ${n} note${n !== 1 ? 's' : ''}` + (res.skipped ? `, ${res.skipped} skipped` : ''));
  } catch (e) { showToast('Import failed'); }
}

// ── Checkbox toggle ──
async function toggleCheck(checkIndex) {
  if (!activeNote) return;
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
  try { await navigator.clipboard.writeText(text); showToast('Copied to clipboard'); }
  catch { showToast('Copy failed'); }
}

// ── Global keyboard shortcuts ──
function handleGlobalKey(e) {
  const tag = e.target.tagName;
  const isEditable = e.target.isContentEditable || tag === 'INPUT' || tag === 'TEXTAREA';
  if (e.ctrlKey && e.key === 'n') { e.preventDefault(); createNote(); return; }
  if (e.ctrlKey && e.key === 's') { e.preventDefault(); doSave(); showToast('Saved'); return; }
  if ((e.ctrlKey && e.shiftKey && e.key === 'F') || (e.ctrlKey && e.key === 'k')) { e.preventDefault(); document.getElementById('searchInput').focus(); return; }
  if (e.ctrlKey && e.key === 'p') { e.preventDefault(); showQuickOpen(); return; }
  if (e.ctrlKey && e.key === 'j') { e.preventDefault(); openDailyJournal(); return; }
  if (e.ctrlKey && e.key === '.') { e.preventDefault(); toggleQuickCapture(); return; }
  if (e.ctrlKey && e.key === 'h') { e.preventDefault(); toggleFindReplace(); return; }
  if (e.key === 'Escape' && readingMode) { toggleReadingMode(); return; }
  if (e.key === '?' && !isEditable) { e.preventDefault(); showShortcutsHelp(); return; }
}

// ── Quick-open ──
function showQuickOpen() {
  const el = document.getElementById('modalContainer');
  const allNotes = notes;
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}"><div class="modal" style="min-width:400px"><h3>Quick Open</h3><input id="qoInput" placeholder="Type to search notes..." autofocus><div class="quick-open-list" id="qoList"></div></div></div>`;
  let qoHighlight = 0, qoResults = [];
  function qoFilter() {
    const q = document.getElementById('qoInput').value.toLowerCase();
    qoResults = allNotes.filter(n => n.title.toLowerCase().includes(q)).slice(0, 10);
    qoHighlight = 0; qoRender();
  }
  function qoRender() {
    const list = document.getElementById('qoList');
    if (!qoResults.length) { list.innerHTML = '<div style="padding:8px;color:var(--text-muted);font-size:12px">No matches</div>'; return; }
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
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}"><div class="modal"><h3>Keyboard Shortcuts</h3><div class="shortcuts-grid"><kbd>Ctrl+N</kbd> <span>New note</span><kbd>Ctrl+S</kbd> <span>Force save</span><kbd>Ctrl+P</kbd> <span>Quick open</span><kbd>Ctrl+Shift+F</kbd> <span>Focus search</span><kbd>Ctrl+B</kbd> <span>Bold</span><kbd>Ctrl+I</kbd> <span>Italic</span><kbd>?</kbd> <span>This help</span></div><div class="modal-buttons" style="margin-top:14px"><button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">Close</button></div></div></div>`;
}

// ── Markdown renderer ──
function renderMarkdown(text) {
  if (!text) return '';
  const lines = text.split('\n');
  const out = [];
  let inCodeBlock = false, codeBuffer = [], inList = false, listType = '';

  function closeList() { if (inList) { out.push(listType === 'check' ? '</ul>' : `</${listType}>`); inList = false; listType = ''; } }
  function escLine(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  function inlineFormat(s) {
    return s
      .replace(/!\[([^\]]*)\]\(([^)]+)\)/g, '<img src="$2" alt="$1">')
      .replace(/\[\[([^\]]+)\]\]/g, (match, title) => {
        const target = notes.find(n => n.title.toLowerCase() === title.toLowerCase().trim());
        if (target) return `<a href="#" class="wikilink" onclick="event.preventDefault(); selectNote(${target.id})">${esc(title)}</a>`;
        return `<a href="#" class="wikilink broken" onclick="event.preventDefault()">${esc(title)}</a>`;
      })
      .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
      .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g, '<em>$1</em>')
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/\[(\d+:\d+(?::\d+)?)\]\s*/g, '<span class="timestamp">[$1]</span> ')
      .replace(/(^|\s)#([a-zA-Z][\w-]*)/g, '$1<span class="tag" onclick="filterByTag(\'$2\')">#$2</span>');
  }

  for (let i = 0; i < lines.length; i++) {
    const raw = lines[i];
    if (raw.trimStart().startsWith('```')) {
      if (inCodeBlock) { out.push('<pre><code>' + codeBuffer.map(escLine).join('\n') + '</code></pre>'); codeBuffer = []; inCodeBlock = false; }
      else { closeList(); inCodeBlock = true; }
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
      out.push(`<li><input type="checkbox" ${checked ? 'checked' : ''} contenteditable="false" onmousedown="event.stopPropagation(); event.stopImmediatePropagation();" onclick="event.stopPropagation(); toggleCheck(${checkIdx})"><span class="${checked ? 'check-done' : ''}">${inlineFormat(escLine(checkMatch[2]))}</span></li>`);
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
          out.push('<table><thead><tr>' + cells.map(c => `<th>${inlineFormat(escLine(c))}</th>`).join('') + '</tr></thead><tbody>');
          i++; // skip separator line
        } else {
          out.push('<table><tbody>');
          out.push('<tr>' + cells.map(c => `<td>${inlineFormat(escLine(c))}</td>`).join('') + '</tr>');
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
  if (inCodeBlock) out.push('<pre><code>' + codeBuffer.map(escLine).join('\n') + '</code></pre>');
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
  if (diff < 60) return 'just now';
  if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
  if (diff < 172800) return 'yesterday';
  if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
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
    el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';resolve_modal(null);}"><div class="modal"><h3>${title}</h3><label style="font-size:12px;color:var(--text-muted)">${label}</label><input id="modalInput" value="${escAttr(defaultVal)}" autofocus><div class="modal-buttons"><button class="btn btn-sm" onclick="resolve_modal(null)">Cancel</button><button class="btn btn-sm btn-accent" onclick="resolve_modal(document.getElementById('modalInput').value)">OK</button></div></div></div>`;
    window.resolve_modal = (val) => { el.innerHTML = ''; resolve(val); };
    const inp = document.getElementById('modalInput');
    inp.focus();
    inp.addEventListener('keydown', e => {
      if (e.key === 'Enter') window.resolve_modal(inp.value);
      if (e.key === 'Escape') window.resolve_modal(null);
    });
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
    div.addEventListener('click', () => { closeContextMenu(); item.action(); });
    menu.appendChild(div);
  });
  el.appendChild(menu);
  document.addEventListener('click', closeContextMenu, { once: true });
}

function closeContextMenu() { document.getElementById('ctxContainer').innerHTML = ''; }
document.addEventListener('scroll', closeContextMenu, true);

// ── Offline indicator ──
function setOnline(online) {
  const el = document.getElementById('offlineBanner');
  if (el) el.classList.toggle('show', !online);
}

// ── Sync pull ──
async function syncPull() {
  const btn = document.getElementById('syncPullBtn');
  btn.disabled = true;
  btn.textContent = 'Syncing...';
  try {
    const r = await api._fetch('/sync/pull', { method: 'POST' });
    if (r.error) {
      showToast('Sync failed: ' + r.error);
    } else {
      showToast(`Synced: ${r.notes || 0} notes`);
      await loadNotebooks();
      await loadNotes();
      updateTrashCount();
    }
  } catch (e) {
    showToast('Sync failed');
  }
  btn.disabled = false;
  btn.textContent = 'Sync';
}

// ── Plaud sync ──
async function syncPlaud() {
  const btn = document.getElementById('plaudSyncBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Syncing Plaud...'; }
  try {
    const r = await api.sync_plaud();
    if (r.error) {
      showToast('Plaud sync failed: ' + r.error);
    } else {
      showToast(`Plaud: ${r.downloaded} new recording${r.downloaded !== 1 ? 's' : ''} imported`);
      if (r.downloaded > 0) {
        await loadNotebooks();
        await loadNotes();
      }
    }
  } catch (e) {
    showToast('Plaud sync failed');
  }
  if (btn) { btn.disabled = false; btn.textContent = 'Plaud'; }
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
      pullEl.textContent = 'Syncing...';
      try {
        await loadNotebooks();
        await loadNotes();
        showToast('Refreshed');
      } catch (e) {}
      pullEl.classList.remove('show');
      pullEl.textContent = '↓ Pull to refresh';
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
  const newNote = await api.create_note(note.notebook, note.title + ' (copy)', note.body);
  await loadNotebooks();
  await loadNotes();
  if (newNote && !newNote.error) {
    selectNote(newNote.id);
    showToast('Duplicated');
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
  showToast('Exported');
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
    document.execCommand('insertHTML', false, `<a href="${url}" target="_blank" rel="noopener">${url}</a>`);
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
  if (!history.length) { showToast('No history yet'); return; }
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal" style="max-width:500px">
      <h3>Note History</h3>
      <div style="max-height:300px;overflow-y:auto">
        ${history.map((h, i) => `<div class="history-item" onclick="restoreHistory(${i})" style="padding:10px;cursor:pointer;border-bottom:1px solid var(--border)">
          <div style="font-size:12px;color:var(--text-muted)">${relativeTime(h.time)} — ${h.title}</div>
          <div style="font-size:11px;color:var(--text);margin-top:4px">${esc((h.body || '').substring(0, 100))}...</div>
        </div>`).join('')}
      </div>
      <div class="modal-buttons" style="margin-top:12px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">Close</button>
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
  showToast('Restored from history');
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
  if (!confirm(`Delete ${bulkSelected.size} note(s)?`)) return;
  for (const id of bulkSelected) {
    const note = notes.find(n => n.id === id);
    if (note) await api.delete_note(note.notebook, id);
  }
  bulkSelected.clear();
  toggleBulkMode();
  await loadNotebooks();
  await loadNotes();
  updateTrashCount();
  showToast('Deleted');
}

async function bulkMove() {
  if (!bulkSelected.size) return;
  const target = await showPrompt('Move Notes', 'Move to notebook:');
  if (!target) return;
  for (const id of bulkSelected) {
    const note = notes.find(n => n.id === id);
    if (note) await api.move_note(note.notebook, id, target);
  }
  bulkSelected.clear();
  toggleBulkMode();
  await loadNotebooks();
  await loadNotes();
  showToast(`Moved to ${target}`);
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
  const goal = await showPrompt('Word Count Goal', 'Target words:', current.toString());
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

function showTagCloud() {
  const tags = getAllTags();
  if (!tags.length) { showToast('No tags found'); return; }
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal"><h3>Tags</h3>
      <div style="display:flex;flex-wrap:wrap;gap:8px;max-height:300px;overflow-y:auto">
        ${tags.map(t => `<span class="tag-chip" onclick="document.getElementById('modalContainer').innerHTML=''; document.getElementById('searchInput').value='#${t}'; onSearch();">#${esc(t)}</span>`).join('')}
      </div>
      <div class="modal-buttons" style="margin-top:14px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">Close</button>
      </div>
    </div>
  </div>`;
}

// ── Note templates ──
const defaultTemplates = [
  { name: 'Meeting Notes', body: '## Meeting Notes\n\n**Date:** \n**Attendees:** \n\n### Agenda\n- \n\n### Action Items\n- [ ] \n\n### Notes\n' },
  { name: 'Journal Entry', body: '## ' + new Date().toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) + '\n\n### Gratitude\n- \n\n### Today\n\n\n### Reflection\n' },
  { name: 'Project Plan', body: '## Project: \n\n### Goal\n\n\n### Tasks\n- [ ] \n- [ ] \n- [ ] \n\n### Timeline\n\n| Phase | Start | End |\n|-------|-------|-----|\n| Planning | | |\n| Execution | | |\n| Review | | |\n\n### Notes\n' },
  { name: 'Quick List', body: '## List\n\n- [ ] \n- [ ] \n- [ ] \n' },
];

function showTemplates() {
  const templates = config.templates || defaultTemplates;
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal"><h3>New from Template</h3>
      <div style="max-height:300px;overflow-y:auto">
        ${templates.map((t, i) => `<div class="template-item" onclick="createFromTemplate(${i})">${esc(t.name)}</div>`).join('')}
      </div>
      <div class="modal-buttons" style="margin-top:14px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">Cancel</button>
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
      showToast('Focus session complete!');
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
    <div class="modal"><h3>Focus Timer</h3>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''; startFocusTimer(15)">15 min</button>
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''; startFocusTimer(25)">25 min</button>
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''; startFocusTimer(45)">45 min</button>
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''; startFocusTimer(60)">60 min</button>
      </div>
      <div class="modal-buttons" style="margin-top:14px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">Cancel</button>
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
  showToast('Captured!');
}

// ── Note linking graph ──
function showLinkGraph() {
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
    <div class="modal" style="max-width:500px"><h3>Note Links</h3>
      <div style="max-height:350px;overflow-y:auto;font-size:13px">
        ${connected.length ? connected.map(([title, targets]) =>
          `<div style="margin-bottom:12px"><strong>${esc(title)}</strong><div style="padding-left:16px;color:var(--text-muted)">${targets.map(t => {
            const found = allNotes.find(n => n.title.toLowerCase() === t.toLowerCase());
            return found ? `<a href="#" style="color:var(--accent)" onclick="event.preventDefault();document.getElementById('modalContainer').innerHTML='';selectNote(${found.id})">${esc(t)}</a>` : `<span style="color:var(--red)">${esc(t)}</span>`;
          }).join(', ')}</div></div>`
        ).join('') : '<div style="color:var(--text-muted)">No wiki-links found. Use [[Note Title]] to link notes.</div>'}
        ${orphans.length ? `<div style="margin-top:12px;border-top:1px solid var(--border);padding-top:8px"><div style="color:var(--text-muted);font-size:11px;margin-bottom:4px">${orphans.length} unlinked notes</div></div>` : ''}
      </div>
      <div class="modal-buttons" style="margin-top:12px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">Close</button>
      </div>
    </div>
  </div>`;
}

// ── Color-coded notes ──
const noteColors = ['', '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
const noteColorNames = ['None', 'Blue', 'Green', 'Yellow', 'Red', 'Purple', 'Pink'];

function showColorPicker(noteId) {
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal"><h3>Note Color</h3>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        ${noteColors.map((c, i) => `<div onclick="setNoteColor(${noteId},'${c}');document.getElementById('modalContainer').innerHTML='';" style="width:36px;height:36px;border-radius:50%;cursor:pointer;border:2px solid var(--border);${c ? 'background:' + c : 'background:var(--bg-input)'}" title="${noteColorNames[i]}"></div>`).join('')}
      </div>
      <div class="modal-buttons" style="margin-top:14px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">Cancel</button>
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
      <button class="btn btn-sm" onclick="closeSplitView()">Close</button>
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
  const body = `## ${title}\n\n### Today\n\n\n### Notes\n\n`;
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
  if (!btn) return;
  if (on) { btn.textContent = 'Stop'; btn.classList.add('recording'); }
  else { btn.innerHTML = '&#127908;'; btn.classList.remove('recording'); }
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

  // Fallback: append to the note body directly.
  activeNote.body = (activeNote.body || '') + chunk;
  if (typeof saveAndRerender === 'function') saveAndRerender();
}

function toggleVoiceMemo() {
  if (dictating) { stopDictation(); return; }
  if (!activeNote) { showToast('Select a note first'); return; }

  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR) { showToast('Dictation is not supported in this browser'); return; }

  recognition = new SR();
  recognition.lang = navigator.language || 'en-US';
  recognition.continuous = true;
  recognition.interimResults = false;

  recognition.onresult = (event) => {
    let finalText = '';
    for (let i = event.resultIndex; i < event.results.length; i++) {
      if (event.results[i].isFinal) finalText += event.results[i][0].transcript;
    }
    if (finalText) insertDictation(finalText);
  };

  recognition.onerror = (e) => {
    if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
      dictating = false;
      showToast('Microphone access denied');
    } else if (e.error !== 'no-speech' && e.error !== 'aborted') {
      showToast('Dictation error: ' + e.error);
    }
  };

  // continuous mode can still end on a long pause — restart unless the user stopped.
  recognition.onend = () => {
    if (dictating) { try { recognition.start(); return; } catch (_) {} }
    dictating = false;
    setDictateBtn(false);
  };

  try {
    recognition.start();
    dictating = true;
    setDictateBtn(true);
    showToast('Listening…');
  } catch (e) {
    dictating = false;
    showToast('Could not start dictation');
  }
}

function stopDictation() {
  dictating = false;
  if (recognition) { try { recognition.stop(); } catch (_) {} }
  setDictateBtn(false);
}

// ── Bookmark sections / TOC ──
function showTOC() {
  if (!activeNote) return;
  const headings = (activeNote.body || '').split('\n')
    .map((line, i) => {
      const m = line.trim().match(/^(#{1,3})\s+(.+)$/);
      return m ? { level: m[1].length, text: m[2], line: i } : null;
    }).filter(Boolean);
  if (!headings.length) { showToast('No headings found'); return; }
  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal"><h3>Table of Contents</h3>
      <div style="max-height:350px;overflow-y:auto">
        ${headings.map(h => `<div class="toc-item" style="padding-left:${(h.level - 1) * 16}px" onclick="jumpToHeading(${h.line});document.getElementById('modalContainer').innerHTML='';">${esc(h.text)}</div>`).join('')}
      </div>
      <div class="modal-buttons" style="margin-top:12px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">Close</button>
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
    showToast('Public link copied!');
  } catch (e) {
    showToast('Share failed');
  }
}

// ── Embed media ──
function embedMedia(url) {
  // YouTube
  let m = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]+)/);
  if (m) return `<div class="embed-container"><iframe src="https://www.youtube.com/embed/${m[1]}" frameborder="0" allowfullscreen style="width:100%;aspect-ratio:16/9;border-radius:8px"></iframe></div>`;
  // Vimeo
  m = url.match(/vimeo\.com\/(\d+)/);
  if (m) return `<div class="embed-container"><iframe src="https://player.vimeo.com/video/${m[1]}" frameborder="0" allowfullscreen style="width:100%;aspect-ratio:16/9;border-radius:8px"></iframe></div>`;
  return null;
}

// ── Typewriter mode ──
let typewriterMode = false;

function toggleTypewriter() {
  typewriterMode = !typewriterMode;
  document.body.classList.toggle('typewriter-mode', typewriterMode);
  showToast(typewriterMode ? 'Typewriter mode on' : 'Typewriter mode off');
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
function showStats() {
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
    return `<div title="${date}: ${count} notes" style="width:20px;height:20px;border-radius:3px;background:var(--accent);opacity:${Math.max(0.1, pct / 100)}" title="${label}"></div>`;
  }).join('');

  const el = document.getElementById('modalContainer');
  el.innerHTML = `<div class="modal-overlay" onclick="if(event.target===this){document.getElementById('modalContainer').innerHTML='';}">
    <div class="modal" style="max-width:450px"><h3>Statistics</h3>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
        <div class="stat-card"><div class="stat-num">${totalNotes}</div><div class="stat-label">Notes</div></div>
        <div class="stat-card"><div class="stat-num">${totalWords.toLocaleString()}</div><div class="stat-label">Words</div></div>
        <div class="stat-card"><div class="stat-num">${notebooks.length}</div><div class="stat-label">Notebooks</div></div>
        <div class="stat-card"><div class="stat-num">${tags.length}</div><div class="stat-label">Tags</div></div>
      </div>
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">Notes per notebook</div>
      ${Object.entries(nbCounts).map(([name, count]) => `<div style="display:flex;justify-content:space-between;font-size:12px;padding:3px 0"><span>${esc(name)}</span><span style="color:var(--text-muted)">${count}</span></div>`).join('')}
      <div style="font-size:12px;color:var(--text-muted);margin:12px 0 6px">Activity (14 days)</div>
      <div style="display:flex;gap:3px;flex-wrap:wrap">${heatmap}</div>
      <div class="modal-buttons" style="margin-top:14px">
        <button class="btn btn-sm" onclick="document.getElementById('modalContainer').innerHTML=''">Close</button>
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
    <input id="frFind" placeholder="Find..." oninput="highlightFindsInContent()">
    <input id="frReplace" placeholder="Replace...">
    <button class="btn btn-sm" onclick="doReplace(false)">Replace</button>
    <button class="btn btn-sm" onclick="doReplace(true)">All</button>
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
  showToast('Replaced');
}

// ── Zen writing mode ──
let zenMode = false;

function toggleZenMode() {
  zenMode = !zenMode;
  document.body.classList.toggle('zen-mode', zenMode);
  showToast(zenMode ? 'Zen mode on' : 'Zen mode off');
}

// ── Auto-save indicator ──
let saveIndicatorTimer = null;

function showSaveIndicator(state) {
  const el = document.getElementById('saveIndicator');
  if (!el) return;
  if (state === 'saving') {
    el.className = 'save-indicator saving';
    el.textContent = '';
  } else {
    el.className = 'save-indicator saved';
    el.textContent = '✓';
    clearTimeout(saveIndicatorTimer);
    saveIndicatorTimer = setTimeout(() => { el.className = 'save-indicator'; }, 3000);
  }
}

// ── Backlinks panel ──
function getBacklinks(noteTitle) {
  if (!noteTitle) return [];
  const lower = noteTitle.toLowerCase();
  return notes.filter(n => {
    const body = (n.body || '').toLowerCase();
    return body.includes('[[' + lower + ']]');
  });
}

function renderBacklinks() {
  if (!activeNote) return;
  const backlinks = getBacklinks(activeNote.title);
  const el = document.getElementById('editorFooter');
  if (!el) return;
  if (backlinks.length) {
    const links = backlinks.map(n => `<a href="#" class="backlink" onclick="event.preventDefault();selectNote(${n.id})">${esc(n.title)}</a>`).join(', ');
    el.innerHTML += `<div class="backlinks-line">Linked from: ${links}</div>`;
  }
}

// ── Notebook cover image ──
async function setNotebookCover(nbName) {
  const url = await showPrompt('Notebook Cover', 'Image URL:', (config.nb_covers || {})[nbName] || '');
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
  const footer = document.getElementById('editorFooter');
  if (!footer) return;
  const images = atts.filter(isImageAttachment);
  const gallery = document.createElement('div');
  gallery.className = 'attachment-gallery';
  atts.forEach(a => {
    const name = a.filename || a.name;
    if (isImageAttachment(a)) {
      const img = document.createElement('img');
      img.className = 'gallery-thumb';
      img.src = a.url; img.alt = name; img.loading = 'lazy';
      img.onclick = () => openLightbox(images, images.indexOf(a));
      gallery.appendChild(img);
    } else {
      const div = document.createElement('div');
      div.className = 'gallery-file';
      div.textContent = name;
      div.onclick = () => { if (/\.html?$/i.test(a.url)) viewAttachment(a.url, name); else window.open(a.url, '_blank'); };
      gallery.appendChild(div);
    }
  });
  footer.after(gallery);
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
      <button class="lb-close" onclick="closeLightbox()" aria-label="Close">&#10005;</button>
      <button class="lb-nav lb-prev" onclick="lightboxStep(-1)" aria-label="Previous">&#8249;</button>
      <div class="lb-stage"><img id="lbImg" alt=""><div class="lb-caption" id="lbCaption"></div></div>
      <button class="lb-nav lb-next" onclick="lightboxStep(1)" aria-label="Next">&#8250;</button>`;
    lb.onclick = (e) => { if (e.target === lb) closeLightbox(); };
    document.body.appendChild(lb);
    document.addEventListener('keydown', lightboxKey);
  }
  lb.style.display = 'flex';
  renderLightbox();
}
function renderLightbox() {
  const a = _lbImages[_lbIndex];
  if (!a) return;
  document.getElementById('lbImg').src = a.url;
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
    if (e.GPSLatitude && e.GPSLongitude) bits.push('<a href="https://maps.google.com/?q=' + e.GPSLatitude + ',' + e.GPSLongitude + '" target="_blank" rel="noopener">📍 map</a>');
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

async function queuedSave(notebook, noteId, title, body) {
  try {
    showSaveIndicator('saving');
    const result = await api.save_note(notebook, noteId, title, body);
    showSaveIndicator('saved');
    hasUnsavedChanges = false;
    return result;
  } catch (e) {
    saveQueue.push({ notebook, noteId, title, body, time: Date.now() });
    showToast('Save failed — will retry');
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
    return;
  }
  const item = saveQueue[0];
  try {
    await api.save_note(item.notebook, item.noteId, item.title, item.body);
    saveQueue.shift();
    showToast('Queued save recovered');
    showSaveIndicator('saved');
    if (!saveQueue.length) {
      clearInterval(saveRetryTimer);
      saveRetryTimer = null;
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
  showToast(`${matches.length} match${matches.length !== 1 ? 'es' : ''}`);
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
