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
<title>USER Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Righteous&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="icon" type="image/png" href="wave_logo2.png">
<link rel="stylesheet" href="ad_dashboard.css">
<style>body { background: url('wavebg.jpeg') no-repeat center center fixed; background-size: cover; }</style>
<style>

/* Layout and card styles copied from ad_dashboard.php for consistency */
html, body {
  height: 100vh;
  width: 100vw;
  overflow: hidden !important;
  margin: 0;
  padding: 0;
}
.main-content, .users-container, .notifications-wrap, .tools-stage {
  max-width: 100vw;
  max-height: 100vh;
  overflow: hidden !important;
  box-sizing: border-box;
  background: #fff;
  border-radius: 0;
  box-shadow: none;
  color: #222;
}
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
  color: #fff;
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 1000;
  height: 100px;
  display: flex;
  align-items: center;
}
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
  top: 100px;
  left: 0;
  bottom: 0;
  width: 220px;
  z-index: 999;
}
.main-content {
  margin-top: 110px;
  margin-left: 230px;
  margin-bottom: 0;
  padding-bottom: 48px;
}
.water-grid { display: grid; grid-template-columns: 2fr 3fr; gap: 12px; margin-bottom: 8px; }
.big-card.sensor-card { min-height: 160px; font-size: 1rem; }
.right-grid { display: grid; grid-template-columns: 1fr 1fr; grid-template-rows: repeat(3, 1fr); gap: 6px; }
.sensor-card { background: linear-gradient(145deg, #7ed6f7, #5faee3); border-radius: 10px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); color: #222; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 38px; font-size: 0.92rem; padding: 6px 2px; }
.sensor-card.wide { grid-column: span 2; }
.sensor-card h3 { margin: 0 0 2px 0; font-size: 0.92rem; font-weight: 700; }
.sensor-card p { margin: 0; font-size: 1rem; font-weight: 600; }
.chart-container { height: 400px; margin-top: 20px; }
#lastUpdatedLabel { display: none; }
.notifications-wrap { background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.08); padding:16px; }
#st-logBox { background:#1e5162; color:#fff; padding:15px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.3); max-height:70vh; overflow-y:auto; font-family:monospace; }
.st-log-entry { margin:5px 0; padding:4px; border-bottom:1px solid #3a7a8c; }
.st-info { color:#4cc9f0; }
.st-warn { color:#ffb703; }
.st-alert { color:#ff6b6b; font-weight:bold; }
.badge-hint { display:inline-block; margin-left:8px; padding:2px 8px; font-size:.75rem; color:#0f5132; background:#d1e7dd; border-radius:9999px; }
.st-btn { font-size:14px; padding:10px 18px; border:none; border-radius:10px; cursor:pointer; font-weight:700; color:#fff; margin:8px 8px 0 0; box-shadow:0 4px 12px rgba(0,0,0,.3); }
.st-clear   { background: linear-gradient(135deg, #ffb703, #fb8500); }
.st-clear:hover { background: linear-gradient(135deg, #fb8500, #d97706); }
.st-export  { background: linear-gradient(135deg, #06d6a0, #1b9aaa); }
.st-export:hover { background: linear-gradient(135deg, #04ad84, #15807c); }
.st-pill {
  background: linear-gradient(180deg,#49d7ff,#1aa6ff) !important;
  border-radius: 9999px !important;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.35), 0 6px 14px rgba(0,0,0,.25) !important;
  color:#083344 !important;
  border:1px solid rgba(255,255,255,.35);
}
.st-pill:hover { filter: brightness(0.95); }
</style>
</head>
<body>
<script>
// Force redirect to login if session is missing (prevents back navigation after logout)
if (window.performance && window.performance.navigation && window.performance.navigation.type === 2) {
  // If navigation is back/forward, reload to trigger PHP session check
  window.location.reload();
}
</script>

<div class="header" style="background: rgba(255,255,255,0.12); border-radius: 32px; box-shadow: 0 8px 32px rgba(30,81,98,0.18); backdrop-filter: blur(24px) saturate(180%) brightness(1.12); -webkit-backdrop-filter: blur(24px) saturate(180%) brightness(1.12); border: 1.5px solid rgba(255,255,255,0.18); color: #fff; position: fixed; top: 0; left: 0; right: 0; z-index: 1000; height: 100px; display: flex; align-items: center;">
  <div class="header-left">
    <img src="isu.png" alt="ISU Logo" height="65" width="65" class="isu-logo">
    <div class="system-title">User Dashboard</div>
    <div class="admin-title"><img src="wave_logo2.png" alt="WAVE Logo"></div>
  </div>
  <div class="header-right">
    <div class="admin-dropdown" onclick="toggleDropdown()">
      <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION["username"]); ?> ▾
      <div id="dropdownMenu" class="dropdown-content">
        <!-- JS-driven logout so we can update logs + localStorage before navigating -->
  <button type="button" id="logoutBtnOcean" class="logout-btn-ocean"><i class="fas fa-sign-out-alt"></i><span class="logout-text">Logout</span></button>
        <style>
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
        #dropdownMenu {
          background: transparent !important;
          box-shadow: none !important;
          border-radius: 0 !important;
          display: flex !important;
          flex-direction: column !important;
          align-items: center !important;
          justify-content: flex-start !important;
          padding: 0 !important;
          min-width: unset !important;
        }
        #dropdownMenu .logout-btn-ocean {
          width: fit-content !important;
          min-width: 0 !important;
          max-width: 120px !important;
          padding: 0 10px 0 8px !important;
          display: flex !important;
          justify-content: center !important;
          align-items: center !important;
          align-self: center !important;
          margin: 2px 0 0 0 !important;
          background: linear-gradient(135deg, #a7ffeb 0%, #40c4ff 60%, #00bcd4 100%) !important;
          box-shadow: 0 2px 8px 0 #40c4ff22 !important;
        }
        .logout-btn-ocean .logout-text {
          padding-right: 2px;
        }
        .logout-btn-ocean i {
          font-size: 1em;
          color: #00bcd4;
        }
        .logout-text {
          font-size: 0.98em;
          color: #01579b;
          font-weight: 600;
          margin-left: 2px;
          letter-spacing: 0.02em;
        }
        .logout-btn-ocean:hover {
          background: linear-gradient(135deg, #40c4ff 0%, #a7ffeb 100%);
          color: #fff;
          box-shadow: 0 4px 12px 0 #00bcd433;
        }
        .logout-btn-ocean:hover i,
        .logout-btn-ocean:hover .logout-text {
          color: #fff;
        }
        </style>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
          var logoutBtn = document.getElementById('logoutBtnOcean');
          if (logoutBtn) {
            logoutBtn.addEventListener('click', function(e) {
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
              }).then((result) => {
                if (result.isConfirmed) {
                  performLogout();
                }
              });
            });
          }
        });
        </script>
      </div>
    </div>
  </div>

</div>

<div class="main-navigation" style="background: rgba(255,255,255,0.12); border-radius: 32px; box-shadow: 0 8px 32px rgba(30,81,98,0.18); backdrop-filter: blur(24px) saturate(180%) brightness(1.12); -webkit-backdrop-filter: blur(24px) saturate(180%) brightness(1.12); border: 1.5px solid rgba(255,255,255,0.18); color: #fff; position: fixed; top: 100px; left: 0; bottom: 0; width: 220px; z-index: 999; max-width: 100vw; max-height: 100vh; overflow: hidden !important; box-sizing: border-box;">
<script>
function toggleDropdown(){ document.getElementById('dropdownMenu').classList.toggle('dropdown-show'); }
function performLogout(){
  try {
    // stopMonitoring();
    // ST_toggleAllSensors(false);
    // ST_setVesselState("OFF");
    // ST_addLog("alert","System shutdown initiated by User");
    // ST_addLog("info","<?php echo addslashes($_SESSION['username']); ?> logged out");
    setTimeout(()=>{
      setTimeout(()=>{ window.location.href='waveout.php'; }, 50);
    }, 500);
  } catch(e) {
    window.location.href='waveout.php';
  }
}
</script>
  <div class="nav-container">
  <button class="<?php echo ($current_tab === 'water') ? 'nav-item active' : 'nav-item'; ?>" data-tab="water"><span class="nav-icon"><i class="fas fa-water"></i></span> <span class="tab-label"><b>MONITORING</b></span> </button>
  <button class="<?php echo ($current_tab === 'notifications') ? 'nav-item active' : 'nav-item'; ?>" data-tab="notifications"><span class="nav-icon"><i class="fas fa-bell"></i></span> <span class="tab-label"><b>NOTIFICATION</b></span> </button>
  <button class="<?php echo ($current_tab === 'feedlogs') ? 'nav-item active' : 'nav-item'; ?>" data-tab="feedlogs"><span class="nav-icon"><i class="fas fa-fish"></i></span> <span class="tab-label"><b>FEEDER</b></span> </button>
  <a href="controller.php?from=user" class="nav-item<?php echo ($current_tab === 'controller') ? ' active' : ''; ?>" id="controllerLink"><span class="nav-icon"><i class="fas fa-ship"></i></span> <span class="tab-label"><b>CONTROLLER</b></span></a>
  </div>
</div>

<div class="main-content" id="mainContent">
  <!-- MONITORING SECTION (copied from ad_dashboard.php) -->
  <div id="waterSection" class="section" style="<?php echo ($current_tab === 'water') ? '' : 'display:none;'; ?>;height:100%;display:flex;flex-direction:column;">
    <div class="water-quality-section" style="flex:1 1 0;display:flex;flex-direction:column;min-height:0;">
      <h2>Monitoring <span id="perfHint" class="badge-hint" style="display:none;">live updates paused</span></h2>
      <div class="water-grid" style="flex:1 1 0;min-height:0;">
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
      <div class="card wide chart-container" style="margin-top: 18px;">
      </div>
      <div class="card wide chart-container">
        <h3 id="chartTitle">WQI Live Chart</h3>
        <canvas id="liveChart" height="140" style="width:100%;max-width:100%;display:block;"></canvas>
      </div>
      <div style="margin-top:10px;color:#555;font-size:0.95rem;">
        <span id="lastUpdatedLabel">Last updated: <em id="lastUpdatedValue">--</em></span>
      </div>
    </div>
  </div>

  <!-- NOTIFICATIONS (FULL-WIDTH LOGS – no dropdown) -->
  <div id="notificationsSection" class="section" style="<?php echo ($current_tab === 'notifications') ? '' : 'display:none;'; ?>">
    <h2>Notifications Logs</h2>
    <div class="notifications-wrap">
      <div class="tool-actions" style="margin-bottom:10px;">
        <button class="st-btn st-clear st-pill" onclick="ST_confirmClearLogs()">Clear Logs</button>
        <button class="st-btn st-export st-pill" onclick="ST_exportLogsPDF()">Export PDF</button>
      </div>
      <div id="st-logBox"></div>
    </div>
  </div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
<script>
// Notifications tab logic (from ad_dashboard.php, user version)
function ST_addLog(type, message){
  const box = document.getElementById("st-logBox");
  if (!box) return;
  const el  = document.createElement("div");
  el.className = "st-log-entry st-"+type;
  el.textContent = `[${new Date().toLocaleString()}] ${message}`;
  box.prepend(el);
  ST_saveLogs();
}
function ST_saveLogs(){
  const box = document.getElementById("st-logBox");
  if (!box) return;
  const logs = Array.from(box.querySelectorAll(".st-log-entry")).map(e=>({
    type: e.className.replace("st-log-entry ",""),
    text: e.textContent
  }));
  localStorage.setItem("systemLogs", JSON.stringify(logs));
}
function ST_loadLogs(){
  const box = document.getElementById("st-logBox");
  if (!box) return;
  const logs = JSON.parse(localStorage.getItem("systemLogs") || "[]");
  logs.forEach(log=>{
    const el = document.createElement("div");
    el.className = "st-log-entry " + log.type;
    el.textContent = log.text;
    box.appendChild(el);
  });
}
function ST_clearLogs(){
  localStorage.removeItem("systemLogs");
  const box = document.getElementById("st-logBox");
  if (box) box.innerHTML = "";
}
function ST_confirmClearLogs(){
  Swal.fire({
    title:'Clear Logs?', text:'This will permanently delete all logs.',
    icon:'warning', showCancelButton:true,
    confirmButtonColor:'#ffb703', cancelButtonColor:'#aaa',
    confirmButtonText:'Yes, clear'
  }).then((r)=>{
    if (r.isConfirmed) {
      ST_clearLogs();
      Swal.fire('Cleared!','All logs have been deleted.','success');
    }
  });
}
function ST_exportLogsPDF(){
  const logs = JSON.parse(localStorage.getItem("systemLogs") || "[]");
  if (logs.length === 0) { Swal.fire("No Logs","There are no logs to export.","info"); return; }
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF();
  doc.setFontSize(16);
  doc.text("System Logs Report", 14, 20);
  doc.setFontSize(12);
  doc.text(`Generated: ${new Date().toLocaleString()}`, 14, 28);
  const tableData = logs.map(l => [l.type.toUpperCase(), l.text]);
  doc.autoTable({ head: [['Type','Message']], body: tableData, startY:35, styles:{fontSize:10, cellPadding:3}, headStyles:{fillColor:[0,119,182]} });
  doc.save("system_logs.pdf");
}
// On load, restore logs
window.addEventListener('load', ()=>{
  ST_loadLogs();
});
</script>

  <!-- FEEDER SECTION (iframe for feedlogs) -->
  <div id="feedlogsSection" class="section" style="<?php echo ($current_tab === 'feedlogs') ? '' : 'display:none;'; ?>">
  <iframe src="feeder.php" style="width:100%;height:80vh;border:none;"></iframe>
  </div>

  <!-- CONTROLLER SECTION (redirect) -->
  <div id="controllerSection" class="section" style="<?php echo ($current_tab === 'controller') ? '' : 'display:none;'; ?>">
    <iframe src="controller.php?from=user" style="width:100%;height:80vh;border:none;"></iframe>
  </div>
</div>

<script>
/* Dropdown */
function toggleDropdown(){ 
  document.getElementById('dropdownMenu').classList.toggle('dropdown-show'); 
}

// Navigation switching (single navButtons declaration, only here)
if (typeof window.userDashboardNavButtons !== 'undefined') {
  window.navButtons = window.userDashboardNavButtons;
} else {
  window.navButtons = document.querySelectorAll(".nav-item[data-tab]");
  window.userDashboardNavButtons = window.navButtons;
}
function navSwitchTo(tab) {
  const sections = document.querySelectorAll(".section");
  sections.forEach(s => s.style.display = "none");
  window.navButtons.forEach(b => b.classList.remove("active"));
  const btn = Array.from(window.navButtons).find(b => b.dataset.tab === tab);
  if (btn) btn.classList.add("active");
  const sec = document.getElementById(tab + "Section");
  if (sec) sec.style.display = "block";
}
window.navButtons.forEach(btn => {
  btn.addEventListener("click", () => {
    const tab = btn.dataset.tab;
    navSwitchTo(tab);
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url);
  });
});
navSwitchTo("<?php echo $current_tab; ?>");
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// --- Monitoring tab live chart and sensor data logic (from admin) ---
// Only declare navButtons once globally
let navButtons;
if (typeof window.userDashboardNavButtons !== 'undefined') {
  navButtons = window.userDashboardNavButtons;
} else {
  navButtons = document.querySelectorAll(".nav-item[data-tab]");
  window.userDashboardNavButtons = navButtons;
}
let pollTimer = null;
let chartReady = false;
function startMonitoring() {
  if (pollTimer) return;
  const hint = document.getElementById('perfHint'); if (hint) hint.style.display = 'none';
  fetchData();
  pollTimer = setInterval(fetchData, 2000);
}
function stopMonitoring() {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
    const hint = document.getElementById('perfHint');
    if (hint) hint.style.display = 'inline-block';
  }
}
function navSwitchTo(tab){
  const sections=document.querySelectorAll(".section");
  sections.forEach(s=>s.style.display="none");
  navButtons.forEach(b=>b.classList.remove("active"));
  const btn=[...navButtons].find(b=>b.dataset.tab===tab);
  if(btn) btn.classList.add("active");
  const sec=document.getElementById(tab+"Section");
  if(sec) sec.style.display="block";
  if (tab === 'water') startMonitoring(); else stopMonitoring();
  const url=new URL(window.location);
  url.searchParams.set('tab',tab);
  window.history.replaceState({},'',url);
}
navButtons.forEach(btn=>{
  btn.addEventListener("click", ()=>{
    const tab=btn.dataset.tab;
    navSwitchTo(tab);
  });
});
document.addEventListener('visibilitychange', ()=>{
  if (document.hidden) stopMonitoring();
  else {
    const active = document.querySelector(".nav-item.active")?.dataset.tab;
    if (active === 'water') startMonitoring();
  }
});
window.addEventListener('beforeunload', ()=>{ stopMonitoring(); });
document.getElementById('controllerLink')?.addEventListener('click', ()=>{ stopMonitoring(); });
navSwitchTo("<?php echo $current_tab; ?>");
const sensorConfig = {
  WQI:  { label: "WQI",         color: "green",  max: 100 },
  PH:   { label: "pH",          color: "teal",   max: 14  },
  TURB: { label: "Turbidity",   color: "orange", max: 230 },
  TEMP: { label: "Temperature", color: "red",    max: 50  },
  AMMO: { label: "Ammonia",     color: "purple", max: 20  },
  DO:   { label: "DO",          color: "blue",   max: 15  }
};
let activeSensor = "WQI";
let liveChart;
const maxPoints = 60;
const lastValueLabelPlugin = {
  id: 'lastValueLabel',
  afterDatasetsDraw(chart) {
    const { ctx } = chart;
    const ds = chart.data.datasets[0];
    if (!ds || ds.data.length === 0) return;
    const meta = chart.getDatasetMeta(0);
    const lastIndex = ds.data.length - 1;
    const lastPoint = meta.data[lastIndex];
    if (!lastPoint) return;
    const value = ds.data[lastIndex];
    ctx.save();
    ctx.font = '12px Segoe UI, sans-serif';
    ctx.fillStyle = '#333';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'bottom';
    ctx.fillText(String(value), lastPoint.x + 6, lastPoint.y - 6);
    ctx.restore();
  }
};
const lastUpdatedPlugin = {
  id: 'lastUpdatedPlugin',
  afterDraw(chart) {
    const ctx = chart.ctx;
    const area = chart.chartArea;
    const txt = document.getElementById("lastUpdatedValue").textContent;
    if (!txt || txt === "--") return;
    ctx.save();
    ctx.font = '12px Segoe UI, sans-serif';
    ctx.fillStyle = '#444';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'top';
    ctx.fillText("Last updated: " + txt, area.left + 6, area.bottom + 4);
    ctx.restore();
  }
};
function setupChart(sensorKey){
  const ctx = document.getElementById('liveChart').getContext('2d');
  if (liveChart) liveChart.destroy();
  const conf = sensorConfig[sensorKey];
  document.getElementById('chartTitle').innerText = conf.label + ' Live Chart';
  liveChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: [],
      datasets: [{
        label: conf.label,
        borderColor: conf.color,
        backgroundColor: 'transparent',
        data: [],
        fill: false,
        tension: 0.25,
        pointRadius: 2.5,
        pointHoverRadius: 6,
        borderWidth: 2
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 0 },
      interaction: { mode: 'nearest', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          enabled: true,
          callbacks: { label: function(ctx){ return `${conf.label}: ${ctx.parsed.y}`; } }
        }
      },
      scales: {
        x: { display: true, ticks: { maxRotation: 0, autoSkip: true } },
        y: { min: 0, max: conf.max, ticks: { stepSize: Math.max(1, Math.round(conf.max/5)) } }
      },
      layout: { padding: { bottom: 22 } }
    },
    plugins: [lastValueLabelPlugin, lastUpdatedPlugin]
  });
  chartReady = true;
}
function switchChart(sensorKey){ activeSensor = sensorKey; setupChart(sensorKey); }
(function lazyInitChart(){
  if (window.requestIdleCallback) {
    requestIdleCallback(()=>{ setupChart(activeSensor); });
  } else {
    setTimeout(()=>{ setupChart(activeSensor); }, 0);
  }
})();
const FLASK_BASE = "http://192.168.1.3:5000";
async function robustFetchJson() {
  const sameOrigin = `${window.location.pathname}?api=get`;
  const remote     = `${FLASK_BASE}/get`;
  try {
    const r1 = await fetch(sameOrigin, { method: "GET", cache: "no-store" });
    if (r1.ok) return await r1.json();
  } catch (e) { }
  const r2 = await fetch(remote, { method: "GET", cache: "no-store" });
  return await r2.json();
}
function safeNumber(x, def=0){ const n = Number(x); return Number.isFinite(n) ? n : def; }
function formatTimestamp(ts) {
  const d = ts ? new Date(ts) : new Date();
  if (isNaN(d)) return "--";
  const mm = String(d.getMonth()+1).padStart(2,"0");
  const dd = String(d.getDate()).padStart(2,"0");
  const yyyy = d.getFullYear();
  let hours = d.getHours();
  const minutes = String(d.getMinutes()).padStart(2,"0");
  const ampm = hours >= 12 ? "PM" : "AM";
  hours = hours % 12; hours = hours ? hours : 12;
  return `${mm}/${dd}/${yyyy} ${hours}:${minutes} ${ampm}`;
}
async function fetchData() {
  const active = document.querySelector(".nav-item.active")?.dataset.tab;
  if (active !== 'water' || !chartReady) return;
  try {
    const wrapper = await robustFetchJson();
    if (wrapper && typeof wrapper.message === 'object') {
      const s = wrapper.message;
      const data = {
        WQI:  safeNumber(s.WQI  ?? s.wqi),
        PH:   safeNumber(s.PH   ?? s.pH),
        TURB: safeNumber(s.TURB ?? s.turb),
        TEMP: safeNumber(s.TEMP ?? s.temp),
        AMMO: safeNumber(s.AMMO ?? s.ammo),
        DO:   safeNumber(s.DO   ?? s.do)
      };
      document.getElementById("wqiValue").textContent    = data.WQI;
      document.getElementById("ph_level").textContent    = data.PH;
      document.getElementById("turbidity").textContent   = data.TURB;
      document.getElementById("temperature").textContent = data.TEMP;
      document.getElementById("ammonia").textContent     = data.AMMO;
      document.getElementById("do").textContent          = data.DO;
      const raw = s.last_updated || s.updated || Date.now();
      document.getElementById("lastUpdatedValue").textContent = formatTimestamp(raw);
      const ds = liveChart.data.datasets[0];
      liveChart.data.labels.push('');
      ds.data.push(data[activeSensor]);
      if (ds.data.length > maxPoints) { ds.data.shift(); liveChart.data.labels.shift(); }
      const conf = sensorConfig[activeSensor];
      liveChart.options.scales.y.max = conf.max;
      liveChart.update('none');
    }
  } catch (err) { }
}
</script>
</body>
</html>
