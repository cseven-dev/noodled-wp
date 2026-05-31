<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>noodled</title>
<meta name="theme-color" content="#111113">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="noodled">
<link rel="manifest" href="<?php echo esc_url( NOODLED_URL . 'assets/manifest.json' ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( NOODLED_URL . 'assets/icon-192.png' ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( NOODLED_URL . 'assets/css/noodled.css' ); ?>?v=<?php echo NOODLED_VERSION; ?>">
</head>
<body>

<!-- Splash -->
<div class="splash" id="splash">
  <div class="splash-logo">noodled</div>
  <div class="splash-version">v<?php echo NOODLED_VERSION; ?></div>
  <div class="splash-loader"></div>
</div>

<!-- Offline banner -->
<div class="offline-banner" id="offlineBanner">You're offline — changes will save when you reconnect</div>

<!-- Toolbar -->
<div class="toolbar">
  <button class="btn-icon mobile-menu" onclick="toggleSidebar()" title="Menu">&#9776;</button>
  <button class="btn-icon mobile-back" onclick="closeNote()" title="Back">&#8592;</button>
  <span class="logo">noodled</span>
  <span style="font-size:10px;color:var(--text-muted);margin-right:4px" class="hide-mobile">v<?php echo NOODLED_VERSION; ?></span>
  <button class="btn btn-sm" onclick="createNote()">+ New Note</button>
  <span class="spacer"></span>
  <span class="status" id="status"></span>
  <button class="btn btn-sm" onclick="syncPull()" id="syncPullBtn">Sync</button>
  <div class="user-menu">
    <span class="user-name"><?php echo esc_html( $config['user']['name'] ); ?></span>
    <button class="btn-logout" onclick="doLogout()">Logout</button>
  </div>
  <button class="btn-icon hide-mobile" onclick="showShortcutsHelp()" title="Keyboard shortcuts" style="font-size:12px;font-weight:700">?</button>
  <button class="btn-icon" onclick="toggleTheme()" title="Toggle theme" id="themeBtn">&#9680;</button>
</div>

<!-- Main 3-column layout -->
<div class="main">

  <!-- Col 1: Notebooks -->
  <div class="col-notebooks">
    <div class="header">
      <span>Notebooks</span>
      <button class="btn-icon btn-sm" onclick="createNotebook()" title="New notebook">+</button>
    </div>
    <div class="nb-all active" id="nbAll" onclick="selectNotebook(null)">All Notes</div>
    <div class="nb-list" id="nbList"></div>
    <div style="border-top:1px solid var(--border);margin-top:auto;padding-top:4px">
      <div class="nb-item" id="nbTrash" onclick="selectTrash()">
        <span class="nb-name">&#128465; Trash</span>
        <span class="count" id="trashCount">0</span>
      </div>
    </div>
  </div>

  <!-- Col 2: Note list -->
  <div class="col-notes">
    <div class="search-box">
      <input type="text" id="searchInput" placeholder="Search notes..." oninput="onSearch()">
    </div>
    <div class="note-list" id="noteList"></div>
    <div class="note-count" id="noteCount"></div>
  </div>

  <!-- Col 3: Content -->
  <div class="col-content" id="colContent">
    <div class="empty-state" id="emptyState">
      <div class="icon">&#127837;</div>
      <div>Select a note or create a new one</div>
    </div>
  </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<div class="toast" id="toast"></div>
<div id="modalContainer"></div>
<div id="ctxContainer"></div>

<script>
const noodledConfig = <?php echo wp_json_encode( $config ); ?>;
</script>
<script src="<?php echo esc_url( NOODLED_URL . 'assets/js/noodled.js' ); ?>?v=<?php echo NOODLED_VERSION; ?>"></script>
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('<?php echo esc_url( NOODLED_URL . 'assets/sw.js' ); ?>');
}
</script>

</body>
</html>
