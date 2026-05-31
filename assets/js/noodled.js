/* noodled for WordPress — v1.0.0
   API adapter + UI logic extracted from desktop app */

// ── API Adapter ──
const api = {
  _base: noodledConfig.apiBase,
  _nonce: noodledConfig.nonce,

  async _fetch(endpoint, options = {}) {
    const url = this._base + endpoint;
    const headers = {
      'X-WP-Nonce': this._nonce,
      'Content-Type': 'application/json',
      ...options.headers,
    };
    try {
      const res = await fetch(url, { ...options, headers, credentials: 'same-origin' });
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
  get_config()                           { return this._fetch('/config'); },
  set_config(key, value)                 { return this._fetch('/config', { method: 'PUT', body: JSON.stringify({ key, value }) }); },
  get_version()                          { return Promise.resolve(noodledConfig.version); },
  open_folder()                          { return Promise.resolve(); },
  sync_plaud()                           { return Promise.resolve({ downloaded: 0, total: 0 }); },
  git_push()                             { return this._fetch('/sync/push', { method: 'POST' }); },
  git_pull()                             { return this._fetch('/sync/pull', { method: 'POST' }); },
  git_status()                           { return this._fetch('/sync/status'); },
  start_auto_sync(interval)              { return this._fetch('/config', { method: 'PUT', body: JSON.stringify({ key: 'git_auto_sync', value: interval }) }); },
  get_last_sync_time()                   { return this._fetch('/sync/status'); },
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

// ── Init ──
document.addEventListener('DOMContentLoaded', async () => {
  // Show loading skeletons
  showLoadingSkeleton();

  try {
    config = await api.get_config();
  } catch (e) {
    config = { theme: 'dark' };
  }
  applyTheme(config.theme || 'dark');

  try {
    await loadNotebooks();
    await loadNotes();
    updateTrashCount();
  } catch (e) {
    setStatus('Failed to load: ' + e.message);
  }

  document.getElementById('splash').classList.add('hidden');
  document.addEventListener('keydown', handleGlobalKey);

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
  renderNotebooks();
}

function renderNotebooks() {
  const el = document.getElementById('nbList');
  el.innerHTML = notebooks.map(nb => {
    const name = nb.name;
    const active = activeNotebook === name ? ' active' : '';
    const shared = nb.access !== 'owner' ? '<span style="font-size:9px;color:var(--text-muted);margin-left:4px" title="Shared with you">&#128279;</span>' : '';
    const readOnly = nb.access === 'read' ? '<span style="font-size:9px;color:var(--text-muted);margin-left:2px" title="Read only">&#128274;</span>' : '';
    return `<div class="nb-item${active}" onclick="selectNotebook('${esc(name)}')" oncontextmenu="event.preventDefault(); showNbContext(event, '${esc(name)}')">
      <span class="nb-name">${esc(name)}${shared}${readOnly}</span>
      <span class="count">${nb.count}</span>
    </div>`;
  }).join('');

  document.getElementById('nbAll').className = 'nb-all' + (activeNotebook === null && !viewingTrash ? ' active' : '');
}

async function selectNotebook(name) {
  viewingTrash = false;
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
      </div>
    </div>
  `;
  document.getElementById('shareEmail').focus();
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
    else { msg.textContent = 'Shared!'; msg.style.color = 'var(--green)'; }
  } catch (e) {
    msg.textContent = 'Failed: ' + e.message; msg.style.color = 'var(--red)';
  }
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
  const q = document.getElementById('searchInput').value.toLowerCase();
  if (q) {
    filteredNotes = notes.filter(n =>
      n.title.toLowerCase().includes(q) ||
      n.body.toLowerCase().includes(q)
    );
  } else {
    filteredNotes = [...notes];
  }
  filteredNotes.sort((a, b) => {
    if (a.pinned !== b.pinned) return b.pinned ? 1 : -1;
    const cmp = (b.modified || '').localeCompare(a.modified || '');
    return cmp !== 0 ? cmp : (b.created || '').localeCompare(a.created || '');
  });
  renderNoteList();
}

function onSearch() { filterNotes(); }

function renderNoteList() {
  const el = document.getElementById('noteList');
  el.innerHTML = filteredNotes.map(n => {
    const preview = (n.body || '').replace(/[#*_\[\]]/g, '').substring(0, 120);
    const isActive = activeNote && activeNote.id === n.id;
    const badge = n.source === 'plaud' ? '<span class="source-badge">plaud</span>' : '';
    const pin = n.pinned ? '<span style="margin-right:4px;font-size:11px" title="Pinned">&#128204;</span>' : '';
    return `
      <div class="note-item-wrapper" data-note-id="${n.id}">
        <div class="note-item-actions">
          <button class="note-delete-btn" onclick="event.stopPropagation(); confirmDelete(${n.id})">Delete</button>
        </div>
        <div class="note-item ${isActive ? 'active' : ''}"
             onclick="selectNote(${n.id})"
             oncontextmenu="event.preventDefault(); showNoteContext(event, ${n.id})">
          <div class="title">${pin}${esc(n.title)}${badge}</div>
          <div class="meta">
            <span>${esc(n.modified || n.created)}</span>
            <span>${esc(n.notebook)}</span>
          </div>
          <div class="preview">${esc(preview)}</div>
        </div>
      </div>
    `;
  }).join('');

  document.getElementById('noteCount').textContent = `${filteredNotes.length} note${filteredNotes.length !== 1 ? 's' : ''}`;
  setupSwipeHandlers();
  setupLongPressHandlers();
}

async function selectNote(noteId) {
  // Show immediate loading feedback
  const item = document.querySelector(`.note-item-wrapper[data-note-id="${noteId}"] .note-item`);
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

  showContextMenu(event, [
    { label: pinLabel, action: () => togglePin(noteId) },
    { label: 'Copy text', action: () => copyNoteText(noteId) },
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
             placeholder="Note title" onchange="saveTitleOnly()">
      <span style="font-size:11px;color:var(--text-muted)">${esc(n.modified || '')}</span>
      <button class="btn btn-sm" onclick="insertBullet()">&#9679; List</button>
      <button class="btn btn-sm" onclick="insertChecklistItem()">&#9744; Check</button>
      <button class="btn btn-sm" onclick="insertHeading()">H</button>
      <button class="btn btn-sm" onclick="copyBody()">Copy</button>
    </div>
    <div class="content-body" id="dropZone">
      <div class="rendered-content" id="noteBody" contenteditable="true"
           oninput="schedSave(); updateWordCount()" onkeydown="handleContentKey(event)">${renderMarkdown(n.body)}</div>
    </div>
    <div class="editor-footer" id="editorFooter"></div>
  `;
  setupDropZone();
  updateWordCount();
}

function updateWordCount() {
  const el = document.getElementById('editorFooter');
  if (!el || !activeNote) return;
  const body = document.getElementById('noteBody');
  if (!body) return;
  const text = body.innerText || '';
  const words = text.trim().split(/\s+/).filter(w => w.length > 0);
  const wordCount = words.length;
  const charCount = text.length;
  const readTime = Math.max(1, Math.ceil(wordCount / 200));
  el.textContent = `${wordCount} words \u00b7 ${charCount} chars \u00b7 ${readTime} min read`;
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

// ── Auto-save ──
function schedSave() {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(doSave, 2000);
}

async function doSave() {
  if (!activeNote || viewingTrash) return;
  const title = document.getElementById('titleInput')?.value || activeNote.title;
  const el = document.getElementById('noteBody');
  if (!el) return;
  const body = htmlToMarkdown(el);
  activeNote.body = body;
  const result = await api.save_note(activeNote.notebook, activeNote.id, title, body);
  if (!result.error) { activeNote = result; await loadNotes(); }
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

// ── Drag & drop attachments ──
async function handleDrop(e) {
  if (!activeNote || !e.dataTransfer.files.length) return;
  for (const file of e.dataTransfer.files) {
    const reader = new FileReader();
    reader.onload = async () => {
      const b64 = reader.result.split(',')[1];
      const result = await api.save_attachment(activeNote.notebook, activeNote.id, file.name, b64);
      if (result.filename) {
        const isImage = /\.(png|jpg|jpeg|gif|webp|svg)$/i.test(file.name);
        const ref = isImage
          ? `\n![${result.filename}](${result.url || result.filename})\n`
          : `\n[${result.filename}](${result.url || result.filename})\n`;
        activeNote.body = (activeNote.body || '') + ref;
        await saveAndRerender();
        showToast(`Attached: ${result.filename}`);
      }
    };
    reader.readAsDataURL(file);
  }
}

// ── Checkbox toggle ──
async function toggleCheck(lineIndex) {
  if (!activeNote) return;
  const el = document.getElementById('noteBody');
  if (el) activeNote.body = htmlToMarkdown(el);
  const lines = activeNote.body.split('\n');
  if (lineIndex >= lines.length) return;
  const line = lines[lineIndex];
  if (/- \[ \]/.test(line)) lines[lineIndex] = line.replace('- [ ]', '- [x]');
  else if (/- \[[xX]\]/.test(line)) lines[lineIndex] = line.replace(/- \[[xX]\]/, '- [ ]');
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
  if (!text) return '<span style="color:var(--text-muted)">(empty note \u2014 click to edit)</span>';
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
      .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2">$1</a>')
      .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g, '<em>$1</em>')
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/\[(\d+:\d+(?::\d+)?)\]\s*/g, '<span class="timestamp">[$1]</span> ');
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
      out.push(`<li><input type="checkbox" ${checked ? 'checked' : ''} contenteditable="false" onmousedown="event.stopPropagation(); event.stopImmediatePropagation();" onclick="event.stopPropagation(); toggleCheck(${i})"><span class="${checked ? 'check-done' : ''}">${inlineFormat(escLine(checkMatch[2]))}</span></li>`);
      continue;
    }
    const bulletMatch = trimmed.match(/^-\s+(.+)$/);
    if (bulletMatch) { if (!inList || listType !== 'ul') { closeList(); out.push('<ul>'); inList = true; listType = 'ul'; } out.push(`<li>${inlineFormat(escLine(bulletMatch[1]))}</li>`); continue; }
    const numMatch = trimmed.match(/^\d+\.\s+(.+)$/);
    if (numMatch) { if (!inList || listType !== 'ol') { closeList(); out.push('<ol>'); inList = true; listType = 'ol'; } out.push(`<li>${inlineFormat(escLine(numMatch[1]))}</li>`); continue; }
    if (trimmed.startsWith('> ')) { closeList(); out.push(`<blockquote>${inlineFormat(escLine(trimmed.substring(2)))}</blockquote>`); continue; }
    closeList();
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
function esc(s) { if (!s) return ''; const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function escAttr(s) { return (s || '').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

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
  window.location.href = noodledConfig.apiBase.replace('/wp-json/noodled/v1', '') + '/wp-login.php?action=logout&_wpnonce=' + noodledConfig.logoutNonce;
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

// ── Swipe to delete (mobile) ──
function setupSwipeHandlers() {
  document.querySelectorAll('.note-item-wrapper').forEach(wrapper => {
    const item = wrapper.querySelector('.note-item');
    let startX = 0, currentX = 0, swiping = false;

    item.addEventListener('touchstart', e => {
      startX = e.touches[0].clientX;
      currentX = startX;
      swiping = true;
      item.style.transition = 'none';
    }, { passive: true });

    item.addEventListener('touchmove', e => {
      if (!swiping) return;
      currentX = e.touches[0].clientX;
      const dx = Math.min(0, currentX - startX);
      if (dx < -10) {
        item.style.transform = `translateX(${Math.max(dx, -80)}px)`;
      }
    }, { passive: true });

    item.addEventListener('touchend', () => {
      swiping = false;
      item.style.transition = 'transform 0.2s ease';
      const dx = currentX - startX;
      if (dx < -60) {
        item.style.transform = 'translateX(-80px)';
      } else {
        item.style.transform = 'translateX(0)';
      }
    });
  });
}

// ── Long-press for context menu (mobile) ──
function setupLongPressHandlers() {
  document.querySelectorAll('.note-item-wrapper').forEach(wrapper => {
    const noteId = parseInt(wrapper.dataset.noteId);
    const item = wrapper.querySelector('.note-item');
    let timer = null;
    let moved = false;

    item.addEventListener('touchstart', e => {
      moved = false;
      timer = setTimeout(() => {
        if (!moved) {
          e.preventDefault();
          const touch = e.changedTouches?.[0] || e.touches[0];
          showNoteContext({ clientX: touch.clientX, clientY: touch.clientY, preventDefault: () => {} }, noteId);
        }
      }, 500);
    }, { passive: false });

    item.addEventListener('touchmove', () => { moved = true; clearTimeout(timer); });
    item.addEventListener('touchend', () => clearTimeout(timer));
    item.addEventListener('touchcancel', () => clearTimeout(timer));
  });
}

// ── Confirm dialog (replaces browser confirm) ──
function confirmDelete(noteId) {
  const note = filteredNotes.find(n => n.id === noteId);
  const title = note ? note.title : 'this note';

  const el = document.createElement('div');
  el.className = 'confirm-overlay';
  el.innerHTML = `
    <div class="confirm-box">
      <p>Delete "${esc(title)}"?</p>
      <div class="confirm-buttons">
        <button class="btn" onclick="this.closest('.confirm-overlay').remove()">Cancel</button>
        <button class="btn btn-danger" style="background:var(--red);color:#fff;border-color:var(--red)" onclick="this.closest('.confirm-overlay').remove(); deleteNote(${noteId})">Delete</button>
      </div>
    </div>
  `;
  document.body.appendChild(el);
}

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
