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

// Enforce single active session per account (user): compare DB token with session token
try {
  if (!empty($_SESSION['username'])) {
    $stmtToken = $conn->prepare("SELECT session_token FROM active_sessions WHERE username=? LIMIT 1");
    if ($stmtToken) {
      $stmtToken->bind_param('s', $_SESSION['username']);
      $stmtToken->execute();
      $stmtToken->bind_result($dbToken);
      if ($stmtToken->fetch()) {
        $stmtToken->close();
        if (empty($_SESSION['session_token']) || !hash_equals($dbToken, $_SESSION['session_token'])) {
          // Notify the user on this device that another login occurred, then logout
          $kick_msg = 'someone logged in using this account. you will be automatically log out';
          session_write_close();
          ?>
          <!doctype html>
          <html lang="en">
          <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Signed Out</title>
            <style>body{font-family:Segoe UI,Arial,Helvetica,sans-serif;background:#f8fafc;color:#0f172a;display:flex;align-items:center;justify-content:center;height:100vh;margin:0} .box{background:#fff;padding:28px;border-radius:12px;box-shadow:0 8px 32px rgba(2,6,23,0.08);max-width:720px;text-align:center} h1{font-size:20px;margin-bottom:8px;color:#072f4a} p{margin:0 0 12px;font-size:16px;color:#0b2233} .count{font-weight:800;color:#0b3b5a}</style>
          </head>
          <body>
            <div class="box">
              <h1>Signed in elsewhere</h1>
              <p><?php echo htmlspecialchars($kick_msg); ?></p>
              <p>You will be redirected to the login page in <span id="sec" class="count">5</span>s.</p>
              <p><a href="waveout.php">Log out now</a></p>
            </div>
            <script>
              (function(){
                var s = 5; var el = document.getElementById('sec');
                var t = setInterval(function(){ s--; if(s<=0){ clearInterval(t); window.location.href='waveout.php'; } el.textContent = s; }, 1000);
              })();
            </script>
          </body>
          </html>
          <?php
          exit;
        }
      } else {
        $stmtToken->close();
      }
    }
  }
} catch (Exception $e) {
  error_log('Session check error (user): ' . $e->getMessage());
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
  .water-grid { display: grid; grid-template-columns: 1.6fr 1fr; gap: 12px; margin-bottom: 8px; height: calc(100% - 12px); min-height: 520px; box-sizing: border-box; }
  .big-card.sensor-card { min-height: 0; height: 100%; font-size: 1rem; border-radius: 10px; display:flex; align-items:center; justify-content:center; box-sizing: border-box; }
  .right-grid { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: repeat(3, 1fr); gap: 10px; height: 100%; box-sizing: border-box; }
  /* Make user sensor cards match admin visuals and scale text to fill cards */
  .sensor-card { background: linear-gradient(145deg, #a1d4f5, #6ec1e4); border-radius: 12px; box-shadow: 0 6px 12px rgba(0,0,0,0.12); color: #012b45; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 0; height: 100%; padding: 12px 10px; box-sizing: border-box; }
  .sensor-card.wide { grid-column: span 2; }
  .sensor-card h3 { margin: 0 0 8px 0; font-size: 1.15rem; font-weight: 800; letter-spacing: .6px; text-transform: uppercase; }
  .sensor-card p { margin: 0; font-size: 2rem; font-weight: 900; line-height: 1; }

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

<!-- Inactivity auto-logout: 10 minutes idle -> show warning -> auto-logout -->
<script>
(function(){
  const IDLE_MS = 10 * 60 * 1000; // 10 minutes
  const WARNING_MS = 15 * 1000; // 15 seconds warning countdown
  let idleTimer = null;
  let warningTimer = null;
  let countdownInterval = null;

  function resetIdle(){
    if(idleTimer) clearTimeout(idleTimer);
    if(warningTimer){ clearTimeout(warningTimer); warningTimer = null; }
    if(countdownInterval){ clearInterval(countdownInterval); countdownInterval = null; }
    idleTimer = setTimeout(onIdle, IDLE_MS);
  }

  function onIdle(){
    let secondsLeft = Math.floor(WARNING_MS / 1000);
    Swal.fire({
      title: 'Inactive',
      html: 'You have been inactive. Logging out in <strong id="sw-count">'+secondsLeft+'</strong> seconds.',
      icon: 'warning',
      showCancelButton: false,
      confirmButtonText: 'Stay signed in',
      allowOutsideClick: false,
      allowEscapeKey: false,
      didOpen: () => {
        const el = document.getElementById('sw-count');
        countdownInterval = setInterval(() => {
          secondsLeft--;
          if(el) el.textContent = secondsLeft;
        }, 1000);
      }
    }).then((result) => {
      if(result.isConfirmed){
        resetIdle();
      } else {
        window.location.href = 'waveout.php';
      }
    });

    // Auto-logout after WARNING_MS if user doesn't interact
    warningTimer = setTimeout(() => {
      Swal.close();
      window.location.href = 'waveout.php';
    }, WARNING_MS);
  }

  ['mousemove','keydown','mousedown','touchstart','click','scroll'].forEach(evt => {
    window.addEventListener(evt, resetIdle, true);
  });

  // start the idle timer
  resetIdle();
})();
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
      <h2> <span id="perfHint" class="badge-hint" style="display:none;">live updates paused</span></h2>
      <div class="water-grid">
        <div class="big-card sensor-card" onclick="switchChart('WQI')">
          <h3>Water Quality Index</h3>
          <p id="wqiValue" name="wqi_value">--</p>
          <small id="wqi_status" style="display:block;margin-top:6px;font-size:0.85rem;color:#083344;opacity:0.95;">&nbsp;</small>
        </div>
        <div class="right-grid">
          <div class="sensor-card" onclick="switchChart('DO')"><h3>Dissolved Oxygen (mg/L)</h3><p id="do">--</p>
            <small id="do_status" style="display:block;margin-top:6px;font-size:0.75rem;color:#083344;opacity:0.95;">&nbsp;</small>
          </div>
          <div class="sensor-card" onclick="switchChart('TURB')"><h3>Turbidity (NTU)</h3>
            <p id="turbidity">--</p>
            <small id="turbidity_status" style="display:block;margin-top:6px;font-size:0.75rem;color:#083344;opacity:0.9;">&nbsp;</small>
          </div>
          <div class="sensor-card" onclick="switchChart('AMMO')">
            <h3>Ammonia (ppm)</h3>
            <p id="ammonia">--</p>
            <small id="ammonia_status" style="display:block;margin-top:6px;font-size:0.75rem;color:#083344;opacity:0.9;">&nbsp;</small>
          </div>
          <div class="sensor-card" onclick="switchChart('PH')"><h3>pH Level</h3><p id="ph_level">--</p>
            <small id="ph_status" style="display:block;margin-top:6px;font-size:0.85rem;color:#083344;opacity:0.95;">&nbsp;</small>
          </div>
          <div class="sensor-card wide" onclick="switchChart('TEMP')"><h3>Water Temperature (°C)</h3>
            <p id="temperature">--</p>
            <small id="temperature_status" style="display:block;margin-top:6px;font-size:0.75rem;color:#083344;opacity:0.9;">&nbsp;</small>
          </div>
        </div>
      </div>
      <!-- Live chart removed on user dashboard to maximize sensor cards -->
    </div>
  </div>

  <!-- Notifications Section (match admin exact table layout + styles) -->
  <div id="notificationsSection" class="section notifications-section" style="<?php echo ($current_tab === 'notifications') ? '' : 'display:none;'; ?>">
    <h2> </h2>
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

  <!-- TOOLS  (Simplified: keep NetDiag + 2FA only) -->
  <div id="systemSection" class="section" style="<?php echo ($current_tab === 'system') ? '' : 'display:none;'; ?>">
    <div class="tools-stage" style="padding:16px; display:flex; align-items:flex-start; justify-content:flex-start; gap:10px; flex-wrap:wrap;">
      <button id="runFaBtn" class="st-btn st-export st-pill" title="Run FA Code Generator" onclick="runFA()">Generate 2FA Code</button>
      <button id="runNetBtn" class="st-btn st-export st-pill" title="Run NetDiag as Administrator" onclick="runNetDiag()">Run Diagnostics</button>
    </div>
  </div>
  <script>
  // Minimal handlers for user dashboard to trigger server-side scripts
  async function runFA(){
    const btn = document.getElementById('runFaBtn');
    try {
      if (btn) { btn.disabled = true; btn.textContent = 'Starting...'; }
      const resp = await fetch('run_fagui.php', { method: 'POST', credentials: 'same-origin' });
      if (!resp.ok) {
        const txt = await resp.text();
        throw new Error('Server error: ' + resp.status + ' ' + txt);
      }
      const j = await resp.json();
      if (j.success) {
        Swal.fire('Started', j.message || 'FA script started', 'success');
        try { ST_addLog('info', `[USER] <?php echo addslashes($_SESSION['username']); ?> started FA_Code_Generator_GUI.py`); } catch(e){}
      } else {
        Swal.fire('Error', j.message || 'Failed to start FA script', 'error');
      }
    } catch (err) {
      Swal.fire('Error', err.message || 'Failed to start FA script', 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = 'Generate 2FA Code'; }
    }
  }

  async function runNetDiag(){
    const btn = document.getElementById('runNetBtn');
    try {
      if (btn) { btn.disabled = true; btn.textContent = 'Starting...'; }
      const resp = await fetch('run_netdiag.php', { method: 'POST', credentials: 'same-origin' });
      if (!resp.ok) {
        const txt = await resp.text();
        throw new Error('Server error: ' + resp.status + ' ' + txt);
      }
      const j = await resp.json();
      if (j.success) {
        try { ST_addLog('info', `[USER] <?php echo addslashes($_SESSION['username']); ?> started Network Diagnostic`); } catch(e){}
      } else {
        Swal.fire('Error', j.message || 'Failed to start NetDiag', 'error');
      }
    } catch (err) {
      Swal.fire('Error', err.message || 'Failed to start NetDiag', 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = 'Run Diagnostics'; }
    }
  }
  </script>

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
    // Vessel power UI removed

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
          window.ST_SENSOR_KEYS = ['ph','turb','temp','do','load1','load2','ultra'];
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

        // Vessel power removed; no initialization required

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
              window.socket.emit('presence', { user: me, ts: Date.now(), origin: 'local' });
              // build sensors snapshot
              const sensors = {};
              (window.ST_SENSOR_KEYS || ['ph','turb','temp','do','load1','load2','ultra']).forEach(k => {
                try { sensors[k] = (localStorage.getItem('st-sensor-' + k) === '1'); } catch(e) { sensors[k] = false; }
              });
              const payload = { user: me, ts: Date.now(), origin: 'local', sensors: sensors };
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

          // Vessel change events are ignored; power UI removed

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
                  // Ignore vessel state in announcements
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

    // Power toggle override removed

// Listen for storage events from other tabs (admin/user) and sync UI
window.addEventListener('storage', function(e) {
  try {
    // Sync sensor toggles
    if (e.key && e.key.startsWith('st-sensor-')) {
      const sensor = e.key.replace('st-sensor-', '');
      const isOn = e.newValue === '1';
      const sw = document.getElementById('st-sw-' + sensor);
      const dot = document.getElementById('st-dot-' + sensor);
      if (sw) sw.checked = isOn
      if (dot) dot.className = 'st-dot ' + (isOn ? 'st-on' : 'st-off');
    }

    // Ignore vesselState storage changes
  } catch (err) { /* ignore */ }
});

// --- Periodic session validation to auto-show kick message when another device logs in ---
(function(){
  var sessionPollInterval = null;
  var kicked = false;
  function showKick(message){
    if (kicked) return; kicked = true;
    try {
      var overlay = document.createElement('div');
      overlay.id = 'kickOverlay';
      overlay.style.position = 'fixed';
      overlay.style.inset = '0';
      overlay.style.background = 'rgba(0,0,0,0.6)';
      overlay.style.display = 'flex';
      overlay.style.alignItems = 'center';
      overlay.style.justifyContent = 'center';
      overlay.style.zIndex = 99999;
      overlay.innerHTML = '<div style="background:#fff;padding:24px;border-radius:12px;max-width:720px;text-align:center;">'
        + '<h1 style="font-size:20px;margin:0 0 8px;color:#072f4a">Signed in elsewhere</h1>'
        + '<p style="margin:0 0 12px;font-size:16px;color:#0b2233">'+(message||'someone logged in using this account. you will be automatically log out')+'</p>'
        + '<p>You will be redirected to the login page in <span id="kickSec" style="font-weight:800;color:#0b3b5a">5</span>s.</p>'
        + '<p><a href="waveout.php">Log out now</a></p></div>';
      document.body.appendChild(overlay);
      var s = 5;
      var t = setInterval(function(){ s--; var el = document.getElementById('kickSec'); if (el) el.textContent = s; if (s<=0){ clearInterval(t); window.location.href='waveout.php'; } }, 1000);
      if (sessionPollInterval) clearInterval(sessionPollInterval);
    } catch(e) { window.location.href = 'waveout.php'; }
  }

  async function checkSessionOnce(){
    try {
      var res = await fetch('session_check.php', { credentials: 'same-origin', cache: 'no-store' });
      if (!res.ok) return;
      var j = await res.json();
      if (j && j.valid === false) {
        showKick(j.message || 'someone logged in using this account. you will be automatically log out');
      }
    } catch(e) {}
  }

  sessionPollInterval = setInterval(checkSessionOnce, 3000);
  checkSessionOnce();
})();
</script>
<script>
// Monitoring poller for user dashboard: keep turbidity and temperature readings + their status in sync
(() => {
  const POLL_MS = 2000;
  let pollTimer = null;

  function getField(o, names) {
    if (!o) return undefined;
    for (const n of names) {
      if (typeof o[n] !== 'undefined') return o[n];
      const up = n.toUpperCase();
      for (const k of Object.keys(o)) {
        if (k.toUpperCase() === up) return o[k];
      }
    }
    return undefined;
  }

  async function fetchMonitoringSensorsOnce() {
    try {
      const active = document.querySelector('.nav-item.active')?.dataset.tab;
      if (active !== 'water' || document.hidden) return; // only update when Monitoring tab visible

      const res = await fetch('fetch_sensors.php', { cache: 'no-store' });
      if (!res.ok) return;
      const data = await res.json();
      let msg = null;
      if (!data) msg = null;
      else if (data.message) msg = data.message;
      else if (data.rpi) msg = data.rpi;
      else msg = data;

      // Accept common variants from the server (TEMP, TEMP_C, IMU_TEMP_C, NTU_VALUE, TURB, etc.)
      const turb = getField(msg, ['NTU_VALUE','NTU','TURB','TURBIDITY','turbidity']);
      const temp = getField(msg, ['TEMP_C','TEMP','IMU_TEMP_C','temperature']);

      // Helper: format numeric values when possible to 2 decimal places
      function fmtNum(v, digits = 2) {
        if (v === null || typeof v === 'undefined' || v === '') return v;
        // preserve string messages (like 'N/A' or '-')
        const n = Number(String(v).trim());
        if (Number.isFinite(n)) return n.toFixed(digits);
        return String(v);
      }

      // WQI helpers (same logic as admin): per-parameter Qi scorers and WQI computation
      function clamp(n, a, b){ return Math.max(a, Math.min(b, n)); }
      function scorePH(pH){ if (!Number.isFinite(pH)) return null; const q = 100 - Math.abs(pH - 7) * 66.6666667; return clamp(Math.round(q * 10) / 10, 0, 100); }
      function scoreDO(d){ if (!Number.isFinite(d)) return null; const q = (d / 8) * 100; return clamp(Math.round(q * 10) / 10, 0, 100); }
      function scoreTurb(t){ if (!Number.isFinite(t)) return null; const q = 100 - (t / 25) * 100; return clamp(Math.round(q * 10) / 10, 0, 100); }
      function scoreNH3(nh3){ if (!Number.isFinite(nh3)) return null; const q = 100 - (nh3 / 0.5) * 100; return clamp(Math.round(q * 10) / 10, 0, 100); }
      function scoreTemp(t){ if (!Number.isFinite(t)) return null; const q = 100 - Math.abs(t - 25) * 2; return clamp(Math.round(q * 10) / 10, 0, 100); }
      function computeWQIFromValues(vals){ const weights = { PH:0.2, DO:0.3, TURB:0.2, AMMO:0.2, TEMP:0.1 }; const qi = {}; qi.PH = Number.isFinite(vals.PH) ? scorePH(vals.PH) : null; qi.DO = Number.isFinite(vals.DO) ? scoreDO(vals.DO) : null; qi.TURB = Number.isFinite(vals.TURB) ? scoreTurb(vals.TURB) : null; qi.AMMO = Number.isFinite(vals.AMMO) ? scoreNH3(vals.AMMO) : null; qi.TEMP = Number.isFinite(vals.TEMP) ? scoreTemp(vals.TEMP) : null; let weightedSum = 0; let weightSum = 0; Object.keys(weights).forEach(k => { if (qi[k] !== null && typeof qi[k] !== 'undefined') { weightedSum += qi[k] * weights[k]; weightSum += weights[k]; } }); if (weightSum <= 0) return null; return Math.round((weightedSum / weightSum) * 10) / 10; }
      function wqiStatusLabel(wqi){ if (!Number.isFinite(wqi)) return ''; if (wqi >= 90) return 'Excellent'; if (wqi >= 70) return 'Good'; if (wqi >= 50) return 'Medium'; if (wqi >= 25) return 'Poor'; return 'Very Poor'; }

      // Status fields (case-insensitive keys, fallback to common variants)
      const turbStatus = getField(msg, ['NTU_STATUS','TURB_STATUS','TURBIDITY_STATUS','NTU_STATUS_MSG','TURB_STATUS_MSG','TURB_STATUS_TEXT','TURBIDITY_STATUS_MSG','STATUS_TURB','TURB_MSG']);
      const tempStatus = getField(msg, ['TEMP_STATUS','TEMPERATURE_STATUS','TEMP_STATUS_MSG','TEMP_C_STATUS','TEMPERATURE_STATUS_MSG','WATER_TEMP_STATUS','WATER_TEMPERATURE_STATUS','TEMP_MSG']);

  // Ammonia value and status (accept NH3_PPM and common variants). Show ammonia with two decimals.
  const ammo = getField(msg, ['NH3_PPM','NH3','AMMO','AMMONIA','ammonia']);
  const ammoStatus = getField(msg, ['NH3_STATUS','AMMO_STATUS','AMMONIA_STATUS','NH3_STATUS_MSG']);

      if (typeof turb !== 'undefined' && turb !== null && String(turb).trim() !== '') {
        const el = document.getElementById('turbidity'); if (el) el.textContent = fmtNum(turb, 1);
        _persistLastKnownPatch('turbidity', fmtNum(turb, 1));
        _persistLastKnownPatch('TURB', String(turb));
      } else {
        // restore persisted turbidity reading if present
        try {
          const raw = localStorage.getItem('wave_lastKnown_v1');
          if (raw) {
            const obj = JSON.parse(raw);
            const el = document.getElementById('turbidity');
            const v = (obj && (obj.turbidity ?? obj.TURB));
            if (el && (el.textContent === '--' || !el.textContent) && typeof v !== 'undefined' && v !== null && String(v).trim() !== '') {
              el.textContent = String(v);
            }
          }
        } catch(e) {}
      }
      if (typeof temp !== 'undefined' && temp !== null && String(temp).trim() !== '') {
        const el = document.getElementById('temperature'); if (el) el.textContent = fmtNum(temp, 2);
        _persistLastKnownPatch('temperature', fmtNum(temp, 2));
        _persistLastKnownPatch('TEMP', String(temp));
      } else {
        // restore persisted temperature reading if present
        try {
          const raw = localStorage.getItem('wave_lastKnown_v1');
          if (raw) {
            const obj = JSON.parse(raw);
            const el = document.getElementById('temperature');
            const v = (obj && (obj.temperature ?? obj.TEMP));
            if (el && (el.textContent === '--' || !el.textContent) && typeof v !== 'undefined' && v !== null && String(v).trim() !== '') {
              el.textContent = String(v);
            }
          }
        } catch(e) {}
      }

      // Update status text only on Monitoring tab (per request). Empty -> hide subtlely
      const tStatEl = document.getElementById('turbidity_status');
      if (tStatEl) {
        if (typeof turbStatus !== 'undefined' && turbStatus !== null && String(turbStatus).trim() !== '') {
          tStatEl.textContent = String(turbStatus);
          _persistLastKnownPatch('turbidity_status', String(turbStatus));
        } else {
          // restore persisted turbidity status if present
          try {
            const rawLK = localStorage.getItem('wave_lastKnown_v1');
            if (rawLK) {
              const obj = JSON.parse(rawLK);
              if (obj && obj.turbidity_status) tStatEl.textContent = obj.turbidity_status; else tStatEl.textContent = '';
            } else {
              tStatEl.textContent = '';
            }
          } catch (e) { tStatEl.textContent = ''; }
        }
      }
      const tmpStatEl = document.getElementById('temperature_status');
      if (tmpStatEl) {
        if (typeof tempStatus !== 'undefined' && tempStatus !== null && String(tempStatus).trim() !== '') {
          tmpStatEl.textContent = String(tempStatus);
          _persistLastKnownPatch('temperature_status', String(tempStatus));
        } else {
          // restore persisted temperature status if present
          try {
            const rawLK = localStorage.getItem('wave_lastKnown_v1');
            if (rawLK) {
              const obj = JSON.parse(rawLK);
              if (obj && obj.temperature_status) tmpStatEl.textContent = obj.temperature_status; else tmpStatEl.textContent = '';
            } else {
              tmpStatEl.textContent = '';
            }
          } catch (e) { tmpStatEl.textContent = ''; }
        }
      }

      // Update ammonia display and persist ammonia + status so Monitoring keeps showing last-known values
      const ammoEl = document.getElementById('ammonia');
      const ammoStatEl = document.getElementById('ammonia_status');
      function _persistLastKnownPatch(key, value) {
        try {
          const raw = localStorage.getItem('wave_lastKnown_v1');
          const obj = raw ? JSON.parse(raw) : {};
          obj[key] = value;
          localStorage.setItem('wave_lastKnown_v1', JSON.stringify(obj));
        } catch (e) { /* ignore */ }
      }

      if (typeof ammo !== 'undefined' && ammo !== null && String(ammo).trim() !== '') {
        if (ammoEl) ammoEl.textContent = fmtNum(ammo, 2);
        // persist formatted numeric string
        _persistLastKnownPatch('ammonia', (fmtNum(ammo,2)));
      } else {
        // if current message lacks ammonia, fall back to persisted value (do not overwrite if live absent)
        try {
          const raw = localStorage.getItem('wave_lastKnown_v1');
          if (raw) {
            const obj = JSON.parse(raw);
            if (obj && typeof obj.ammonia !== 'undefined' && ammoEl && (ammoEl.textContent === '--' || !ammoEl.textContent)) {
              ammoEl.textContent = obj.ammonia;
            }
          }
        } catch(e) {}
      }

      if (ammoStatEl) {
        if (typeof ammoStatus !== 'undefined' && ammoStatus !== null && String(ammoStatus).trim() !== '') {
          ammoStatEl.textContent = String(ammoStatus);
          _persistLastKnownPatch('ammonia_status', String(ammoStatus));
        } else {
          // restore persisted status if present
          try {
            const raw = localStorage.getItem('wave_lastKnown_v1');
            if (raw) {
              const obj = JSON.parse(raw);
              if (obj && obj.ammonia_status) ammoStatEl.textContent = obj.ammonia_status;
              else ammoStatEl.textContent = '';
            }
          } catch(e) { ammoStatEl.textContent = ''; }
        }
      }

      // --- PH & DO: display values and status (persist last-known as needed) ---
      const doVal = getField(msg, ['DO','DISSOLVED_OXYGEN','DO_MGL','DO_MG_L','DO_MG/L','DO_MG']);
      const phVal = getField(msg, ['PH','PH_LEVEL','PH_VAL','pH']);
      const doStatusRaw = getField(msg, ['DO_STATUS','DO_STATUS_MSG','DISSOLVED_OXYGEN_STATUS','do_status']);
      const phStatusRaw = getField(msg, ['PH_STATUS','PH_STATUS_MSG','ph_status','pH_STATUS']);

      const doEl = document.getElementById('do');
      const phEl = document.getElementById('ph_level');
      const doStatEl = document.getElementById('do_status');
      const phStatEl = document.getElementById('ph_status');

      if (typeof doVal !== 'undefined' && doVal !== null && String(doVal).trim() !== '') {
        if (doEl) doEl.textContent = String(doVal);
        _persistLastKnownPatch('do', String(doVal));
      } else {
        try { const raw = localStorage.getItem('wave_lastKnown_v1'); if (raw){ const obj=JSON.parse(raw); if (obj && typeof obj.do !== 'undefined' && doEl && (!doEl.textContent||doEl.textContent==='--')) doEl.textContent = obj.do; } } catch(e){}
      }

      if (typeof phVal !== 'undefined' && phVal !== null && String(phVal).trim() !== '') {
        if (phEl) phEl.textContent = String(phVal);
        _persistLastKnownPatch('ph', String(phVal));
      } else {
        try { const raw = localStorage.getItem('wave_lastKnown_v1'); if (raw){ const obj=JSON.parse(raw); if (obj && typeof obj.ph !== 'undefined' && phEl && (!phEl.textContent||phEl.textContent==='--')) phEl.textContent = obj.ph; } } catch(e){}
      }

      if (doStatEl) {
        if (typeof doStatusRaw !== 'undefined' && doStatusRaw !== null && String(doStatusRaw).trim() !== '') {
          doStatEl.textContent = String(doStatusRaw);
          _persistLastKnownPatch('do_status', String(doStatusRaw));
        } else {
          try { const raw = localStorage.getItem('wave_lastKnown_v1'); if (raw){ const obj=JSON.parse(raw); if (obj && obj.do_status) doStatEl.textContent = obj.do_status; else doStatEl.textContent=''; } } catch(e){ doStatEl.textContent=''; }
        }
      }

      if (phStatEl) {
        if (typeof phStatusRaw !== 'undefined' && phStatusRaw !== null && String(phStatusRaw).trim() !== '') {
          phStatEl.textContent = String(phStatusRaw);
          _persistLastKnownPatch('ph_status', String(phStatusRaw));
        } else {
          try { const raw = localStorage.getItem('wave_lastKnown_v1'); if (raw){ const obj=JSON.parse(raw); if (obj && obj.ph_status) phStatEl.textContent = obj.ph_status; else phStatEl.textContent=''; } } catch(e){ phStatEl.textContent=''; }
        }
      }

      // --- WQI: prefer server-provided WQI, otherwise compute from available values and fallbacks ---
      try {
        const doVal = getField(msg, ['DO','DISSOLVED_OXYGEN','DO_MGL','DO_MG_L','DO_MG/L','DO_MG']);
        const phVal = getField(msg, ['PH','PH_LEVEL','PH_VAL','pH']);
        const wqiServer = getField(msg, ['WQI','WQI_VALUE','WATER_QUALITY_INDEX','WATER_QUALITY']);

        // parse helper
        function parseNum(v){ if (v === null || typeof v === 'undefined' || v === '') return NaN; const n = Number(String(v).toString().trim()); return Number.isFinite(n) ? n : NaN; }

        const parsedDO = parseNum(doVal);
        const parsedPH = parseNum(phVal);
        const parsedTurb = parseNum(turb);
        const parsedTemp = parseNum(temp);
        const parsedAmmo = parseNum(ammo);

        let finalWqi = null;
        let finalWqiStatus = '';

        if (typeof wqiServer !== 'undefined' && wqiServer !== null && String(wqiServer).toString().trim() !== '') {
          const pv = parseNum(wqiServer);
          if (Number.isFinite(pv)) {
            finalWqi = Math.round(pv * 10) / 10;
            finalWqiStatus = wqiStatusLabel(finalWqi);
          }
        }

        if (finalWqi === null) {
          // build values object using live readings or last-known fallbacks
          let lastKnown = {};
          try { lastKnown = JSON.parse(localStorage.getItem('wave_lastKnown_v1') || '{}') || {}; } catch(e) { lastKnown = {}; }
          const fallback = v => { const n = parseNum(v); return Number.isFinite(n) ? n : NaN; };
          const vals = {
            PH: Number.isFinite(parsedPH) ? parsedPH : fallback(lastKnown.ph) || fallback(lastKnown.PH) || NaN,
            DO: Number.isFinite(parsedDO) ? parsedDO : fallback(lastKnown.do) || fallback(lastKnown.DO) || NaN,
            TURB: Number.isFinite(parsedTurb) ? parsedTurb : fallback(lastKnown.turbidity) || fallback(lastKnown.TURB) || NaN,
            AMMO: Number.isFinite(parsedAmmo) ? parsedAmmo : (function(){ const a = lastKnown && lastKnown.ammonia ? Number(String(lastKnown.ammonia).replace(/[^0-9\.\-]/g,'')) : NaN; return Number.isFinite(a)?a:NaN; })(),
            TEMP: Number.isFinite(parsedTemp) ? parsedTemp : fallback(lastKnown.temperature) || fallback(lastKnown.TEMP) || NaN
          };

          const computed = computeWQIFromValues(vals);
          if (Number.isFinite(computed)) {
            finalWqi = Math.round(computed * 10) / 10;
            finalWqiStatus = wqiStatusLabel(finalWqi);
          }
        }

        // render and persist
        const wqiEl = document.getElementById('wqiValue');
        const wqiStatusEl = document.getElementById('wqi_status');
        if (wqiEl) {
          if (finalWqi === null || typeof finalWqi === 'undefined' || !Number.isFinite(finalWqi)) wqiEl.textContent = '--'; else wqiEl.textContent = (finalWqi).toFixed(1);
        }
        if (wqiStatusEl) wqiStatusEl.textContent = finalWqiStatus || '';
        if (finalWqi !== null && Number.isFinite(finalWqi)) _persistLastKnownPatch('wqi', (finalWqi).toFixed(1));
        if (finalWqiStatus) _persistLastKnownPatch('wqi_status', finalWqiStatus);
      } catch (e) { /* ignore WQI errors */ }

    } catch (e) {
      // silent
    }
  }

  function startUserMonitoring() {
    if (pollTimer) return;
    fetchMonitoringSensorsOnce();
    pollTimer = setInterval(fetchMonitoringSensorsOnce, POLL_MS);
  }
  function stopUserMonitoring() {
    if (!pollTimer) return; clearInterval(pollTimer); pollTimer = null;
  }

  // Start when the page loads. If Monitoring isn't active, fetcher will be dormant.
  document.addEventListener('DOMContentLoaded', startUserMonitoring);

  // Pause when page hidden
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopUserMonitoring(); else startUserMonitoring();
  });
  // Also resume/stop when user switches tabs in-app
  document.addEventListener('click', (e) => {
    // simple heuristic: if a nav-item was clicked, re-evaluate
    if (e.target && e.target.closest && e.target.closest('.nav-item')) {
      setTimeout(() => { const active = document.querySelector('.nav-item.active')?.dataset.tab; if (active === 'water') startUserMonitoring(); else stopUserMonitoring(); }, 120);
    }
  }, true);
  // Restore persisted last-known ammonia/status immediately so Monitoring shows stable values
  try {
    const rawLK = localStorage.getItem('wave_lastKnown_v1');
    if (rawLK) {
      const lk = JSON.parse(rawLK);
      if (lk) {
  try { if (lk.ammonia && document.getElementById('ammonia')) document.getElementById('ammonia').textContent = lk.ammonia; } catch(e){}
  try { if (lk.ammonia_status && document.getElementById('ammonia_status')) document.getElementById('ammonia_status').textContent = lk.ammonia_status; } catch(e){}
  try { if (lk.turbidity && document.getElementById('turbidity')) document.getElementById('turbidity').textContent = lk.turbidity; } catch(e){}
  try { if (lk.turbidity_status && document.getElementById('turbidity_status')) document.getElementById('turbidity_status').textContent = lk.turbidity_status; } catch(e){}
  try { if (lk.temperature && document.getElementById('temperature')) document.getElementById('temperature').textContent = lk.temperature; } catch(e){}
  try { if (lk.temperature_status && document.getElementById('temperature_status')) document.getElementById('temperature_status').textContent = lk.temperature_status; } catch(e){}
  try { if (lk.wqi && document.getElementById('wqiValue')) document.getElementById('wqiValue').textContent = String(lk.wqi); } catch(e){}
  try { if (lk.wqi_status && document.getElementById('wqi_status')) document.getElementById('wqi_status').textContent = lk.wqi_status; } catch(e){}
      }
    }
  } catch(e) {}
})();
</script>
</body>
</html>
