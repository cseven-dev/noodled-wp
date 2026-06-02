<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( Noodled_Settings::get_brand_name() ); ?></title>
<meta name="theme-color" content="#111113">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( Noodled_Settings::get_brand_name() ); ?>">
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-HMPWDP2MTN"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-HMPWDP2MTN');
</script>
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo esc_url( NOODLED_URL . 'assets/icon-192.png' ); ?>">
<link rel="manifest" href="<?php echo esc_url( NOODLED_URL . 'assets/manifest.json' ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( NOODLED_URL . 'assets/icon-192.png' ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( NOODLED_URL . 'assets/css/noodled.css' ); ?>?v=<?php echo NOODLED_VERSION . '.' . filemtime( NOODLED_PATH . 'assets/css/noodled.css' ); ?>">
<?php $accent = Noodled_Settings::get_accent_color(); if ( $accent && $accent !== '#0078d4' ) : ?>
<style>:root { --accent: <?php echo esc_attr( $accent ); ?>; --accent-hover: <?php echo esc_attr( $accent ); ?>cc; }</style>
<?php endif; ?>
</head>
<body>

<!-- Splash -->
<div class="splash" id="splash">
  <div class="splash-logo"><?php echo esc_html( Noodled_Settings::get_brand_name() ); ?></div>
  <div class="splash-version">v<?php echo NOODLED_VERSION; ?></div>
  <div class="splash-loader"></div>
</div>

<!-- Offline banner -->
<div class="offline-banner" id="offlineBanner">You're offline — changes will save when you reconnect</div>

<!-- Top toolbar — clean and minimal -->
<div class="toolbar">
  <button class="btn-icon mobile-menu" onclick="toggleSidebar()" title="Menu">&#9776;</button>
  <button class="btn-icon mobile-back" onclick="closeNote()" title="Back">&#8592;</button>
  <span class="logo" onclick="closeNote()" style="cursor:pointer"><?php echo esc_html( Noodled_Settings::get_brand_name() ); ?></span>
  <span class="logo-version">v<?php echo NOODLED_VERSION; ?></span>
  <button class="btn btn-sm hide-mobile" id="newNoteBtn" onclick="createNote()" title="New note">+ New Note</button>
  <button class="btn btn-sm hide-mobile" id="photoNoteBtn" onclick="uploadPhotos()" title="Upload photos into a new note">+ Photos</button>
  <input type="file" id="photoUploadInput" accept="image/*" multiple style="display:none" onchange="handlePhotoUpload(this)">
  <input type="file" id="enexImportInput" accept=".enex" style="display:none" onchange="handleEvernoteImport(this)">
  <span class="spacer"></span>
  <div class="focus-timer" id="focusTimer">
    <span id="focusTime">25:00</span>
    <button class="btn btn-sm" onclick="stopFocusTimer()" style="padding:2px 6px;font-size:10px">&#10005;</button>
  </div>
  <span class="status" id="status"></span>
  <button class="btn btn-sm" onclick="syncPull()" id="syncPullBtn" title="Sync with GitHub">Sync</button>
  <?php if ( Noodled_Plaud::is_configured() ) : ?>
  <button class="btn btn-sm" onclick="syncPlaud()" id="plaudSyncBtn" title="Import Plaud recordings">Plaud</button>
  <?php endif; ?>
  <div class="toolbar-menu-wrap">
    <button class="btn-icon" onclick="toggleAppMenu()" title="Menu" id="appMenuBtn">&#8942;</button>
    <div class="toolbar-dropdown" id="appMenu">
      <?php if ( ! empty( $config['user']['owner'] ) ) : ?>
      <div class="dropdown-item" onclick="manageUsers()">&#128101; Manage people</div>
      <div class="dropdown-sep"></div>
      <?php endif; ?>
      <div class="dropdown-item" onclick="showTemplates()">New from template</div>
      <div class="dropdown-item" onclick="openDailyJournal()">Daily journal</div>
      <div class="dropdown-sep"></div>
      <div class="dropdown-item" onclick="showFocusOptions()">Focus timer</div>
      <div class="dropdown-item" onclick="showTagCloud()">Browse tags</div>
      <div class="dropdown-item" onclick="showLinkGraph()">Note links</div>
      <div class="dropdown-item" onclick="showStats()">Statistics</div>
      <div class="dropdown-item" onclick="toggleQuickCapture()">Quick capture</div>
      <div class="dropdown-item" onclick="importEvernote()">Import from Evernote</div>
      <div class="dropdown-sep"></div>
      <div class="dropdown-item" onclick="showShortcutsHelp()">Keyboard shortcuts</div>
      <div class="dropdown-item" onclick="toggleTheme()">Toggle theme</div>
      <div class="dropdown-sep"></div>
      <div class="dropdown-item" onclick="doLogout()">Logout</div>
    </div>
  </div>
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
      <div class="nb-item" id="nbStarred" onclick="showStarred()">
        <span class="nb-name">&#9733; Starred</span>
      </div>
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
      <button class="sort-btn" id="sortBtn" onclick="cycleSort()" title="Change sort order">Modified</button>
    </div>
    <div class="pull-indicator" id="pullIndicator">&#8595; Pull to refresh</div>
    <div class="note-list" id="noteList"></div>
    <div class="note-count" id="noteCount">
      <button class="btn-icon" onclick="toggleBulkMode()" title="Select multiple" style="font-size:11px;float:right">&#9745;</button>
    </div>
    <div class="bulk-bar" id="bulkBar">
      <span><span id="bulkCount">0</span> selected</span>
      <button class="btn btn-sm" onclick="bulkMove()">Move</button>
      <button class="btn btn-sm" onclick="bulkDelete()" style="color:var(--red)">Delete</button>
      <button class="btn btn-sm" onclick="toggleBulkMode()">Cancel</button>
    </div>
  </div>

  <!-- Col 3: Content -->
  <div class="col-content" id="colContent">
    <div class="empty-state" id="emptyState">
      <div class="empty-icon">&#128221;</div>
      <div class="empty-title">No note selected</div>
      <div class="empty-sub">Pick a note from the list or create a new one</div>
    </div>
  </div>
</div>

<!-- Mobile bottom nav -->
<nav class="bottom-nav" id="bottomNav">
  <button class="bottom-nav-item" onclick="closeNote(); closeSidebar();">
    <span class="bottom-nav-icon">&#128196;</span>
    <span class="bottom-nav-label">Notes</span>
  </button>
  <button class="bottom-nav-item" onclick="document.getElementById('searchInput').focus(); closeNote();">
    <span class="bottom-nav-icon">&#128269;</span>
    <span class="bottom-nav-label">Search</span>
  </button>
  <button class="bottom-nav-item bottom-nav-primary" onclick="createNote()">
    <span class="bottom-nav-icon">&#43;</span>
  </button>
  <button class="bottom-nav-item" onclick="syncPull()">
    <span class="bottom-nav-icon">&#8635;</span>
    <span class="bottom-nav-label">Sync</span>
  </button>
  <button class="bottom-nav-item" onclick="toggleSidebar()">
    <span class="bottom-nav-icon">&#9776;</span>
    <span class="bottom-nav-label">Menu</span>
  </button>
</nav>

<!-- Split view panel -->
<div class="split-view" id="splitView"></div>

<!-- Quick capture -->
<div class="quick-capture" id="quickCapture">
  <input type="text" id="qcInput" placeholder="Quick thought..." onkeydown="if(event.key==='Enter'){submitQuickCapture();} if(event.key==='Escape'){toggleQuickCapture();}">
  <button class="btn btn-sm" onclick="submitQuickCapture()">Save</button>
  <button class="btn btn-sm" onclick="toggleQuickCapture()">&#10005;</button>
</div>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<div class="toast" id="toast"></div>
<div id="modalContainer"></div>
<div id="ctxContainer"></div>

<script>
const noodledConfig = <?php echo wp_json_encode( $config ); ?>;
</script>
<script src="<?php echo esc_url( NOODLED_URL . 'assets/js/noodled.js' ); ?>?v=<?php echo NOODLED_VERSION . '.' . filemtime( NOODLED_PATH . 'assets/js/noodled.js' ); ?>"></script>
<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('<?php echo esc_url( NOODLED_URL . 'assets/sw.js' ); ?>');
}
</script>

</body>
</html>
