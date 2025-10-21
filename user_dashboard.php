<?php
session_start();
// Strict cache control to prevent back after logout
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
include 'wavedb.php';

// ✅ Allow only users (LAR_level = 1)
if (!isset($_SESSION["username"]) || $_SESSION["LAR_level"] != 1) {
  header("Location: wavelogin.php");
  exit;
}

// Default tab
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'water';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Righteous&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="icon" type="image/png" href="wave_logo2.png">
<link rel="stylesheet" href="ad_dashboard.css">
<!-- Admin styles: rely primarily on ad_dashboard.css; keep only minimal page-specific overrides to enforce layout and glassy containers -->
<style>
  /* Page layout & background (use admin background image) */
    html, body { height: auto; width: 100%; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }

  /* Keep layout sizing but do NOT override admin visuals (allow ad_dashboard.css to control card backgrounds) */
  .users-container { max-width: 100vw; box-sizing: border-box; }
  /* Leave .main-content styling to ad_dashboard.css so the white card background and glass layout match admin */

  /* Use admin sizing to match header/nav spacing exactly (keeps CSS authoritative in ad_dashboard.css) */
    /* Use admin sizing to match header/nav spacing exactly (ad_dashboard.css defines the visuals) */
  .main-navigation { width: 240px; }
  .main-content {
    margin-left: 240px;
    margin-top: 90px;
    padding: 22px 28px;
    /* Enforce admin main white card visual to avoid accidental transparency */
    background: #fff !important;
    border-top-left-radius: 32px;
    border-bottom-left-radius: 32px;
    box-shadow: none !important;
    width: calc(100vw - 240px);
    min-height: calc(100vh - 90px);
  }

  /* Monitoring cards — mirror admin compact layout for exact parity */
  .water-grid { display: grid; grid-template-columns: 2fr 3fr; gap: 12px; margin-bottom: 8px; }
  .big-card.sensor-card { min-height: 160px; font-size: 1rem; border-radius: 10px; }
  .right-grid { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: repeat(3, 1fr); gap: 6px; }
  .sensor-card { background: linear-gradient(145deg, #7ed6f7, #5faee3); border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); color: #222; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 38px; font-size: 0.92rem; padding: 6px 2px; }
  .sensor-card.wide { grid-column: span 2; }
  .sensor-card h3 { margin: 0 0 2px 0; font-size: 0.92rem; font-weight: 700; }
  .sensor-card p { margin: 0; font-size: 1rem; font-weight: 600; }

  /* notifications/tools should visually match admin containers (use ad_dashboard.css for full visuals) */
  .notifications-wrap { background: #fff !important; border-radius: 12px; padding: 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
  .tools-stage { width:100%; min-height:70vh; background:#fff !important; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.08); padding:16px; }
  /* Ensure all card-like containers use white background to match admin layout */
  .card, .chart-container, .white-card { background: #fff !important; }

  /* Small helper adjustments (pills, badges) */
  .st-pill { background: linear-gradient(180deg,#49d7ff,#1aa6ff) !important; border-radius: 9999px !important; box-shadow: inset 0 1px 0 rgba(255,255,255,.35), 0 6px 14px rgba(0,0,0,.08) !important; color:#083344 !important; border:1px solid rgba(255,255,255,.35); }
  .badge-hint { display:inline-block; margin-left:8px; padding:2px 8px; font-size:0.75rem; color:#0f5132; background:#d1e7dd; border-radius:9999px; }
</style>

<!-- injected admin-style removed; page relies on ad_dashboard.css and concise overrides above -->
</head>
<body>
<!-- Loading overlay (admin-style) -->
<div id="loadingOverlay" style="display:none;align-items:center;justify-content:center;flex-direction:column;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:9999;background:transparent;">
  <img src="wave_logo2.png" alt="Logo" class="loading-logo" style="width:90px;height:90px;margin-bottom:18px;animation:spinLogo 1s linear infinite;">
  <div class="loading-text" style="font-size:2rem;color:#fff;font-family:'Righteous',cursive;text-shadow:0 2px 8px rgba(0,0,0,0.18);">Loading...</div>
</div>
<style>
@keyframes spinLogo { 0%{transform:rotate(0deg)}100%{transform:rotate(360deg)} }
.logout-btn-ocean {
  height: 26px;
  padding: 0 10px 0 8px;
  border: none !important;
  border-radius: 14px;
  background: linear-gradient(135deg, #a7ffeb 0%, #40c4ff 60%, #00bcd4 100%);
  color: #01579b;
  font-weight: 700;
  font-size: 0.97em;
  text-align: center;
  cursor: pointer;
  box-shadow: none !important;
  display: flex;
  align-items: center;
  gap: 4px;
  transition: background 0.22s, color 0.22s, box-shadow 0.22s;
  width: fit-content;
  min-width: 0;
  max-width: 120px;
  margin: 8px auto 8px auto;
}
#dropdownMenu { background: transparent !important; box-shadow: none !important; border-radius: 0 !important; display: flex !important; flex-direction: column !important; align-items: center !important; justify-content: flex-start !important; padding: 0 !important; min-width: unset !important; }
.logout-btn-ocean .logout-text { padding-right: 2px; }
.logout-btn-ocean i { font-size: 1em; color: #00bcd4; }
.logout-text { font-size: 0.98em; color: #01579b; font-weight: 600; margin-left: 2px; letter-spacing: 0.02em; }
.logout-btn-ocean:hover { background: linear-gradient(135deg, #40c4ff 0%, #a7ffeb 100%); color: #fff; box-shadow: 0 4px 12px 0 #00bcd433; }
.logout-btn-ocean:hover i, .logout-btn-ocean:hover .logout-text { color: #fff; }
</style>
<script>
// Prevent browser back to cached session
if (window.performance && window.performance.navigation && window.performance.navigation.type === 2) {
  window.location.reload();
}
</script>

<div class="header">
  <div class="header-left">
    <img src="isu.png" alt="ISU Logo" height="65" width="65" class="isu-logo">
    <div class="system-title">User Dashboard</div>
    <div class="admin-title"><img src="wave_logo2.png" alt="WAVE Logo"></div>
  </div>
  <div class="header-right">
    <div class="admin-dropdown" onclick="toggleDropdown()">
      <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION["username"]); ?> ▾
      <div id="dropdownMenu" class="dropdown-content">
        <button type="button" id="logoutBtnOcean" class="logout-btn-ocean"><i class="fas fa-sign-out-alt"></i><span class="logout-text">Logout</span></button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('logoutBtnOcean').addEventListener('click', e => {
    e.preventDefault();
    Swal.fire({
      title: 'Logout',
      text: 'Are you sure you want to logout?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#00bcd4',
      cancelButtonColor: '#aaa',
      confirmButtonText: 'Logout',
      cancelButtonText: 'Cancel'
    }).then(result => {
      if (result.isConfirmed) performLogout();
    });
  });
});

function toggleDropdown() {
  document.getElementById('dropdownMenu').classList.toggle('dropdown-show');
}
function performLogout() {
  setTimeout(() => { window.location.href = 'waveout.php'; }, 200);
}
</script>

<div class="main-navigation">
  <div class="nav-container">
    <button class="<?php echo ($current_tab === 'water') ? 'nav-item active' : 'nav-item'; ?>" data-tab="water"><span class="nav-icon"><i class="fas fa-water"></i></span> <span class="tab-label"><b>MONITORING</b></span> </button>
    <button class="<?php echo ($current_tab === 'notifications') ? 'nav-item active' : 'nav-item'; ?>" data-tab="notifications"><span class="nav-icon"><i class="fas fa-bell"></i></span> <span class="tab-label"><b>NOTIFICATION</b></span> </button>
    <button class="<?php echo ($current_tab === 'feedlogs') ? 'nav-item active' : 'nav-item'; ?>" data-tab="feedlogs"><span class="nav-icon"><i class="fas fa-fish"></i></span> <span class="tab-label"><b>FEEDER</b></span> </button>
    <a href="controller.php?from=user" id="controllerLink" class="nav-item<?php echo ($current_tab === 'controller') ? ' active' : ''; ?>"><span class="nav-icon"><i class="fas fa-ship"></i></span> <span class="tab-label"><b>CONTROLLER</b></span></a>
    <button class="<?php echo ($current_tab === 'system') ? 'nav-item active' : 'nav-item'; ?>" data-tab="system"><span class="nav-icon"><i class="fas fa-tools"></i></span> <span class="tab-label"><b>SYSTEM TOOLS</b></span></button>
  </div>
</div>

<div class="main-content" id="mainContent">
  <!-- Monitoring Section -->
  <div id="waterSection" class="section" style="<?php echo ($current_tab === 'water') ? '' : 'display:none;'; ?>">
    <div class="water-quality-section">
      <h2>Monitoring <span id="perfHint" class="badge-hint" style="display:none;">live updates paused</span></h2>
      <div class="water-grid">
        <div class="big-card sensor-card" onclick="switchChart('WQI')">
          <h3>Water Quality Index</h3>
          <p id="wqiValue">--</p>
        </div>
        <div class="right-grid">
          <div class="sensor-card" onclick="switchChart('DO')"><h3>Dissolved Oxygen</h3><p id="do">--</p></div>
          <div class="sensor-card" onclick="switchChart('TURB')"><h3>Turbidity</h3><p id="turbidity">--</p></div>
          <div class="sensor-card" onclick="switchChart('AMMO')"><h3>Ammonia</h3><p id="ammonia">--</p></div>
          <div class="sensor-card" onclick="switchChart('PH')"><h3>pH Level</h3><p id="ph_level">--</p></div>
          <div class="sensor-card wide" onclick="switchChart('TEMP')"><h3>Temperature</h3><p id="temperature">--</p></div>
        </div>
      </div>
      <div class="card wide chart-container">
        <h3 id="chartTitle">WQI Live Chart</h3>
        <canvas id="liveChart" height="140"></canvas>
      </div>
      <div style="margin-top:10px;color:#555;font-size:0.95rem;">
        <span id="lastUpdatedLabel">Last updated: <em id="lastUpdatedValue">--</em></span>
      </div>
    </div>
  </div>

  <!-- Notifications Section (match admin exact table layout + styles) -->
  <div id="notificationsSection" class="section notifications-section" style="<?php echo ($current_tab === 'notifications') ? '' : 'display:none;'; ?>">
    <h2>Notifications Logs</h2>
    <div class="notifications-wrap">
      <div class="tool-actions" style="margin-bottom:10px; display:flex; gap:10px; align-items:center;">
        <input id="notifSearch" type="text" placeholder="Search logs..." class="notif-searchbar" oninput="filterNotificationLogs()">
        <select id="notifCategoryFilter" class="notif-category-filter" onchange="filterNotificationLogs()">
          <option value="all">All Events</option>
          <option value="action">Action</option>
          <option value="alert">Alert</option>
          <option value="info">Info</option>
          <option value="access">Access</option>
          <option value="alarm">Alarm</option>
        </select>
        <button class="st-btn st-export st-pill" onclick="openExportPdfModal()">Export PDF</button>
      </div>

      <!-- Export PDF Modal (admin styles) -->
      <div id="exportPdfModal">
        <div class="modal-content">
          <h3>Export Notification Logs</h3>
          <div class="modal-row">
            <label for="modalExportCategory">Event:</label>
            <select id="modalExportCategory">
              <option value="all">All</option>
              <option value="action">Action</option>
              <option value="alert">Alert</option>
              <option value="info">Info</option>
              <option value="access">Access</option>
              <option value="alarm">Alarm</option>
            </select>
          </div>
          <div class="modal-row">
            <label for="modalExportFrom">From:</label>
            <input type="datetime-local" id="modalExportFrom">
          </div>
          <div class="modal-row">
            <label for="modalExportTo">To:</label>
            <input type="datetime-local" id="modalExportTo">
          </div>
          <div class="modal-actions">
            <button id="modalExportCancel">Cancel</button>
            <button id="modalExportConfirm">Export PDF</button>
          </div>
        </div>
      </div>

      <style>
        /* Admin-like search/filter and modal styles (kept scoped) */
        .notif-searchbar { padding: 8px 14px; border-radius: 8px; border: 1.5px solid #d0d7de; min-width: 180px; font-size: 1rem; background: #f8fafc; transition: border-color .15s, box-shadow .15s; outline: none; margin-right: 10px; box-shadow: 0 1px 4px #1e516208; }
        .notif-searchbar:focus { border-color: #1e5162; background: #fff; box-shadow: 0 2px 8px #1e516222; }
        .notif-category-filter { padding: 8px 12px; border-radius: 8px; border: 1.5px solid #d0d7de; font-size: 1rem; background: #f8fafc; transition: border-color .15s, box-shadow .15s; outline: none; margin-right: 10px; box-shadow: 0 1px 4px #1e516208; cursor: pointer; }
        .notif-category-filter:focus { border-color: #1e5162; background: #fff; box-shadow: 0 2px 8px #1e516222; }
        #exportPdfModal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.25); align-items: center; justify-content: center; }
        #exportPdfModal.active { display: flex; }
        #exportPdfModal .modal-content { background: #fff; padding: 2.2rem 2.2rem 1.5rem 2.2rem; border-radius: 16px; min-width: 320px; max-width: 95vw; box-shadow: 0 4px 32px #0002; font-family: 'Segoe UI', Arial, sans-serif; }
        #exportPdfModal h3 { margin-top: 0; margin-bottom: 1.2rem; color: #1e5162; font-size: 1.35rem; font-weight: 700; }
        #exportPdfModal label { font-weight: 600; color: #1e5162; margin-right: 8px; font-size: 1rem; }
        #exportPdfModal select, #exportPdfModal input[type="datetime-local"] { padding: 8px 12px; border-radius: 7px; border: 1px solid #d0d7de; font-size: 1rem; margin-bottom: 0.2em; margin-top: 0.2em; }
        #exportPdfModal .modal-row { display: flex; align-items: center; margin-bottom: 1.1rem; }
        #exportPdfModal .modal-row label { min-width: 60px; }
        #exportPdfModal .modal-actions { text-align: right; margin-top: 0.5em; }
        #modalExportCancel { margin-right: 1em; background: #fff; color: #1e5162; border: 1px solid #1e5162; padding: 0.5em 1.2em; border-radius: 6px; font-weight: 600; cursor: pointer; transition: background .15s; }
        #modalExportCancel:hover { background: #f0f4f8; }
        #modalExportConfirm { background: #1e5162; color: #fff; border: none; padding: 0.5em 1.2em; border-radius: 6px; font-weight: 600; cursor: pointer; box-shadow: 0 2px 8px #1e516222; transition: background .15s; }
        #modalExportConfirm:hover { background: #1976d2; }
      </style>

        <div style="overflow-x:auto;">
        <style>
          /* Admin table styles (scoped) */
          #st-logTable { width: 100%; max-width: 900px; margin: 0 auto; border-collapse: separate; border-spacing: 0; table-layout: fixed; background: #fff; border-radius: 14px; box-shadow: 0 4px 24px rgba(30,81,98,0.13); overflow: hidden; font-family: 'Segoe UI', Arial, sans-serif; }
          #st-logTable th, #st-logTable td { padding: 14px 18px; text-align: left; font-size: 1.08rem; }
          #st-logTable th { background: #1e5162; color: #fff; border-bottom: 3px solid #1976d2; font-weight: 800; letter-spacing: 0.5px; }
          #st-logTable tbody tr { transition: background 0.2s; }
          #st-logTable tbody tr:hover { background: #f0f4f8; }
          .cat-action { background: #f6fff7; }
          .cat-alert { background: #fff7e6; }
          .cat-info { background: #fffde7; }
          .cat-alarm { background: #fff0f0; }
          .cat-access { background: #e3f2fd; }
          .cat-action td.category { color: #219150; font-weight: bold; }
          .cat-alert td.category { color: #e65100; font-weight: bold; }
          .cat-info td.category { color: #fbc02d; font-weight: bold; }
          .cat-alarm td.category { color: #b71c1c; font-weight: bold; }
          .cat-access td.category { color: #1976d2; font-weight: bold; }
          #st-logTable td { border-bottom: 1px solid #e3e8ee; word-break: break-word; color: #222; }
          #st-logTable td.timestamp { font-family: 'Fira Mono', 'Consolas', monospace; white-space: nowrap; width: 170px; color: #1976d2; font-weight: 600; }
          #st-logTable td.message { width: 60%; color: #222; }
          #st-logTable td.category { width: 120px; text-align: center; border-radius: 16px; background: none; font-size: 1.02rem; letter-spacing: 0.2px; padding: 10px 0; }
          #st-logTable td.category::before { content: ''; display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 8px; vertical-align: middle; }
          .cat-action td.category::before { background: #43a047; }
          .cat-alert td.category::before { background: #ff9800; }
          .cat-info td.category::before { background: #fbc02d; }
          .cat-alarm td.category::before { background: #e53935; }
          .cat-access td.category::before { background: #2196f3; }
        </style>

        <div style="max-height:420px; overflow-y:auto; border-radius:14px; box-shadow:0 2px 12px rgba(30,81,98,0.04); background:#fff;">
          <table id="st-logTable" style="margin-bottom:0; width:100%;">
            <thead>
              <tr style="background:#1e5162; color:#fff;">
                <th style="text-align:center; padding:12px 0; font-size:1.1em; letter-spacing:0.5px;">Timestamp</th>
                <th style="text-align:center; padding:12px 0; font-size:1.1em; letter-spacing:0.5px;">Log</th>
                <th style="text-align:center; padding:12px 0; font-size:1.1em; letter-spacing:0.5px;">Event</th>
              </tr>
            </thead>
            <tbody id="st-logBox" style="background:#f8fafc; font-size:1.05em;"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <script>
  function openExportPdfModal() { document.getElementById('exportPdfModal').classList.add('active'); }
  document.getElementById('modalExportCancel').onclick = function() { document.getElementById('exportPdfModal').classList.remove('active'); }
  document.getElementById('modalExportConfirm').onclick = function() {
    let cat = document.getElementById('modalExportCategory').value || 'all';
    if (cat.toLowerCase() === 'access') cat = 'access';
    const from = document.getElementById('modalExportFrom').value;
    const to = document.getElementById('modalExportTo').value;
    document.getElementById('exportPdfModal').classList.remove('active');
    ST_exportLogsPDF(cat, from, to);
  }
  </script>

  <!-- Feeder Section -->
  <div id="feedlogsSection" class="section" style="<?php echo ($current_tab === 'feedlogs') ? '' : 'display:none;'; ?>">
    <iframe src="feeder.php" style="width:100%;height:80vh;border:none;"></iframe>
  </div>

  <!-- Controller Section -->
  <div id="controllerSection" class="section" style="<?php echo ($current_tab === 'controller') ? '' : 'display:none;'; ?>">
    <iframe src="controller.php?from=user" style="width:100%;height:80vh;border:none;"></iframe>
  </div>

  <!-- System Tools Section -->
  <div id="systemSection" class="section" style="<?php echo ($current_tab === 'system') ? '' : 'display:none;'; ?>">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
      <h2>System Tools</h2>
      <div style="display:flex; align-items:center; gap:12px;">
        <label style="font-weight:600;color:#234;">View:</label>
        <select id="sysViewSelect" style="padding:8px 12px;border-radius:8px;border:1px solid #d0d7de;background:#fff;">
          <option value="sensors">Sensors</option>
          <option value="actuators">Actuators</option>
        </select>
      </div>
    </div>

    <div style="margin-top:12px; display:flex; gap:12px; align-items:center;">
      <button id="sysAllOn" class="st-pill" style="padding:8px 14px; cursor:pointer;">ALL ON</button>
    </div>

    <!-- white container to match admin card look -->
    <div class="tools-stage" style="margin-top:16px;">
      <style>
        /* White card used inside System Tools - concise, self-contained */
        .white-card { background: #ffffff; border-radius: 12px; padding: 12px 14px; box-shadow: 0 10px 30px rgba(16,65,75,0.06); }
        .white-card .white-card-inner { gap: 14px; }
        @media (max-width:720px) { .white-card .white-card-inner { flex-direction:column; align-items:flex-start; } }
      </style>
      <!-- Reusable white card: summary / quick actions -->
      <div class="white-card" id="systemSummaryCard" style="margin-bottom:18px;">
        <div class="white-card-inner" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
          <div style="display:flex;align-items:center;gap:12px;">
            <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(180deg,#49d7ff,#1aa6ff);display:flex;align-items:center;justify-content:center;color:#083344;font-size:22px;box-shadow:inset 0 1px 0 rgba(255,255,255,0.35),0 8px 18px rgba(6,118,170,0.08);">
              <i class="fas fa-tachometer-alt"></i>
            </div>
            <div>
              <div style="font-weight:800;color:#123;">System Summary</div>
              <div style="font-size:0.92rem;color:#456;">Quick overview of sensors & actuator states</div>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;">
            <button class="st-btn st-diag st-pill" onclick="runDiagnostics()">Run Diagnostics</button>
            <button class="st-btn st-clear" onclick="ST_confirmClearLogs()">Clear Logs</button>
          </div>
        </div>
      </div>

    <style>
      /* Refined admin-like System Tools styles to match ad_dashboard screenshot */
      .tools-stage { background:#fff; border-radius:14px; padding:22px 26px; box-shadow:0 10px 30px rgba(16,65,75,0.06); }
      /* make cards a little wider and consistent with admin spacing */
      .st-grid { display:grid; grid-template-columns: repeat(auto-fill,minmax(260px,1fr)); gap:24px; align-items:start; }
      .st-card { background: linear-gradient(180deg,#164b51 0%, #1e5162 100%); color:#fff; border-radius:16px; padding:22px 18px; min-height:140px; box-shadow:0 14px 30px rgba(16,65,75,0.12); position:relative; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; transition: transform .18s ease, box-shadow .18s ease; }
      .st-card:hover { transform: translateY(-6px); box-shadow: 0 18px 36px rgba(16,90,110,0.18); }
      .st-icon { width:64px; height:64px; border-radius:14px; background: linear-gradient(180deg,#49d7ff,#1aa6ff); display:flex; align-items:center; justify-content:center; color:#083344; font-size:24px; box-shadow: inset 0 1px 0 rgba(255,255,255,0.25), 0 10px 22px rgba(6,118,170,0.14); margin-bottom:10px; }
      .st-dot { display:inline-block; width:12px; height:12px; border-radius:50%; background:#ff4d4f; vertical-align:middle; position:absolute; right:14px; top:14px; box-shadow:0 0 8px rgba(255,77,79,0.35); }
      .st-card p { margin:6px 0 10px 0; font-weight:700; font-size:1.02rem; }
      .st-switch { position:relative; display:inline-block; width:56px; height:30px; margin-top:6px; }
      .st-switch input { display:none; }
      .st-slider { position:absolute; cursor:pointer; inset:0; background:#aebfcc; border-radius:40px; transition: background .34s ease; }
      .st-slider::before { content:""; position:absolute; height:24px; width:24px; left:3px; bottom:3px; background:#fff; border-radius:50%; box-shadow:0 4px 10px rgba(0,0,0,0.18); transition: transform .22s ease; }
      .st-switch input:checked + .st-slider { background: linear-gradient(135deg, #06d6a0, #1b9aaa); box-shadow: 0 6px 18px rgba(0,200,150,0.18); }
      .st-switch input:checked + .st-slider::before { transform: translateX(26px); background: #e7fbff; box-shadow: 0 6px 18px rgba(0,200,150,0.12); }
      .st-btn { font-size:14px; padding:10px 18px; border:none; border-radius:10px; cursor:pointer; font-weight:700; color:#fff; margin:8px 8px 0 0; box-shadow:0 6px 18px rgba(0,0,0,.12); }
      /* make pill buttons match admin gradient and dark label style */
      .st-pill { background: linear-gradient(180deg,#49d7ff,#1aa6ff); border-radius:999px; padding:8px 16px; color:#083344 !important; font-weight:800; box-shadow: inset 0 1px 0 rgba(255,255,255,0.32), 0 8px 18px rgba(6,118,170,0.08); border:1px solid rgba(255,255,255,0.18); }
      .st-on  { background:#06d6a0; box-shadow:0 0 12px #06d6a0; }
      .st-off { background:#e63946; box-shadow:0 0 6px #e63946; }
      /* ensure responsive stacking on very narrow screens */
      @media (max-width:720px) { .st-grid { grid-template-columns: repeat(auto-fill,minmax(180px,1fr)); gap:12px; } .st-card { padding:16px; } }
    </style>

  <div class="st-grid" id="sysGrid">
    <div class="st-card">
      <div class="st-icon"><i class="fas fa-vial"></i></div>
      <span class="st-dot st-off" id="st-dot-ph"></span>
      <p>pH Level</p>
      <label class="st-switch"><input type="checkbox" id="st-sw-ph" onchange="ST_toggleSensor(this,'ph')"><span class="st-slider"></span></label>
    </div>
    <div class="st-card">
      <div class="st-icon"><i class="fas fa-tint"></i></div>
      <span class="st-dot st-off" id="st-dot-turb"></span>
      <p>Turbidity</p>
      <label class="st-switch"><input type="checkbox" id="st-sw-turb" onchange="ST_toggleSensor(this,'turb')"><span class="st-slider"></span></label>
    </div>
    <div class="st-card">
      <div class="st-icon"><i class="fas fa-thermometer-half"></i></div>
      <span class="st-dot st-off" id="st-dot-temp"></span>
      <p>Temperature</p>
      <label class="st-switch"><input type="checkbox" id="st-sw-temp" onchange="ST_toggleSensor(this,'temp')"><span class="st-slider"></span></label>
    </div>
    <div class="st-card">
      <div class="st-icon"><i class="fas fa-flask"></i></div>
      <span class="st-dot st-off" id="st-dot-ammo"></span>
      <p>Ammonia</p>
      <label class="st-switch"><input type="checkbox" id="st-sw-ammo" onchange="ST_toggleSensor(this,'ammo')"><span class="st-slider"></span></label>
    </div>
    <div class="st-card">
      <div class="st-icon"><i class="fas fa-wind"></i></div>
      <span class="st-dot st-off" id="st-dot-do"></span>
      <p>Dissolved Oxygen</p>
      <label class="st-switch"><input type="checkbox" id="st-sw-do" onchange="ST_toggleSensor(this,'do')"><span class="st-slider"></span></label>
    </div>
    <div class="st-card">
      <div class="st-icon"><i class="fas fa-balance-scale"></i></div>
      <span class="st-dot st-off" id="st-dot-load1"></span>
      <p>Loadcell 1</p>
      <label class="st-switch"><input type="checkbox" id="st-sw-load1" onchange="ST_toggleSensor(this,'load1')"><span class="st-slider"></span></label>
    </div>
    <div class="st-card">
      <div class="st-icon"><i class="fas fa-balance-scale"></i></div>
      <span class="st-dot st-off" id="st-dot-load2"></span>
      <p>Loadcell 2</p>
      <label class="st-switch"><input type="checkbox" id="st-sw-load2" onchange="ST_toggleSensor(this,'load2')"><span class="st-slider"></span></label>
    </div>
    <div class="st-card">
      <div class="st-icon"><i class="fas fa-satellite-dish"></i></div>
      <span class="st-dot st-off" id="st-dot-ultra"></span>
      <p>Feed Level (Ultrasonic)</p>
      <label class="st-switch"><input type="checkbox" id="st-sw-ultra" onchange="ST_toggleSensor(this,'ultra')"><span class="st-slider"></span></label>
    </div>
  </div>

  <script>
    // Sensor keys and toggle handlers (mirror admin functionality)
    const ST_SENSOR_KEYS = ['ph','turb','temp','ammo','do','load1','load2','ultra'];
    function ST_toggleSensor(input, sensor) {
      const dot = document.getElementById('st-dot-' + sensor);
      const key = 'st-sensor-' + sensor;
      const isOn = !!input.checked;
      if (dot) dot.className = 'st-dot ' + (isOn ? 'st-on' : 'st-off');
      localStorage.setItem(key, isOn ? '1' : '0');
      ST_addLog('action', `${sensor.toUpperCase()} sensor turned ${isOn ? 'ON' : 'OFF'}`);
    }

    function ST_loadSensorStates() {
      ST_SENSOR_KEYS.forEach(k => {
        const sw = document.getElementById('st-sw-' + k);
        const dot = document.getElementById('st-dot-' + k);
        const v = localStorage.getItem('st-sensor-' + k) === '1';
        if (sw) sw.checked = v;
        if (dot) dot.className = 'st-dot ' + (v ? 'st-on' : 'st-off');
      });
    }

    function ST_toggleAllSensors(state) {
      ST_SENSOR_KEYS.forEach(k => {
        localStorage.setItem('st-sensor-' + k, state ? '1' : '0');
        const sw = document.getElementById('st-sw-' + k);
        const dot = document.getElementById('st-dot-' + k);
        if (sw) sw.checked = state;
        if (dot) dot.className = 'st-dot ' + (state ? 'st-on' : 'st-off');
      });
      ST_addLog('action', `All sensors turned ${state ? 'ON' : 'OFF'}`);
    }

    function ST_allSensorsCurrentlyOn() {
      return ST_SENSOR_KEYS.every(k => localStorage.getItem('st-sensor-' + k) === '1');
    }

    function ST_toggleAllSensorsToggle() {
      const shouldTurnOn = !ST_allSensorsCurrentlyOn();
      ST_toggleAllSensors(shouldTurnOn);
      const btn = document.getElementById('toggleAllBtn') || document.getElementById('sysAllOn');
      if (btn) btn.textContent = shouldTurnOn ? 'ALL OFF' : 'ALL ON';
    }

    // Initialize sensor states on page load for user dashboard
    window.addEventListener('load', function() {
      try {
        ST_loadSensorStates();
        const btn = document.getElementById('sysAllOn') || document.getElementById('toggleAllBtn');
        if (btn) {
          btn.textContent = ST_allSensorsCurrentlyOn() ? 'ALL OFF' : 'ALL ON';
          btn.addEventListener('click', ST_toggleAllSensorsToggle);
        }
      } catch (e) { /* ignore init errors */ }
    });
  </script>
  </div>

  <script>
      function toggleSysCard(el){
        const card = el.closest('.sys-card');
        if(!card) return;
        const dot = card.querySelector('.sys-dot');
        const sw = el.querySelector('.toggle-switch');
        const isOn = sw.classList.toggle('toggle-on');
        if(isOn) { dot.style.background = '#28c76f'; dot.dataset.state = 'on'; ST_addLog('action', card.querySelector('.sys-title').textContent + ' turned ON'); }
        else { dot.style.background = '#ff4d4f'; dot.dataset.state = 'off'; ST_addLog('action', card.querySelector('.sys-title').textContent + ' turned OFF'); }
      }

      document.getElementById('sysAllOn').addEventListener('click', function(){
        const btn = this;
        btn.classList.add('st-pill');
        const cards = document.querySelectorAll('.sys-card');
        cards.forEach(c=>{
          const sw = c.querySelector('.toggle-switch'); if(!sw.classList.contains('toggle-on')) sw.classList.add('toggle-on');
          const dot = c.querySelector('.sys-dot'); dot.style.background = '#28c76f'; dot.dataset.state = 'on';
        });
        ST_addLog('action','All sensors turned ON');
        Swal.fire('All ON','All sensors have been turned ON (simulated).','success');
      });
    </script>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
<script>
/* --- Notification Functions --- */
function classifyLog(type, message) {
  const msg = (message || '').toLowerCase();
  if (type === 'alert' || /shutdown|powered on|reboot/.test(msg)) return 'alert';
  if (/login|logged in|logout/.test(msg)) return 'login';
  if (/sensor|diagnostics|all sensors/.test(msg)) return 'action';
  if (type === 'warn' || /⚠️|delay|fail|error|alarm/.test(msg)) return 'alarm';
  return 'action';
}

function ST_addLog(type, message){
  const box = document.getElementById("st-logBox");
  if (!box) return;
  const tr = document.createElement("tr");
  const timestamp = new Date().toLocaleString();
  const category = classifyLog(type, message);
  tr.className = `st-log-entry cat-${category}`;
  tr.dataset.category = category;
  const tdTime = document.createElement("td");
  tdTime.textContent = timestamp;
  tdTime.className = "timestamp";
  const tdMsg = document.createElement("td");
  tdMsg.textContent = message;
  tdMsg.className = "message";
  const tdCat = document.createElement("td");
  tdCat.textContent = category.charAt(0).toUpperCase() + category.slice(1);
  tdCat.className = "category";
  tr.appendChild(tdTime);
  tr.appendChild(tdMsg);
  tr.appendChild(tdCat);
  box.prepend(tr);
  ST_saveLogs();

  // send to server (optional) - reuse admin endpoint
  try {
    var xhr = new XMLHttpRequest();
    xhr.open("POST", 'ad_dashboard.php', true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.send("log_to_event_log=1&desc=" + encodeURIComponent(message) + "&status=" + encodeURIComponent(type.toUpperCase()));
  } catch(e) { console.error('Log send error', e); }
}

function ST_saveLogs(){
  const box = document.getElementById("st-logBox");
  if (!box) return;
  const logs = Array.from(box.querySelectorAll(".st-log-entry")).map(tr=>{
    const tds = tr.querySelectorAll('td');
    return {
      timestamp: tds[0]?.textContent || '',
      message: tds[1]?.textContent || '',
      category: tr.dataset.category || 'action'
    };
  });
  localStorage.setItem("systemLogs", JSON.stringify(logs));
}

function ST_loadLogs(){
  const box = document.getElementById("st-logBox");
  if (!box) return;
  box.innerHTML = '';
  const logs = JSON.parse(localStorage.getItem("systemLogs") || "[]");
  logs.forEach(log=>{
    const tr = document.createElement('tr');
    const category = log.category || classifyLog('info', log.message || '');
    tr.className = `st-log-entry cat-${category}`;
    tr.dataset.category = category;
    const tdTime = document.createElement('td'); tdTime.className='timestamp'; tdTime.textContent = log.timestamp || '';
    const tdMsg = document.createElement('td'); tdMsg.className='message'; tdMsg.textContent = log.message || '';
    const tdCat = document.createElement('td'); tdCat.className='category'; tdCat.textContent = category.charAt(0).toUpperCase()+category.slice(1);
    tr.appendChild(tdTime); tr.appendChild(tdMsg); tr.appendChild(tdCat);
    box.appendChild(tr);
  });
}

function ST_clearLogs(){ localStorage.removeItem('systemLogs'); const box=document.getElementById('st-logBox'); if(box) box.innerHTML=''; }

function ST_confirmClearLogs(){
  Swal.fire({ title:'Clear Logs?', text:'This will permanently delete all logs.', icon:'warning', showCancelButton:true, confirmButtonColor:'#ffb703', cancelButtonColor:'#aaa', confirmButtonText:'Yes, clear' }).then((r)=>{ if (r.isConfirmed) { ST_clearLogs(); Swal.fire('Cleared!','All logs have been deleted.','success'); } });
}

function ST_exportLogsPDF(){
  const logs = JSON.parse(localStorage.getItem("systemLogs") || "[]");
  if (logs.length === 0) { Swal.fire("No Logs","There are no logs to export.","info"); return; }
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF();
  doc.setFontSize(16); doc.text("System Logs Report", 14, 20); doc.setFontSize(12); doc.text(`Generated: ${new Date().toLocaleString()}`, 14, 28);
  const tableData = logs.map(l => [l.timestamp, l.message]);
  doc.autoTable({ head: [['Timestamp','Message']], body: tableData, startY:35, styles:{fontSize:10, cellPadding:3}, headStyles:{fillColor:[0,119,182]} });
  doc.save("system_logs.pdf");
}

window.addEventListener('load', ST_loadLogs);

/* --- Navigation Switching --- */
const navButtons = document.querySelectorAll(".nav-item[data-tab]");
navButtons.forEach(btn => {
  btn.addEventListener("click", () => {
    const tab = btn.dataset.tab;
    navSwitchTo(tab);
  });
});
function navSwitchTo(tab) {
  const sections = document.querySelectorAll(".section");
  sections.forEach(s => s.style.display = "none");  
  navButtons.forEach(b => b.classList.remove("active"));
  const btn = Array.from(navButtons).find(b => b.dataset.tab === tab);
  if (btn) btn.classList.add("active");
  const sec = document.getElementById(tab + "Section");
  if (sec) sec.style.display = "block";
  const url = new URL(window.location);
  url.searchParams.set('tab', tab);
  window.history.replaceState({}, '', url);
}
navSwitchTo("<?php echo $current_tab; ?>");

// System Tools JS handlers
function runDiagnostics(){
  Swal.fire({title:'Run Diagnostics', text:'Run system diagnostics now?', icon:'question', showCancelButton:true, confirmButtonText:'Run', confirmButtonColor:'#00bcd4'}).then(r=>{ if(r.isConfirmed){ ST_addLog('action','User ran diagnostics'); Swal.fire('Diagnostics','Diagnostics completed (simulated).','success'); } });
}

function togglePower(state){
  const verb = state === 'ON' ? 'powered on' : 'powered off';
  Swal.fire({title:'Confirm', text:`Are you sure you want to ${verb} the system peripherals?`, icon:'warning', showCancelButton:true, confirmButtonText:'Yes', confirmButtonColor:'#1e5162'}).then(r=>{ if(r.isConfirmed){ ST_addLog('action',`User ${verb} peripherals`); Swal.fire('Done',`Peripherals ${verb}. (simulated)`,'success'); } });
}

function rebootSystem(){
  Swal.fire({title:'Reboot', text:'Perform soft reboot now?', icon:'warning', showCancelButton:true, confirmButtonText:'Reboot', confirmButtonColor:'#ffb703'}).then(r=>{ if(r.isConfirmed){ ST_addLog('action','User initiated system reboot'); Swal.fire('Rebooting','System reboot simulated.','success'); } });
}
</script>
</body>
</html>
