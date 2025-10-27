<?php
// Use a separate session name for user dashboard to avoid replacing admin session data
session_name('WAVE_USER');
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
<link rel="stylesheet" href="user_dashboard.css">
<!-- Admin styles: rely primarily on ad_dashboard.css; keep only minimal page-specific overrides to enforce layout and glassy containers -->
<style>
  /* Copy admin header and left-navigation glassmorphism styles for visual parity */
  .header {
    max-width: 100vw;
    max-height: 100vh;
    overflow: hidden !important;
    box-sizing: border-box;
    background: rgba(255,255,255,0.12);
    border-radius: 32px;
    box-shadow: 0 8px 32px rgba(30,81,98,0.18);
    backdrop-filter: blur(24px) saturate(180%) brightness(1.12);
    -webkit-backdrop-filter: blur(24px) saturate(180%) brightness(1.12);
    border: 1.5px solid rgba(255,255,255,0.18);
  color: #000;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1000;
    height: 100px;
    display: flex;
    align-items: center;
    justify-content: center; /* center header content like admin */
  }
  /* ensure header-left is centered inside the header bar */
  .header-left { display:flex; align-items:center; gap:20px; margin: 0 auto; }
  .header .system-title { color: #000 !important; font-weight: 800; font-size: 32px; text-transform: uppercase; letter-spacing: 2px; margin-left: 8px; }
  .header .admin-title img { filter: none !important; }
  .main-navigation {
    max-width: 100vw;
    max-height: 100vh;
    overflow: hidden !important;
    box-sizing: border-box;
    background: rgba(255,255,255,0.12);
    border-radius: 32px;
    box-shadow: 0 8px 32px rgba(30,81,98,0.18);
    backdrop-filter: blur(24px) saturate(180%) brightness(1.12);
    -webkit-backdrop-filter: blur(24px) saturate(180%) brightness(1.12);
    border: 1.5px solid rgba(255,255,255,0.18);
    color: #fff;
    position: fixed;
  top: 90px;
    left: 0;
    bottom: 18px; /* leave a small inset from bottom to match admin rounded container */
    width: 240px; /* match main content margin and external CSS */
    z-index: 999;
  }

  /* Page background image to match admin */
  body {
    background: url('wavebg.jpeg') no-repeat center center fixed;
    background-size: cover;
  }

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
  margin-top: 90px; /* align with header height */
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
  .right-grid { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: repeat(3, 1fr); gap: 10px; }
  /* Make user sensor cards match admin visuals: tighter, smaller height and same blue gradient */
  .sensor-card { background: linear-gradient(145deg, #a1d4f5, #6ec1e4); border-radius: 12px; box-shadow: 0 6px 12px rgba(0,0,0,0.12); color: #012b45; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 110px; font-size: 0.92rem; padding: 12px 10px; }
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
  /* position dropdown menu to top-right of header like admin */
  .header-right { position: absolute; right: 20px; top: 18px; }
  .admin-dropdown { color: #ffffffff !important; }
  .dropdown-content { right: 0; top: 100%; }
  .badge-hint { display:inline-block; margin-left:8px; padding:2px 8px; font-size:0.75rem; color:#0f5132; background:#d1e7dd; border-radius:9999px; }
</style>

<?php include_once __DIR__ . '/socket_secret.php'; ?>
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
  try {
    if (window.socket && window.socket.connected) {
      window.socket.emit('log.event', { type: 'info', message: 'logged out', ts: Date.now(), origin: 'local' });
      setTimeout(() => { window.location.href = 'waveout.php'; }, 250);
      return;
    }
  } catch(e) {}
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
          <p id="wqiValue" name="wqi_value">--</p>
        </div>
        <div class="right-grid">
          <div class="sensor-card" onclick="switchChart('DO')"><h3>Dissolved Oxygen (mg/L)</h3><p id="do">--</p></div>
          <div class="sensor-card" onclick="switchChart('TURB')"><h3>Turbidity (NTU)</h3><p id="turbidity">--</p></div>
          <div class="sensor-card" onclick="switchChart('AMMO')"><h3>Ammonia (mg/L)</h3><p id="ammonia">--</p></div>
          <div class="sensor-card" onclick="switchChart('PH')"><h3>pH Level</h3><p id="ph_level">--</p></div>
          <div class="sensor-card wide" onclick="switchChart('TEMP')"><h3>Water Temperature (°C)</h3><p id="temperature">--</p></div>
        </div>
      </div>
      <!-- Live chart removed on user dashboard to maximize sensor cards -->
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
      </div>

      <style>
        /* Admin-like search/filter styles (kept scoped) */
        .notif-searchbar { padding: 8px 14px; border-radius: 8px; border: 1.5px solid #d0d7de; min-width: 180px; font-size: 1rem; background: #f8fafc; transition: border-color .15s, box-shadow .15s; outline: none; margin-right: 10px; box-shadow: 0 1px 4px #1e516208; }
        .notif-searchbar:focus { border-color: #1e5162; background: #fff; box-shadow: 0 2px 8px #1e516222; }
        .notif-category-filter { padding: 8px 12px; border-radius: 8px; border: 1.5px solid #d0d7de; font-size: 1rem; background: #f8fafc; transition: border-color .15s, box-shadow .15s; outline: none; margin-right: 10px; box-shadow: 0 1px 4px #1e516208; cursor: pointer; }
        .notif-category-filter:focus { border-color: #1e5162; background: #fff; box-shadow: 0 2px 8px #1e516222; }
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
  // Live filter for notification logs (user dashboard)
  function filterNotificationLogs() {
    const search = document.getElementById('notifSearch').value.toLowerCase();
    let cat = document.getElementById('notifCategoryFilter').value;
    const table = document.getElementById('st-logTable');
    if (!table) return;
    const rows = table.getElementsByTagName('tr');
    for (let i = 1; i < rows.length; i++) { // skip header
      const cells = rows[i].getElementsByTagName('td');
      if (cells.length < 3) continue;
      const msg = cells[1].textContent.toLowerCase();
      let event = cells[2].textContent.toLowerCase();
      // Treat 'login' as 'access' for filtering
      if (event === 'login') event = 'access';
      const catMatch = (cat === 'all') || (event === cat);
      const searchMatch = !search || msg.includes(search) || event.includes(search) || cells[0].textContent.toLowerCase().includes(search);
      rows[i].style.display = (catMatch && searchMatch) ? '' : 'none';
    }
  }
  </script>

  <!-- Feeder Section -->
  <div id="feedlogsSection" class="section" style="<?php echo ($current_tab === 'feedlogs') ? '' : 'display:none;'; ?>">
    <iframe src="feeder.php?from=user" style="width:100%;height:80vh;border:none;"></iframe>
  </div>

  <!-- Controller Section -->
  <div id="controllerSection" class="section" style="<?php echo ($current_tab === 'controller') ? '' : 'display:none;'; ?>">
    <iframe src="controller.php?from=user" style="width:100%;height:80vh;border:none;"></iframe>
  </div>

  <!-- TOOLS  (SENSORS & SYSTEM ACTIONS AS COMBO BOX) - copied from admin -->
  <div id="systemSection" class="section" style="<?php echo ($current_tab === 'system') ? 'position:relative;' : 'display:none; position:relative;'; ?>">
    <h2></h2>

    <div class="tools-toolbar" style="padding:8px 0 0 8px;">
      <!-- Unified view: sensors and actions are shown together; dropdown removed on user page to match admin -->
    </div>

  <div id="toolsStageSensors" class="tools-stage" style="display:block; padding-top:8px; position:relative;">
      <div class="tool-actions" style="margin-bottom:15px;">
        <!-- ALL pill (matches admin visual) -->
        <button class="st-pill" id="toggleAllBtn" onclick="ST_toggleAllSensorsToggle()">ALL ON</button>
      </div>

      <div class="top-right-actions" style="position:fixed; top:140px; right:18px; display:flex; gap:10px; align-items:center; z-index:9999;">
        <!-- Default to OFF visually; JS init will override from localStorage if needed -->
        <p id="st-vesselStatus" class="st-vessel-off" style="margin:0; padding:8px 12px; border-radius:12px; box-shadow:0 8px 20px rgba(0,0,0,0.08);">Vessel Status: OFF</p>
        <button id="st-powerBtn" class="st-btn st-powerOn st-pill" onclick="ST_togglePower()">Power On Vessel</button>
      </div>

      <style>
        /* Responsive placement for vessel controls and spacing to avoid overlap */
        @media (max-width: 900px) {
          #systemSection .top-right-actions { position: static !important; margin: 0 0 14px 0; justify-content: flex-end; }
          #systemSection .top-right-actions p, #systemSection .top-right-actions button { font-size: 0.95rem; }
          #toolsStageSensors { padding-top: 56px; }
          #systemSection .st-grid { margin-top: 8px !important; }
        }

        @media (min-width: 901px) {
          /* Fixed controls sit below the header at the same offset used by admin */
          #systemSection .top-right-actions { position: fixed !important; top: 140px !important; right: 18px !important; z-index:9999; pointer-events: auto !important; }
          /* Keep the toolsStage compact but give the grid an explicit offset so lower rows don't clash with the floating pill */
          #toolsStageSensors { padding-top: 6px; }
          #systemSection .st-grid { margin-top: 40px !important; }
        }
      </style>

  <!-- Clean: reuse admin classes, small scoped grid/dot rules + UI tweaks -->
  <style>
    /* Exact admin System Tools styles (scoped to #systemSection) */
    #systemSection .st-grid { display:grid !important; grid-template-columns: repeat(auto-fill,minmax(220px,1fr)) !important; gap:20px !important; }

  /* card background, shadow and hover from admin */
  #systemSection .st-card { background: linear-gradient(145deg, #2b6777, #1e5162) !important; border-radius: 14px !important; padding: 12px !important; box-shadow: 0 8px 18px rgba(0,0,0,0.2) !important; text-align:center !important; color:#f1faff !important; transition: transform .2s ease, box-shadow .3s ease !important; }
    #systemSection .st-card:hover { transform: translateY(-6px) !important; box-shadow: 0 12px 25px rgba(0,150,200,0.5) !important; }

  /* icon bubble exact admin style */
  #systemSection .st-icon { display:inline-flex !important; align-items:center !important; justify-content:center !important; width:52px !important; height:52px !important; border-radius:12px !important; background: linear-gradient(180deg,#49d7ff,#1aa6ff) !important; color:#fff !important; font-size:20px !important; box-shadow: inset 0 1px 0 rgba(255,255,255,.35), 0 4px 10px rgba(0,0,0,.25) !important; margin-bottom: 10px !important; position:relative !important; }

  /* dot and glow (hidden on user dashboard because switches already indicate status) */
  #systemSection .st-dot { display:none !important; width:12px !important; height:12px !important; border-radius:50% !important; margin-right:6px !important; background:#bbb !important; vertical-align:middle !important; }
    #systemSection .st-on  { background:#06d6a0 !important; box-shadow:0 0 12px #06d6a0 !important; }
    #systemSection .st-off { background:#e63946 !important; box-shadow:0 0 6px #e63946 !important; }

    /* iOS-style Toggle Switch (copy admin dimensions + checked glow) */
    #systemSection .st-switch { position:relative !important; display:inline-block !important; width:64px !important; height:34px !important; }
    #systemSection .st-switch input { display:none !important; }
    #systemSection .st-slider { position:absolute !important; cursor:pointer !important; inset:0 !important; background:#b0c4de !important; border-radius:34px !important; transition: background .4s ease !important; }
    #systemSection .st-slider::before { content:"" !important; position:absolute !important; height:26px !important; width:26px !important; left:4px !important; bottom:3px !important; background:#fff !important; border-radius:50% !important; transition: transform .4s ease, background .4s ease !important; box-shadow: 0 2px 6px rgba(0,0,0,0.3) !important; }
    #systemSection .st-switch input:checked + .st-slider { background: linear-gradient(135deg, #06d6a0, #1b9aaa) !important; box-shadow: 0 0 12px rgba(0,200,150,0.7) !important; }
    #systemSection .st-switch input:checked + .st-slider::before { transform: translateX(30px) !important; background: #e0f7fa !important; box-shadow: 0 0 10px rgba(0,200,150,0.9) !important; }

    /* ensure the icon contains the dot and positions correctly */
    #systemSection .st-card { position: relative !important; }
    #systemSection .st-card .st-icon { position: relative !important; }
  #systemSection .st-card .st-icon .st-dot { position: absolute !important; top: 6px !important; right: 6px !important; transform: none !important; z-index:6 !important; width:10px !important; height:10px !important; }

  /* ALL pill (top-left) — make it match admin .st-pill visuals */
  #systemSection #toggleAllBtn { padding:6px 12px !important; font-size:0.88rem !important; border-radius:20px !important; height:34px !important; display:inline-flex !important; align-items:center !important; justify-content:center !important; background: linear-gradient(180deg,#49d7ff,#1aa6ff) !important; color:#083344 !important; border:1px solid rgba(255,255,255,.35) !important; box-shadow: inset 0 1px 0 rgba(255,255,255,.35), 0 6px 14px rgba(0,0,0,.06) !important; }

  /* Vessel pill styles: make ON/OFF match admin's visual emphasis */
  #systemSection #st-vesselStatus { margin:0; padding:8px 12px; border-radius:12px; font-weight:700; font-size:0.9rem; }
  #systemSection #st-vesselStatus.st-vessel-on { background:#06d6a0 !important; color:#fff !important; box-shadow:0 0 12px #06d6a0 !important; }
  #systemSection #st-vesselStatus.st-vessel-off { background: linear-gradient(135deg,#e63946,#d00000) !important; color:#fff !important; box-shadow:0 0 8px #e63946 !important; }
  
  /* Sensor label text should be white to match admin card contrast; use normal weight on user page */
  #systemSection .st-card p { color: #ffffff !important; font-weight:400 !important; }

  /* Shutdown button style (when class st-powerOff is present) */

  /* Button base + power variants copied from admin (scoped to #systemSection to avoid bleed) */
  #systemSection .st-btn { font-size:15px !important; padding:10px 18px !important; border:none !important; border-radius:10px !important; cursor:pointer !important; font-weight:700 !important; color:#fff !important; margin:8px 8px 0 0 !important; box-shadow:0 4px 12px rgba(0,0,0,.3) !important; }
  #systemSection .st-pill {
    background: linear-gradient(180deg,#49d7ff,#1aa6ff) !important;
    border-radius: 9999px !important;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.35), 0 6px 14px rgba(0,0,0,.25) !important;
    color:#083344 !important;
    border:1px solid rgba(255,255,255,.35) !important;
  }

  /* Power OFF (shutdown) visual */
  #systemSection .st-btn.st-powerOff, #systemSection #st-powerBtn.st-powerOff { background: linear-gradient(135deg, #e63946, #d00000) !important; color: #fff !important; box-shadow: 0 6px 18px rgba(208,0,0,0.18) !important; border: 1px solid rgba(255,255,255,0.12) !important; }
  #systemSection .st-btn.st-powerOff:hover, #systemSection #st-powerBtn.st-powerOff:hover { background: linear-gradient(135deg, #d00000, #9d0208) !important; }

  /* Power ON visual */
  #systemSection .st-btn.st-powerOn, #systemSection #st-powerBtn.st-powerOn { background: linear-gradient(135deg, #06d6a0, #1b9aaa) !important; color: #fff !important; }
  #systemSection .st-btn.st-powerOn:hover, #systemSection #st-powerBtn.st-powerOn:hover { background: linear-gradient(135deg, #04ad84, #15807c) !important; }

  /* Sensor name typography: uppercase, letter-spacing and matching admin size */
  #systemSection .st-card p { text-transform: uppercase !important; letter-spacing: .5px !important; font-size: 0.95rem !important; }
  </style>

      <div class="st-grid">
        <div class="st-card sensor-card">
          <div class="st-icon sensor-icon"><i class="fas fa-vial"></i><span class="st-dot st-off" id="st-dot-ph"></span></div>
          <p>PH LEVEL</p>
          <label class="st-switch"><input type="checkbox" id="st-sw-ph" onchange="ST_toggleSensor(this,'ph')"><span class="st-slider"></span></label>
        </div>
        <div class="st-card sensor-card">
          <div class="st-icon sensor-icon"><i class="fas fa-tint"></i><span class="st-dot st-off" id="st-dot-turb"></span></div>
          <p>TURBIDITY</p>
          <label class="st-switch"><input type="checkbox" id="st-sw-turb" onchange="ST_toggleSensor(this,'turb')"><span class="st-slider"></span></label>
        </div>
        <div class="st-card sensor-card">
          <div class="st-icon sensor-icon"><i class="fas fa-thermometer-half"></i><span class="st-dot st-off" id="st-dot-temp"></span></div>
          <p>TEMPERATURE</p>
          <label class="st-switch"><input type="checkbox" id="st-sw-temp" onchange="ST_toggleSensor(this,'temp')"><span class="st-slider"></span></label>
        </div>
        <div class="st-card sensor-card">
          <div class="st-icon sensor-icon"><i class="fas fa-flask"></i><span class="st-dot st-off" id="st-dot-ammo"></span></div>
          <p>AMMONIA</p>
          <label class="st-switch"><input type="checkbox" id="st-sw-ammo" onchange="ST_toggleSensor(this,'ammo')"><span class="st-slider"></span></label>
        </div>
        <div class="st-card sensor-card">
          <div class="st-icon sensor-icon"><i class="fas fa-wind"></i><span class="st-dot st-off" id="st-dot-do"></span></div>
          <p>DISSOLVED OXYGEN</p>
          <label class="st-switch"><input type="checkbox" id="st-sw-do" onchange="ST_toggleSensor(this,'do')"><span class="st-slider"></span></label>
        </div>
        <div class="st-card sensor-card">
          <div class="st-icon sensor-icon"><i class="fas fa-balance-scale"></i><span class="st-dot st-off" id="st-dot-load1"></span></div>
          <p>LOADCELL 1</p>
          <label class="st-switch"><input type="checkbox" id="st-sw-load1" onchange="ST_toggleSensor(this,'load1')"><span class="st-slider"></span></label>
        </div>
        <div class="st-card sensor-card">
          <div class="st-icon sensor-icon"><i class="fas fa-balance-scale"></i><span class="st-dot st-off" id="st-dot-load2"></span></div>
          <p>LOADCELL 2</p>
          <label class="st-switch"><input type="checkbox" id="st-sw-load2" onchange="ST_toggleSensor(this,'load2')"><span class="st-slider"></span></label>
        </div>
        <div class="st-card sensor-card">
          <div class="st-icon sensor-icon"><i class="fas fa-satellite-dish"></i><span class="st-dot st-off" id="st-dot-ultra"></span></div>
          <p>FEED LEVEL</p>
          <p>(ULTRA SONIC)</p>
          <label class="st-switch"><input type="checkbox" id="st-sw-ultra" onchange="ST_toggleSensor(this,'ultra')"><span class="st-slider"></span></label>
        </div>
      </div>
    </div>
    <hr style="margin:20px 0; border:1px solid #e5e7eb;">
  </div>

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

function ST_addLog(type, message, opts){
  opts = opts || {};
  const box = document.getElementById("st-logBox");
  if (!box) return;
  try {
    // Collapse duplicate role prefixes (if any) and ensure a single [USER] prefix
    let msg = String(message || '');
    let seen = false;
    msg = msg.replace(/\[(USER|ADMIN)\]\s*/gi, function(full, role){ if (!seen) { seen = true; return '[' + role.toUpperCase() + '] '; } return ''; });
    if (!/^\[(USER|ADMIN)\]/i.test(msg)) msg = '[USER] ' + msg;
    message = msg.trim();
  } catch (e) {}
  const tr = document.createElement("tr");
  const timestamp = (opts.timestamp) ? opts.timestamp : new Date().toLocaleString();
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

  // send to server (optional) - reuse admin endpoint unless caller asked us not to
  if (!opts.noDb) {
    try {
      var xhr = new XMLHttpRequest();
      xhr.open("POST", 'ad_dashboard.php', true);
      xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
      // Send admin-compatible payload while preserving USER origin. Include role and timestamp for parity.
      var payload = [];
      payload.push('log_to_event_log=1');
      payload.push('user=' + encodeURIComponent("<?php echo addslashes($_SESSION['username']); ?>"));
      payload.push('role=' + encodeURIComponent('USER'));
      payload.push('ts=' + encodeURIComponent(Date.now()));
      payload.push('desc=' + encodeURIComponent(message));
      payload.push('status=' + encodeURIComponent(type.toUpperCase()));
      xhr.send(payload.join('&'));
    } catch(e) { console.error('Log send error', e); }
  }

  // Do not auto-emit socket 'log.event' here. The realtime server builds and broadcasts
  // canonical log.events for UI; emitting from clients caused duplication and inconsistent
  // role/user tags (double 'by'). Only specialized flows should emit explicit socket events
  // like 'sensor.change' / 'vessel.change' / 'sensors.bulk'.
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
  let logs = JSON.parse(localStorage.getItem("systemLogs") || "[]");
  // Sort logs newest first (descending timestamp) so latest entries show on top
  logs.sort((a, b) => {
    const ta = a.timestamp ? new Date(a.timestamp).getTime() : 0;
    const tb = b.timestamp ? new Date(b.timestamp).getTime() : 0;
    return tb - ta;
  });
  if (logs.length === 0) {
    const tr = document.createElement("tr");
    const td = document.createElement("td");
    td.colSpan = 3;
    td.style.textAlign = "center";
    td.style.background = "#e0e0e0";
    td.style.color = "#555";
    td.style.fontFamily = "monospace";
    td.style.fontSize = "1.2em";
    td.style.padding = "32px 0";
    td.textContent = "No notifications logs yet.";
    tr.appendChild(td);
    box.appendChild(tr);
    return;
  }
  logs.forEach(log=>{
    const tr = document.createElement('tr');
    let category = log.category || classifyLog('info', log.message || '');
    // Normalize 'login' to 'access' for consistent display and filtering
    if (category === 'login') category = 'access';
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

function ST_confirmClearLogs(){ Swal.fire({ title:'Clear Logs?', text:'This will permanently delete all logs.', icon:'warning', showCancelButton:true, confirmButtonColor:'#ffb703', cancelButtonColor:'#aaa', confirmButtonText:'Yes, clear' }).then((r)=>{ if (r.isConfirmed) { ST_clearLogs(); Swal.fire('Cleared!','All logs have been deleted.','success'); } }); }

window.addEventListener('load', ST_loadLogs);

    // If we landed here after login with ?log=login create a login event (this will also broadcast to admin via realtime)
    window.addEventListener('load', function(){
  try {
    // helper: wait for socket connect then emit, otherwise fallback
    function emitWhenSocketReady(evName, payload, timeoutMs, fallback) {
      timeoutMs = timeoutMs || 2000;
      const start = Date.now();
      (function tryEmit(){
        try { if (window.socket && window.socket.connected) { window.socket.emit(evName, payload); return; } } catch(e){}
        if (Date.now() - start < timeoutMs) { setTimeout(tryEmit, 100); return; }
        try { if (typeof fallback === 'function') fallback(); } catch(e){}
      })();
    }
    function createSyncStatus(){ if (document.getElementById('st-syncStatus')) return; const el=document.createElement('div'); el.id='st-syncStatus'; el.style.position='fixed'; el.style.top='86px'; el.style.right='18px'; el.style.zIndex='9999'; el.style.background='rgba(255,255,255,0.95)'; el.style.border='1px solid #ddd'; el.style.padding='6px 10px'; el.style.borderRadius='18px'; el.style.boxShadow='0 2px 8px rgba(0,0,0,0.06)'; el.style.fontSize='13px'; el.style.color='#222'; el.innerHTML='<span id="st-syncDot" style="display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:8px;background:#ccc;vertical-align:middle"></span><span id="st-syncText">Sync: offline</span><span id="st-syncTime" style="margin-left:8px;color:#666;font-size:11px"></span>'; document.body.appendChild(el); }
    function updateSyncStatus(state, info){ const dot=document.getElementById('st-syncDot'); const text=document.getElementById('st-syncText'); const time=document.getElementById('st-syncTime'); if(!dot||!text) return; if(state==='connected'){ dot.style.background='#2ecc71'; text.textContent='Sync: connected'; } else if(state==='disconnected'){ dot.style.background='#e74c3c'; text.textContent='Sync: disconnected'; } else if(state==='active'){ dot.style.background='#f39c12'; text.textContent='Sync: active'; } else { dot.style.background='#95a5a6'; text.textContent='Sync: offline'; } if(time) time.textContent = info ? ('last: '+info) : ''; }
    const params = new URLSearchParams(window.location.search);
    if (params.get('log') === 'login') {
      createSyncStatus();
      emitWhenSocketReady('log.event', { type: 'info', message: 'logged in', ts: Date.now(), origin: 'local' }, 2000, function(){ ST_addLog('info', `[USER] <?php echo addslashes($_SESSION['username']); ?> logged in`); });
      params.delete('log');
      const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
      window.history.replaceState({}, '', newUrl);
    }
  } catch(e) {}
});

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
    // Additional System Tools JS helpers (copied from admin)
  function ST_setVesselState(state, emit=true) {
      const statusEl = document.getElementById('st-vesselStatus');
      const powerBtn = document.getElementById('st-powerBtn');
      const sensorSwitches = document.querySelectorAll('.st-switch input[type="checkbox"]');
      const toggleAllBtn = document.getElementById('toggleAllBtn');
      if (state === 'ON') {
        if (statusEl){ statusEl.textContent = 'Vessel Status: ON'; statusEl.className = 'st-vessel-on'; }
        if (powerBtn){ powerBtn.textContent = 'Shutdown Vessel'; powerBtn.className = 'st-btn st-powerOff st-pill'; }
        sensorSwitches.forEach(sw => { sw.disabled = false; if (sw.parentElement) sw.parentElement.style.opacity = '1'; });
        if (toggleAllBtn) { toggleAllBtn.disabled = false; toggleAllBtn.style.opacity = '1'; toggleAllBtn.style.cursor = 'pointer'; }
      } else {
        if (statusEl){ statusEl.textContent = 'Vessel Status: OFF'; statusEl.className = 'st-vessel-off'; }
        if (powerBtn){ powerBtn.textContent = 'Power On Vessel'; powerBtn.className = 'st-btn st-powerOn st-pill'; }
        sensorSwitches.forEach(sw => { sw.disabled = true; if (sw.parentElement) sw.parentElement.style.opacity = '.6'; });
        if (toggleAllBtn) { toggleAllBtn.disabled = true; toggleAllBtn.style.opacity = '.6'; toggleAllBtn.style.cursor = 'not-allowed'; }
      }
      localStorage.setItem('vesselState', state);
      try {
        // Only emit vessel.change when this call is the local initiator (emit=true)
        if (emit && window.socket && window.socket.connected) {
          window.socket.emit('vessel.change', { state: state, user: '<?php echo addslashes($_SESSION['username']); ?>', role: 'USER', ts: Date.now(), origin: 'local' });
        }
      } catch(e) {}
    }

    function ST_togglePower() {
      const state = localStorage.getItem('vesselState') || 'ON';
      if (state === 'ON') {
        Swal.fire({
          title: 'Shutdown Vessel?',
          text: "This will power off the system.",
          icon:'error', showCancelButton:true,
          confirmButtonColor:'#9d0208', cancelButtonColor:'#aaa',
          confirmButtonText:'Yes, shutdown'
        }).then((res)=>{
          if (res.isConfirmed) {
            ST_setVesselState('OFF');
            // Server will emit the canonical shutdown log.event
            const rs = document.getElementById('st-rebootStatus');
            if (rs) rs.textContent = 'Shutting down vessel...';
            setTimeout(()=>{ const rs2 = document.getElementById('st-rebootStatus'); if (rs2) rs2.textContent = 'Vessel is now powered off.'; },3000);
          }
        });
      } else {
        Swal.fire({
          title:'Power On Vessel?',
          text:'This will start the system.',
          icon:'success', showCancelButton:true,
          confirmButtonColor:'#06d6a0', cancelButtonColor:'#aaa',
          confirmButtonText:'Yes, power on'
        }).then((res)=>{
          if (res.isConfirmed) {
            ST_setVesselState('ON');
            // Server will emit the canonical power-on log.event
            const rs = document.getElementById('st-rebootStatus');
            if (rs) rs.textContent = 'Vessel powering on...';
            setTimeout(()=>{ const rs2 = document.getElementById('st-rebootStatus'); if (rs2) rs2.textContent = 'Vessel is now running.'; },3000);
          }
        });
      }
    }

    // Keep the unified all-sensors functions consistent with admin
    function ST_toggleAllSensors(state) {
      ST_SENSOR_KEYS.forEach(k => {
        localStorage.setItem('st-sensor-' + k, state ? '1' : '0');
        const sw = document.getElementById('st-sw-' + k);
        const dot = document.getElementById('st-dot-' + k);
        if (sw) sw.checked = state;
        if (dot) dot.className = 'st-dot ' + (state ? 'st-on' : 'st-off');
      });
  // Do not locally log bulk sensor toggles; server will emit a canonical `log.event`.
      try {
        if (window.socket && window.socket.connected) {
          window.socket.emit('sensors.bulk', { keys: ST_SENSOR_KEYS, value: state, user: '<?php echo addslashes($_SESSION['username']); ?>', role: 'USER', ts: Date.now(), origin: 'local' });
        }
      } catch(e) {}
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

    /* Initialization: sensor keys, restore switches, vessel state, and ALL pill label */
    (function(){
        try {
        // Define sensor keys consistent with admin
        if (typeof ST_SENSOR_KEYS === 'undefined' || !Array.isArray(ST_SENSOR_KEYS)) {
          window.ST_SENSOR_KEYS = ['ph','turb','temp','ammo','do','load1','load2','ultra'];
        }

        // Restore individual sensor switches from localStorage
        ST_SENSOR_KEYS.forEach(k => {
          const val = localStorage.getItem('st-sensor-' + k);
          const sw = document.getElementById('st-sw-' + k);
          const dot = document.getElementById('st-dot-' + k);
          if (sw) sw.checked = (val === '1');
          if (dot) dot.className = 'st-dot ' + ((val === '1') ? 'st-on' : 'st-off');
        });

        // Add user-side sensor toggle handler (match admin behavior but mark as [USER])
        window.ST_toggleSensor = function(input, sensor) {
          const dot = document.getElementById('st-dot-' + sensor);
          const isOn = !!input.checked;
          if (dot) dot.className = 'st-dot ' + (isOn ? 'st-on' : 'st-off');
      try { localStorage.setItem('st-sensor-' + sensor, isOn ? '1' : '0'); } catch(e) {}
      // Do not create a local log here. The server will emit a canonical `log.event`
      // with the role and "by <username>" suffix for consistent display.
        };
        // emit with origin will be performed by the outer wrapper to avoid double-emitting

        // Initialize vessel state (default OFF for safety). ST_setVesselState also stores in localStorage.
        var vs = localStorage.getItem('vesselState');
        if (!vs) vs = 'OFF';
        ST_setVesselState(vs);

        // Set ALL pill label based on current sensors
        var allBtn = document.getElementById('toggleAllBtn');
        if (allBtn) allBtn.textContent = ST_allSensorsCurrentlyOn() ? 'ALL OFF' : 'ALL ON';
      } catch(e) { console.warn('System Tools init error', e); }
    })();

    // --- Realtime Socket.IO integration (connect to LAN node server) ---
    // Replace with your LAN IP: 192.168.0.2
    try {
      const SOCKET_HOST = 'http://192.168.0.2:3000';
      const script = document.createElement('script');
      script.src = 'https://cdn.socket.io/4.7.2/socket.io.min.js';
      script.onload = function() {
        try {
          // Build token from server-signed HMAC and include the server-side timestamp used
          const socketAuth = (function(){
            // inline pre-signed hmac (server computed) using a single timestamp
            <?php $__socket_ts = time(); $__socket_hmac = hash_hmac('sha256', $_SESSION['username'] . "|USER|" . $__socket_ts, WAVE_SOCKET_SECRET); ?>
            const ts = <?php echo $__socket_ts; ?>;
            return '<?php echo $__socket_hmac; ?>::' + '<?php echo addslashes($_SESSION['username']); ?>' + '::USER::' + ts;
          })();
          window.socket = io(SOCKET_HOST, { transports: ['websocket'], auth: { token: socketAuth } });
          window.socket.on('connect', () => { console.log('socket connected', window.socket.id); try { updateSyncStatus('connected', '<?php echo addslashes($_SESSION['username']); ?>'); } catch(e){} });
          window.socket.on('disconnect', () => { try { updateSyncStatus('disconnected'); } catch(e){} });
          // Announce presence and current local state on connect so other clients sync immediately
          window.socket.on('connect', () => {
            try {
              const me = '<?php echo addslashes($_SESSION['username']); ?>';
              // emit presence (short ping)
              window.socket.emit('presence', { user: me, ts: Date.now(), origin: 'user' });
              // build sensors snapshot
              const sensors = {};
              (window.ST_SENSOR_KEYS || ['ph','turb','temp','ammo','do','load1','load2','ultra']).forEach(k => {
                try { sensors[k] = (localStorage.getItem('st-sensor-' + k) === '1'); } catch(e) { sensors[k] = false; }
              });
              const payload = { user: me, ts: Date.now(), origin: 'user', vesselState: localStorage.getItem('vesselState') || 'OFF', sensors: sensors };
              window.socket.emit('announce.state', payload);
              try { updateSyncStatus('active', me + ' @ ' + new Date().toLocaleTimeString()); } catch(e) {}
            } catch(e) {}
          });

          const __WAVE_USER = '<?php echo addslashes($_SESSION['username']); ?>';
          // When another client changes a sensor, update UI and localStorage
          window.socket.on('sensor.change', payload => {
            try {
              const key = payload.key;
              const isOn = !!payload.value;
              const sw = document.getElementById('st-sw-' + key);
              const dot = document.getElementById('st-dot-' + key);
              if (sw) sw.checked = isOn;
              if (dot) dot.className = 'st-dot ' + (isOn ? 'st-on' : 'st-off');
              try { localStorage.setItem('st-sensor-' + key, isOn ? '1' : '0'); } catch(e) {}
              // UI-only update: logging will be handled by server-emitted `log.event` to avoid duplicates
            } catch(e){}
          });

          window.socket.on('vessel.change', payload => {
            try {
              // Apply remote change but do NOT re-emit (prevent loops)
              ST_setVesselState(payload.state, false);
              try { localStorage.setItem('vesselState', payload.state); } catch(e){}
              // No direct logging here; server will emit a single log.event with a cleaned message
            } catch(e){}
          });

              // Apply incoming announced states from other clients
              window.socket.on('state.announce', payload => {
                try {
                  const me = '<?php echo addslashes($_SESSION['username']); ?>';
                  if (!payload || payload.user === me) return;
                  if (payload.sensors && typeof payload.sensors === 'object') {
                    Object.keys(payload.sensors).forEach(k => {
                      try {
                        const isOn = !!payload.sensors[k];
                        const sw = document.getElementById('st-sw-' + k);
                        const dot = document.getElementById('st-dot-' + k);
                        if (sw) sw.checked = isOn;
                        if (dot) dot.className = 'st-dot ' + (isOn ? 'st-on' : 'st-off');
                        try { localStorage.setItem('st-sensor-' + k, isOn ? '1' : '0'); } catch(e) {}
                      } catch(e) {}
                    });
                  }
                  if (payload.vesselState) {
                    try { ST_setVesselState(String(payload.vesselState || 'OFF'), false); localStorage.setItem('vesselState', String(payload.vesselState || 'OFF')); } catch(e) {}
                  }
                  try { updateSyncStatus('active', payload.user + ' @ ' + new Date(payload.ts || Date.now()).toLocaleTimeString()); } catch(e) {}
                } catch(e) {}
              });

              // Listen for server-emitted cleaned log events and display them.
              window.socket.on('log.event', payload => {
                try {
                  try { updateSyncStatus('active', new Date().toLocaleTimeString()); } catch(e){}
                  const msg = String(payload.message || payload.desc || '').trim();
                  if (msg) ST_addLog(payload.type || 'info', msg, { noDb: true, timestamp: payload.ts ? new Date(payload.ts).toLocaleString() : undefined });
                } catch(e) {}
              });

        } catch(e) { console.warn('socket init failed', e); }
      };
      document.head.appendChild(script);
    } catch (e) { console.warn('socket load error', e); }

    // Emit events when user toggles sensors or vessel
    const origST_toggleSensor = window.ST_toggleSensor;
    window.ST_toggleSensor = function(input, sensor) {
      origST_toggleSensor(input, sensor);
      try {
        const isOn = !!input.checked;
        if (window.socket && window.socket.connected) {
          window.socket.emit('sensor.change', { key: sensor, value: isOn, user: '<?php echo addslashes($_SESSION['username']); ?>', role: 'USER', ts: Date.now(), origin: 'local' });
        }
      } catch(e){}
    };

    const origST_togglePower = window.ST_togglePower;
    window.ST_togglePower = function() {
      // call original which already updates local state and emits via ST_setVesselState when confirmed
      origST_togglePower();
      // Do NOT emit here — ST_setVesselState handles emission after user confirms via SweetAlert.
    };

// Listen for storage events from other tabs (admin/user) and sync UI
window.addEventListener('storage', function(e) {
  try {
    // Sync sensor toggles
    if (e.key && e.key.startsWith('st-sensor-')) {
      const sensor = e.key.replace('st-sensor-', '');
      const isOn = e.newValue === '1';
      const sw = document.getElementById('st-sw-' + sensor);
      const dot = document.getElementById('st-dot-' + sensor);
      if (sw) sw.checked = isOn;
      if (dot) dot.className = 'st-dot ' + (isOn ? 'st-on' : 'st-off');
    }

    // Sync vessel state changes
    if (e.key === 'vesselState') {
      const state = e.newValue || 'OFF';
      ST_setVesselState(state);
      // Do not create a storage-based "remote" log; server will emit canonical log.event
    }
  } catch (err) { /* ignore */ }
});
</script>
</body>
</html>
