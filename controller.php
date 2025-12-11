<?php
// Respect caller context (iframe or link) so we open the correct named session
$from = isset($_GET['from']) ? strtolower($_GET['from']) : '';
if ($from === 'admin') {
  session_name('WAVE_ADMIN');
} elseif ($from === 'user') {
  session_name('WAVE_USER');
}
session_start();
// Prevent browser caching of protected pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

// Default fallback if not logged in
$backTarget = "wavelogin.php";

if (isset($_SESSION["LAR_level"])) {
    if ($_SESSION["LAR_level"] == 2) {
        $backTarget = "ad_dashboard.php?tab=water"; // Admin
    } elseif ($_SESSION["LAR_level"] == 1) {
        $backTarget = "user_dashboard.php?tab=water"; // User
    }
}
?> 
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>W.A.V.E - Controller</title>
  <link href="https://fonts.googleapis.com/css2?family=Righteous&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="icon" type="image/png" href="wave_logo2.png">
  <style>
    body {
      margin: 0;
      height: 100vh;
      overflow: hidden;
      font-family: 'Righteous', sans-serif;
      color: #e2e8f0;
      display: flex;
      flex-direction: column;
      /* background moved to .bg-blur overlay */
    }
    .bg-blur {
      position: fixed;
      top: 0; left: 0; width: 100vw; height: 100vh;
      background: url('wavebg.jpeg') no-repeat center center fixed;
      background-size: cover;
      filter: blur(16px) brightness(1.1);
      z-index: 0;
    }
    .main, .header, .status-bar, .back-btn-ocean {
      position: relative;
      z-index: 1;
    }

    /* Header */
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
      justify-content: center;
      padding: 0 25px;
    }
    .header-left { display: flex; align-items: center; gap: 20px; }
    .header-left img { height: 65px; }
    .system-title {
      font-family: 'Righteous', cursive;
      font-size: 34px;
      font-weight: 700;
      background: none !important;
      color: #111 !important;
      -webkit-text-fill-color: #111 !important;
      letter-spacing: 3px;
      text-transform: uppercase;
    }
    .admin-title img { height: 65px; }

    /* Main layout */
    .main {
      flex: 1;
      display: grid;
      grid-template-columns: 280px 1fr 280px;
      align-items: center;
      justify-items: center;
      padding: 120px 10px 10px 10px; /* top padding to avoid header overlap (header height ~100px) */
    }

    /* Back button */
    .back-btn {
      position: fixed;
      bottom: 35px;
      left: 55px;
      background: linear-gradient(180deg, #1e4e78, #2e75ac, #3aa3d1);
      color: #000000;
      padding: 10px 18px;
      border-radius: 10px;
      text-decoration: none;
      font-size: 14px;
      font-weight: bold;
      overflow: hidden;
      transition: transform 0.3s ease, color 0.3s ease;
      z-index: 9999;
    }

    .back-btn::before {
      content: "";
      position: absolute;
      top: 0;
      left: -100%;
      width: 200%;
      height: 100%;
      background: url("https://i.ibb.co/7k8vztp/wave-pattern.png") repeat-x;
      background-size: 50px 50px;
      opacity: 0.3;
      animation: wave 8s linear infinite;
      z-index: 0;
    }

    .back-btn:hover {
      transform: scale(1.08);
      color: #e0faff;
    }

    .back-btn:hover::before {
      animation-duration: 3s;
    }

    .sensor-box, .extra-box {
      background: #1e293b;
      border: 1px solid #334155;
      border-radius: 12px;
      padding: 20px;
      text-align: left;
      width: 100%;
      box-sizing: border-box;
      margin-bottom: 10px;
      height: auto;
    }
    .sensor-box p { margin: 6px 0; }
    h2 { margin: 0 0 12px; text-align: center; font-size: 16px; }

    /* Compass */
    .dial {
      width: 350px; height: 350px;
      border-radius: 50%;
      position: relative;
      margin: auto;
      background: #1e293b;
      border: 2px solid #334155;
      overflow: hidden;
    }
    #compassCard { transform-origin: 175px 175px; }
    .boat-rotating {
      position: absolute;
      top: 50%; left: 50%;
      transform: translate(-50%, -50%) rotate(0deg);
      width: 50px; height: 100px;
      transition: transform 0.2s linear;
      pointer-events: none;
    }
    .boat-rotating polygon {
      fill: none; stroke: #e2e8f0; stroke-width: 3;
    }
  /* Prominent heading readout placed below compass */
  .heading-readout { color: white; margin-top: 12px; font-size: 34px; font-weight: 800; text-align: center; letter-spacing: 0.6px; }

    /* Heading + Compass container */
    .heading-display { display:flex; flex-direction:column; align-items:center; justify-content:center; }
  .heading-display .heading-readout { margin-top: 12px; font-size: 34px; font-weight: 800; color: #ffffff; text-align:center; }

    /* Status bar */
    .status-bar {
      position: fixed;
      bottom: 0; left: 0; right: 0;
      display: flex;
      justify-content: center;
      gap: 12px;
      padding: 6px 0;
      font-size: 12px;
      color: #fff;
    }
    .status-item {
      background: #1e293b;
      border: 1px solid #334155;
      border-radius: 6px;
      padding: 4px 8px;
      min-width: 60px;
      text-align: center;
    }


    /* Controls panel */
    .panel {
      background: #1e293b;
      border: 1px solid #334155;
      border-radius: 12px;
      padding: 20px 18px 28px 18px;
      width: 75%;
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-start;
      gap: 22px;
      min-height: 580px; /* increased to provide room for lowered helm */
    }

    /* Telegraph */
      .telegraph {
        position: relative;
        width: 85px; height: 380px; /* adjusted telegraph height */
        min-height: 360px;
        background: #0f172a;
        border: 2px solid #334155;
        border-radius: 10px;
        margin-bottom: 10px; /* less space before helm */
        overflow: hidden; /* ensure fills are clipped */
        padding-top: 10px;
      }
    .scale {
      position: absolute;
      top: 0; left: 50%;
      transform: translateX(-50%);
      width: 4px; height: 100%;
      background: rgba(255,255,255,0.03); /* neutral track color */
      border-radius: 2px;
      z-index: 1;
    }
    .scale-fill {
      position: absolute;
      left: 50%;
      transform: translateX(-50%);
      width: 4px;
      top: 50%;
      height: 0%;
      background: #9ca3af;
      border-radius: 2px;
      transition: top 0.18s ease, height 0.18s ease, background 0.18s ease;
      pointer-events: none;
      z-index: 2;
      box-sizing: border-box;
    }
    .throttle-percent { margin-top: 10px; font-size: 14px; color: #cfeff8; text-align:center; font-weight:700; }
    .lever {
      position: absolute;
      left: 50%;
      transform: translateX(-50%);
      width: 80px; height: 24px; min-height: 24px;
      background: #38bdf8;
      border-radius: 6px;
      text-align: center;
      font-size: 10px;
      font-weight: bold;
      color: #000;
      cursor: grab;
      user-select: none;
      transition: opacity 0.3s;
      top: 0; /* initial top, will be adjusted by JS */
      z-index: 3;
    }
    .lever.disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    /* Helm */
    .helm { display: flex; justify-content: center; gap: 28px; align-items: center; }
    .helm > div { display:flex; flex-direction:column; align-items:center; gap:8px; }
    .helm button {
      background: #0f172a;
      color: #e2e8f0;
      border: 1px solid #334155;
      border-radius: 14px;
      padding: 16px 24px;
      font-size: 24px;
      font-weight: 800;
      width: 72px;
      height: 62px;
      cursor: pointer;
      transition: transform 0.12s, border-color 0.12s, opacity 0.3s;
      display: flex; align-items: center; justify-content: center;
    }
    .helm button:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    .helm button:hover:not(:disabled) { border-color: #38bdf8; transform: scale(1.06); }
    .helm-label { text-align: center; margin-top: 6px; font-size: 12px; letter-spacing: 1px; }

    /* === Feeds indicator styles === */
    .feed-container {
      width: 100%;
      height: 18px;
      background: #334155;
      border-radius: 9px;
      overflow: hidden;
      margin-top: 10px;
    }
    .feed-bar {
      height: 100%;
      width: 0%;
      background: linear-gradient(90deg, #22c55e, #16a34a);
      transition: width 0.5s ease;
    }
  </style>
</head>
<body>
  <div class="bg-blur"></div>
  <div class="header">
    <div class="header-left">
      <img src="isu.png" alt="ISU Logo" height="65" width="65" class="isu-logo">
      <div class="system-title">W.A.V.E. CONTROLLER</div>
      <div class="admin-title"><img src="wave_logo2.png" alt="WAVE Logo"></div>
    </div>
    <!-- header-right removed as requested -->
  </div>

  <div class="main">
    <!-- Left: Sensors -->
    <div>
      <div class="sensor-box" id="sensors_controller">
        <h2>SENSORS</h2>
        <p style="margin:6px 0;">WQI: <span id="wqiValue">--</span></p>
        <p id="doDisplay" name="dissolve_sensor">DO (mg/L): --</p>
  <p id="turbidityDisplay" name="turbidity_sensor">Turbidity: --</p>
        <p id="ammoniaDisplay" name="ammonia_sensor">Ammonia (mg/L): --</p>
          <p id="phDisplay" name="ph_sensor">pH Level: --</p>
  <p id="tempDisplay" name="temperature_sensor">Temperature (°C): --</p>

        <!-- Raw/extra sensor list (populated dynamically) -->
        <div id="rawSensors" style="margin-top:10px;font-size:12px;color:#cbd5e1;">&nbsp;</div>

      </div>
      <!-- battery card removed per request -->
    </div>

    <!-- Center: Compass -->
    <section class="heading-display">
        <div class="dial">
          <svg id="compassSvg" width="350" height="350" viewBox="0 0 350 350" aria-hidden="true">
          <g id="compassCard">
            <circle cx="175" cy="175" r="165" fill="none" stroke="#3a3a3a" stroke-width="2"/>
            <g id="ticks"></g>
            <g id="labels"></g>
          </g>
        </svg>
        <div class="boat-rotating" id="boat" aria-hidden="true">
          <svg width="50" height="100" viewBox="0 0 40 80" aria-hidden="true">
            <polygon points="20,0 30,30 30,70 20,80 10,70 10,30"/>
          </svg>
        </div>
        </div>
        <div class="heading-readout" id="headingReadout">Heading: 0°</div>
    </section>

    <!-- Right: Controls -->
    <aside class="panel">
      <div class="telegraph" id="telegraph">
        <div class="scale"></div>
        <div class="scale-fill" id="scaleFill"></div>
        <div class="lever" id="lever">STOP</div>
      </div>
      <div id="telegraphPercent" class="throttle-percent">0%</div>
      <div class="helm">
        <div>
          <button id="port">&lt;</button>
          <div class="helm-label">PORTSIDE</div>
        </div>
        <div>
          <button id="starboard">&gt;</button>
          <div class="helm-label">STARBOARD</div>
        </div>
      </div>

      <!-- Feed indicator -->
        <!-- Remaining feeds removed - space reallocated to telegraph and controls -->
    </aside>
  </div>

  <!-- Fixed Status Bar -->
  <div class="status-bar">
    <div class="status-item" id="wifiStatus"><div>WIFI</div>--</div>
    <div class="status-item" id="gpsStatus"><div>GPS</div>--</div>
  <div class="status-item" id="caTemp"><div>CA TEMP</div>--</div>
    <div class="status-item" id="speedStatus"><div>SPEED</div>--</div>
    <div class="status-item" id="watchdogStatus"><div>WATCHDOG</div>--</div>
  </div>

  

  <!-- Redesigned Ocean/Glassmorphism Back Button -->
  <a href="<?php echo htmlspecialchars($backTarget, ENT_QUOTES, 'UTF-8'); ?>" class="back-btn-ocean" title="Back">
    <i class="fas fa-arrow-left"></i>
    <span>Back</span>
  </a>
  <style>
  .back-btn-ocean {
    position: fixed;
    bottom: 38px;
    left: 48px;
    z-index: 1200;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 18px;
    font-size: 14px;
    font-weight: bold;
    color: #000000;
    background: linear-gradient(90deg, #a7ffeb 0%, #40c4ff 60%, #00bcd4 100%);
    border-radius: 999px;
    border: 2px solid #00bcd4;
    box-shadow: 0 4px 18px 0 #40c4ff22;
    backdrop-filter: blur(12px) saturate(140%) brightness(1.06);
    -webkit-backdrop-filter: blur(12px) saturate(140%) brightness(1.06);
    text-decoration: none;
    cursor: pointer;
    overflow: hidden;
    transition: background 0.25s, color 0.25s, box-shadow 0.25s;
  }
  .back-btn-ocean:hover {
    background: linear-gradient(90deg, #40c4ff 0%, #a7ffeb 100%);
    color: #01579b;
    box-shadow: 0 8px 24px 0 #00bcd455;
  }
  .back-btn-ocean i {
    font-size: 1.35em;
    color: #00bcd4;
    filter: drop-shadow(0 2px 8px #40c4ff33);
  }
  </style>


  <script>
    // run after DOM ready to avoid initialization/order issues
    document.addEventListener('DOMContentLoaded', () => {
// --- Telegraph / Lever logic ---
const telegraph = document.getElementById('telegraph');
const lever = document.getElementById('lever');
const scaleFill = document.getElementById('scaleFill');
const percentEl = document.getElementById('telegraphPercent');
const speeds = ['FULL AHEAD','HALF AHEAD','SLOW AHEAD','DEAD SLOW AHEAD','STOP','DEAD SLOW ASTERN','SLOW ASTERN','HALF ASTERN','FULL ASTERN'];
let currentIndex = speeds.indexOf('STOP');
let lastSentTelegraphIndex = null; // avoid sending duplicate messages
if (telegraph && lever) {
  function setLeverToIndex(i, retryCount = 0) {
    const rect = telegraph.getBoundingClientRect();
    const leverHeight = lever.offsetHeight;
    const rows = speeds.length;
    if ((!rect.height || !leverHeight) && retryCount < 20) {
      window.requestAnimationFrame(() => setLeverToIndex(i, retryCount + 1));
      return;
    }
    if (rect.height && leverHeight) {
      const rowHeight = rect.height / rows;
      const top = i * rowHeight + (rowHeight - leverHeight) / 2;
      lever.style.top = `${top}px`;
      lever.textContent = speeds[i];
      currentIndex = i;
        // update visual fill and percent display
        updateTelegraphVisual(i);
        // send telegraph message when index changes
        try {
          const msg = indexToTelegraphMsg(i);
          if (lastSentTelegraphIndex !== i && msg) {
            lastSentTelegraphIndex = i;
            // fire-and-forget; log success/failure in console
            sendPcMessage(msg).then(ok => console.log('[Telegraph] sent', msg, ok)).catch(e => console.warn('[Telegraph] send error', e));
          }
        } catch (e) {
          console.warn('telegraph send error', e);
        }
    }
  }
  function forceLeverToStop() {
    const stopIndex = speeds.indexOf('STOP');
    setLeverToIndex(stopIndex);
  }
  setTimeout(forceLeverToStop, 100);
  window.addEventListener('resize', () => setTimeout(forceLeverToStop, 100));
  // pointer-based drag for lever (works for mouse + touch)
  let dragging = false;
  let pointerId = null;
  lever.addEventListener('pointerdown', (ev) => {
    dragging = true;
    pointerId = ev.pointerId;
    lever.setPointerCapture(pointerId);
  });
  lever.addEventListener('pointerup', (ev) => {
    if (ev.pointerId !== pointerId) return;
    dragging = false;
    try { lever.releasePointerCapture(pointerId); } catch(e) {}
    pointerId = null;
  });
  lever.addEventListener('pointercancel', () => {
    dragging = false;
    pointerId = null;
  });
  lever.addEventListener('pointermove', (e) => {
    if (!dragging) return;
    const rect = telegraph.getBoundingClientRect();
    let y = e.clientY - rect.top;
    y = Math.max(0, Math.min(y, rect.height));
    const row = Math.floor((y / rect.height) * speeds.length);
    const idx = Math.max(0, Math.min(speeds.length - 1, row));
    setLeverToIndex(idx);
  });
}
// map index to percent (positive=AHEAD, negative=ASTERN)
function indexToPercent(i) {
  switch(i) {
    case 0: return {percent:100, dir:'AHEAD'}; // FULL AHEAD
    case 1: return {percent:50, dir:'AHEAD'};  // HALF AHEAD
    case 2: return {percent:25, dir:'AHEAD'};  // SLOW AHEAD
    case 3: return {percent:15, dir:'AHEAD'};  // DEAD SLOW AHEAD
    case 4: return {percent:0, dir:'STOP'};    // STOP
    case 5: return {percent:15, dir:'ASTERN'}; // DEAD SLOW ASTERN
    case 6: return {percent:25, dir:'ASTERN'}; // SLOW ASTERN
    case 7: return {percent:50, dir:'ASTERN'}; // HALF ASTERN
    case 8: return {percent:100, dir:'ASTERN'}; // FULL ASTERN
    default: return {percent:0, dir:'STOP'};
  }
}

// map index to telegraph message string expected by PC
function indexToTelegraphMsg(i) {
  switch(i) {
    case 0: return '10:AHEAD:100'; // FULL AHEAD
    case 1: return '10:AHEAD:50';  // HALF AHEAD
    case 2: return '10:AHEAD:25';  // SLOW AHEAD
    case 3: return '10:AHEAD:15';  // DEAD SLOW AHEAD
    case 4: return '10:AHEAD:0';   // STOP -> AHEAD:0 per spec
    case 5: return '10:ASTERN:15'; // DEAD SLOW ASTERN
    case 6: return '10:ASTERN:25'; // SLOW ASTERN
    case 7: return '10:ASTERN:50'; // HALF ASTERN
    case 8: return '10:ASTERN:100';// FULL ASTERN
    default: return null;
  }
}

  function updateTelegraphVisual(i) {
  if (!telegraph || !scaleFill || !percentEl) return;
  const rect = telegraph.getBoundingClientRect();
  const centerY = rect.height / 2;
  const mapping = indexToPercent(i);
  const p = mapping.percent;
  // reset
  if (p === 0) {
    scaleFill.style.height = '0px';
    scaleFill.style.top = `${centerY}px`;
    scaleFill.style.background = '#9ca3af';
    percentEl.textContent = '0%';
    return;
  }
  // Use half-height as the max distance (fills occupy either upper half or lower half)
  const half = Math.floor(rect.height / 2);
  const fillPx = Math.round((p / 100) * half);
  if (mapping.dir === 'AHEAD') {
    // fill upwards from center (cap at top)
    const topPx = Math.max(0, Math.round(centerY - fillPx));
    scaleFill.style.top = `${topPx}px`;
    scaleFill.style.height = `${fillPx}px`;
    scaleFill.style.background = '#22c55e';
    percentEl.textContent = `${p}% AHEAD`;
  } else {
    // ASTERN: fill downwards from center (cap at bottom)
    const topPx = Math.round(centerY);
    scaleFill.style.top = `${topPx}px`;
    scaleFill.style.height = `${fillPx}px`;
    scaleFill.style.background = '#ef4444';
    percentEl.textContent = `${p}% ASTERN`;
  }
}
        // --- Compass logic ---
        const ticksG = document.getElementById('ticks');
const labelsG = document.getElementById('labels');
const headingReadout = document.getElementById('headingReadout');
const boat = document.getElementById('boat');
if (ticksG && labelsG && headingReadout && boat) {
  const cx = 175, cy = 175, rOuter = 165, rInner = 150;
  const cardinal = {0: "N", 90: "E", 180: "S", 270: "W"};
  function deg2rad(d) { return (d * Math.PI) / 180; }
  // create tick marks every 30 degrees
  for (let d = 0; d < 360; d += 30) {
    const rad = deg2rad(d);
    const x1 = cx + rOuter * Math.sin(rad), y1 = cy - rOuter * Math.cos(rad);
    const x2 = cx + rInner * Math.sin(rad), y2 = cy - rInner * Math.cos(rad);
    const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    line.setAttribute('x1', x1); line.setAttribute('y1', y1);
    line.setAttribute('x2', x2); line.setAttribute('y2', y2);
    line.setAttribute('stroke', '#999'); line.setAttribute('stroke-width', '2');
    ticksG.appendChild(line);
  }
  // cardinal labels
  const rLabel = 135;
  for (let deg in cardinal) {
    const rad = deg2rad(deg);
    const lx = cx + rLabel * Math.sin(rad), ly = cy - rLabel * Math.cos(rad);
    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    text.setAttribute('x', lx.toFixed(2)); text.setAttribute('y', ly.toFixed(2));
    text.setAttribute('fill', '#cfcfcf'); text.setAttribute('font-size', '18');
    text.setAttribute('font-weight', 'bold');
    text.setAttribute('text-anchor', 'middle'); text.setAttribute('dominant-baseline', 'central');
    text.textContent = cardinal[deg]; labelsG.appendChild(text);
  }
}
  let heading = 0;
  let helmInterval = null;
  // IMU yaw forwarded from RPi (if available). When fresh, UI heading follows this value.
  let lastRpiYaw = null;
  let lastRpiYawTs = 0;
  // helm tap/hold helpers
  let helmPulseTimer = null; // timer that sends 10:STOP after a tap
  let helmPressStart = null; // timestamp when a UI press started
  const HELM_TAP_THRESHOLD = 350; // ms threshold to consider a press a tap
        function normalize(d) { d %= 360; if (d < 0) d += 360; return d; }
        function updateHeadingUI() {
          boat.style.transform = `translate(-50%,-50%) rotate(${heading}deg)`;
          headingReadout.textContent = `Heading: ${normalize(heading).toFixed(0)}°`;
        }
        const portBtn = document.getElementById('port');
        const starboardBtn = document.getElementById('starboard');

        function isRpiYawFresh() {
          try { return (lastRpiYaw !== null) && (Date.now() - lastRpiYawTs < 2500); } catch(e){ return false; }
        }

        function startHelm(direction) {
          // Do NOT locally animate heading. Heading is authoritative from the RPi IMU (YAW_REL_DEG).
          // Keep helmInterval cleared to avoid any visual movement from local actions.
          if (helmInterval) { clearInterval(helmInterval); helmInterval = null; }
          return;
        }

        function stopHelm() {
          if (helmInterval) { clearInterval(helmInterval); helmInterval = null; }
        }

        function scheduleHelmStopMessage(delay = 1000) {
          if (helmPulseTimer) clearTimeout(helmPulseTimer);
          helmPulseTimer = setTimeout(() => {
            sendPcMessage('10:STOP').then(ok => console.log('[PC → RPi] sent STOP', ok)).catch(e => console.warn('[PC → RPi] STOP error', e));
            helmPulseTimer = null;
          }, delay);
        }

        // UI pointer handlers: detect tap vs hold
        portBtn.addEventListener('pointerdown', (ev) => {
          helmPressStart = Date.now();
          try { ev.target.setPointerCapture(ev.pointerId); } catch (e) {}
          startHelm(-1);
        });
        portBtn.addEventListener('pointerup', (ev) => {
          const dur = helmPressStart ? (Date.now() - helmPressStart) : Infinity;
          try { ev.target.releasePointerCapture(ev.pointerId); } catch (e) {}
          stopHelm();
          if (dur < HELM_TAP_THRESHOLD) {
            // tap: single pulse then auto-stop after 1s
            sendPcMessage('10:PS').then(ok => console.log('[PC → RPi] sent PS', ok)).catch(e => console.warn('[PC → RPi] PS error', e));
            scheduleHelmStopMessage(2000);
          } else {
            // hold-release: ensure we notify STOP immediately
            sendPcMessage('10:STOP').then(ok => console.log('[PC → RPi] sent STOP', ok)).catch(e => console.warn('[PC → RPi] STOP error', e));
          }
          helmPressStart = null;
        });

        // Click handler: emulate a short tap for users who click (not press-and-hold)
        portBtn.addEventListener('click', (ev) => {
          // visual short pulse
          startHelm(-1);
          setTimeout(() => stopHelm(), 120);
          sendPcMessage('10:PS').then(ok => console.log('[PC → RPi] sent PS (click)', ok)).catch(e => console.warn('[PC → RPi] PS error', e));
          scheduleHelmStopMessage(2000);
        });

        // Note: keyboard activation on buttons removed; use Arrow keys or pointer/click only

        starboardBtn.addEventListener('pointerdown', (ev) => {
          helmPressStart = Date.now();
          try { ev.target.setPointerCapture(ev.pointerId); } catch (e) {}
          startHelm(+1);
        });
        starboardBtn.addEventListener('pointerup', (ev) => {
          const dur = helmPressStart ? (Date.now() - helmPressStart) : Infinity;
          try { ev.target.releasePointerCapture(ev.pointerId); } catch (e) {}
          stopHelm();
          if (dur < HELM_TAP_THRESHOLD) {
            sendPcMessage('10:SS').then(ok => console.log('[PC → RPi] sent SS', ok)).catch(e => console.warn('[PC → RPi] SS error', e));
            scheduleHelmStopMessage(1000);
          } else {
            sendPcMessage('10:STOP').then(ok => console.log('[PC → RPi] sent STOP', ok)).catch(e => console.warn('[PC → RPi] STOP error', e));
          }
          helmPressStart = null;
        });

        // Click handler for starboard
        starboardBtn.addEventListener('click', (ev) => {
          startHelm(+1);
          setTimeout(() => stopHelm(), 120);
          sendPcMessage('10:SS').then(ok => console.log('[PC → RPi] sent SS (click)', ok)).catch(e => console.warn('[PC → RPi] SS error', e));
          scheduleHelmStopMessage(1000);
        });

        // Note: keyboard activation on buttons removed; use Arrow keys or pointer/click only

        // ensure pointer cancels also stop helm
        window.addEventListener('pointerup', stopHelm);
        window.addEventListener('pointercancel', stopHelm);
        // keyboard controls for helm and telegraph
  // Client-side preserve token: when the UI sends a control (lever/helm),
  // hold the five status values (WIFI, GPS, CA TEMP, SPEED, WATCHDOG)
  // so they don't flicker or get replaced while the user is interacting.
  let preserveUntil = 0; // timestamp (ms) until which polling should not overwrite status items
  let preservedValues = {};

  // lastKnown stores the last-received value per logical key so we don't clear a field
  // when a later RPi message omits that key. Keys are canonical strings like 'lat','lon','wqi','ca_temp', etc.
  const lastKnown = {};

  // Persist lastKnown across page reloads so UI values survive refreshes
  const LAST_KNOWN_KEY = 'wave_lastKnown_v1';
  function loadLastKnownFromStorage() {
    try {
      const raw = localStorage.getItem(LAST_KNOWN_KEY);
      if (!raw) return;
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object') {
        Object.assign(lastKnown, parsed);
      }
    } catch (e) { /* ignore malformed storage */ }
  }
  function saveLastKnownToStorage() {
    try {
      localStorage.setItem(LAST_KNOWN_KEY, JSON.stringify(lastKnown));
    } catch (e) { /* ignore quota errors */ }
  }

  // Render UI from lastKnown values immediately (so refresh keeps previous values visible)
  function renderFromLastKnown() {
    try {
      // Sensors
      if (typeof lastKnown.wqi !== 'undefined') {
        const wEl = document.getElementById('wqiValue'); if (wEl) wEl.textContent = String(lastKnown.wqi);
      }
      if (typeof lastKnown.do !== 'undefined') document.getElementById('doDisplay').textContent = `DO (mg/L): ${lastKnown.do}`;
      if (typeof lastKnown.turbidity !== 'undefined') {
        const tv = parseFloat(lastKnown.turbidity);
        const turbText = (Number.isFinite(tv)) ? tv.toFixed(1) : String(lastKnown.turbidity);
        document.getElementById('turbidityDisplay').textContent = `Turbidity: ${turbText}`;
      }
      if (typeof lastKnown.ammonia !== 'undefined') document.getElementById('ammoniaDisplay').textContent = `Ammonia (mg/L): ${lastKnown.ammonia}`;
      if (typeof lastKnown.ph !== 'undefined') document.getElementById('phDisplay').textContent = `pH Level: ${lastKnown.ph}`;
      if (typeof lastKnown.temp !== 'undefined') {
        const tv2 = parseFloat(lastKnown.temp);
        const tmpText = (Number.isFinite(tv2)) ? tv2.toFixed(2) : String(lastKnown.temp);
        document.getElementById('tempDisplay').textContent = `Temperature (°C): ${tmpText}`;
      }
  // batteryDisplay removed; no-op

      // CA TEMP card
      const caTempEl = document.getElementById('caTemp');
      if (caTempEl && typeof lastKnown.ca_temp !== 'undefined') {
        const v = parseFloat(lastKnown.ca_temp);
        if (!Number.isNaN(v)) caTempEl.innerHTML = `<div>CA TEMP</div>${v.toFixed(1)}°C`;
      }

      // Status bar
      const wifiEl = document.getElementById('wifiStatus');
      if (wifiEl) {
        const r = lastKnown.rssi !== undefined ? `${lastKnown.rssi} dBm` : '';
        const q = lastKnown.wifiq !== undefined ? `${lastKnown.wifiq}` : '';
        if (r || q) wifiEl.innerHTML = `<div>WIFI</div>${(r + ' ' + q).trim()}`;
      }
      const gpsEl = document.getElementById('gpsStatus');
      if (gpsEl) {
        // Prefer numeric lat/lon from lastKnown and show on two lines.
        const latVal = (typeof lastKnown.lat !== 'undefined') ? parseFloat(lastKnown.lat) : NaN;
        const lonVal = (typeof lastKnown.lon !== 'undefined') ? parseFloat(lastKnown.lon) : NaN;
        if (!Number.isNaN(latVal) && !Number.isNaN(lonVal)) {
          gpsEl.innerHTML = `<div>GPS</div><div>LAT: ${latVal.toFixed(6)}</div><div>LON: ${lonVal.toFixed(6)}</div>`;
        } else if (!Number.isNaN(latVal)) {
          gpsEl.innerHTML = `<div>GPS</div><div>LAT: ${latVal.toFixed(6)}</div><div>LON: --</div>`;
        } else if (!Number.isNaN(lonVal)) {
          gpsEl.innerHTML = `<div>GPS</div><div>LAT: --</div><div>LON: ${lonVal.toFixed(6)}</div>`;
        } else if (lastKnown.gps_status) {
          // If no coords but a textual GPS status is present, show it (but strip long parenthetical noise)
          const gs = String(lastKnown.gps_status).replace(/\(.*\)/, '').trim();
          gpsEl.innerHTML = `<div>GPS</div>${gs}`;
        } else {
          gpsEl.innerHTML = `<div>GPS</div>--`;
        }
      }
      const speedEl = document.getElementById('speedStatus');
      if (speedEl) {
        if (typeof lastKnown.speed_kmh !== 'undefined') speedEl.innerHTML = `<div>SPEED</div>${parseFloat(lastKnown.speed_kmh).toFixed(2)} km/h`;
        else if (typeof lastKnown.speed_kn !== 'undefined') speedEl.innerHTML = `<div>SPEED</div>${(parseFloat(lastKnown.speed_kn) * 1.852).toFixed(2)} km/h`;
      }
      const wdEl = document.getElementById('watchdogStatus');
      console.log('[DEBUG] Updating WATCHDOG status:', lastKnown);
      if (wdEl && typeof lastKnown.watchdog !== 'undefined') {
        console.log('[DEBUG] Updating WATCHDOG status to OK in UI');
        wdEl.innerHTML = `<div>WATCHDOG</div>OK`;
      } else {
        console.error('[DEBUG] WATCHDOG status not found in server response');
      }
    } catch (e) { /* non-fatal */ }
  }

        // Helper: log watchdog trigger event to notification system
        function logWatchdogTrigger() {
          try {
            const xhr = new XMLHttpRequest();
            xhr.open("POST", "ad_dashboard.php", true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            const payload = [
              'log_to_event_log=1',
              'user=SYSTEM',
              'role=CONTROLLER',
              'ts=' + encodeURIComponent(Date.now()),
              'desc=' + encodeURIComponent('Watchdog trigger'),
              'status=ALARM'
            ];
            xhr.send(payload.join('&'));
            console.log('[Controller] Logged watchdog trigger to notification system');
          } catch(e) {
            console.error('[Controller] Failed to log watchdog trigger:', e);
          }
        }

        // Helper: send a PC-originated message to Flask server (/send_from_pc)
        const FLASK_CANDIDATES = [
          'http://192.168.0.2:5000',
          'http://192.168.0.3:5000',
          'http://localhost:5000'
        ];

        async function sendPcMessage(msg) {
          // When sending a control message, activate the preserve token for a short time
          try {
            const now = Date.now();
            const holdMs = 5000; // keep values for 5s while control in progress
            preserveUntil = now + holdMs;
            // snapshot current visible values so we can restore if poll overwrites
            preservedValues = {
              wifi: (document.getElementById('wifiStatus') || {innerHTML:''}).innerHTML,
              gps: (document.getElementById('gpsStatus') || {innerHTML:''}).innerHTML,
              caTemp: (document.getElementById('caTemp') || {innerHTML:''}).innerHTML,
              speed: (document.getElementById('speedStatus') || {innerHTML:''}).innerHTML,
              watchdog: (document.getElementById('watchdogStatus') || {innerHTML:''}).innerHTML
            };
          } catch (e) { /* non-fatal */ }
          // First try same-origin proxy to avoid CORS issues
          try {
            const proxyRes = await fetch('pc_proxy.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ message: msg })
            });
            const text = await proxyRes.text().catch(()=>null);
            if (proxyRes.ok) {
              console.log('[PC → RPi] sent via proxy:', msg, 'response:', proxyRes.status, text);
              return true;
            }
            console.warn('[PC → RPi] proxy returned', proxyRes.status, text);
          } catch (e) {
            console.warn('[PC → RPi] proxy error', e);
          }

          // Fallback: try direct Flask candidates (may be blocked by CORS)
          for (const base of FLASK_CANDIDATES) {
            try {
              const controller = new AbortController();
              const timeout = setTimeout(() => controller.abort(), 1800);
              const res = await fetch(base + '/send_from_pc', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: msg }),
                signal: controller.signal
              });
              clearTimeout(timeout);
              if (res.ok) {
                console.log('[PC → RPi] sent:', msg, 'via', base);
                return true;
              }
            } catch (err) {
              // try next candidate
            }
          }
          console.warn('[PC → RPi] failed to send message to any Flask candidate:', msg);
          return false;
        }

        window.addEventListener('keydown', (e) => {
          if (e.key === 'ArrowLeft') {
            // start helm turning
            if (!e.repeat) {
              // initial press = tap or hold start
              startHelm(-1);
              // schedule STOP in case user only tapped
              scheduleHelmStopMessage(1000);
              // send the PS command once on initial press
              sendPcMessage('10:PS').then(ok => console.log('[PC → RPi] sent PS', ok)).catch(() => {});
            } else {
              // repeated keydown: continue turning; clear any scheduled stop
              if (helmPulseTimer) { clearTimeout(helmPulseTimer); helmPulseTimer = null; }
            }
          }
          if (e.key === 'ArrowRight') {
            if (!e.repeat) {
              startHelm(+1);
              scheduleHelmStopMessage(1000);
              sendPcMessage('10:SS').then(ok => console.log('[PC → RPi] sent SS', ok)).catch(() => {});
            } else {
              if (helmPulseTimer) { clearTimeout(helmPulseTimer); helmPulseTimer = null; }
            }
          }
          if (e.key === 'ArrowUp') {
            // move lever up (toward FULL AHEAD)
            const ni = Math.max(0, currentIndex - 1);
            setLeverToIndex(ni);
          }
          if (e.key === 'ArrowDown') {
            // move lever down (toward FULL ASTERN)
            const ni = Math.min(speeds.length - 1, currentIndex + 1);
            setLeverToIndex(ni);
          }
        });
                window.addEventListener('keyup', (e) => {
                  if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                    // on key release, stop turning and send STOP immediately
                    stopHelm();
                    if (helmPulseTimer) { clearTimeout(helmPulseTimer); helmPulseTimer = null; }
                        sendPcMessage('10:STOP').then(ok => console.log('[PC → RPi] sent STOP', ok)).catch(e => console.warn('[PC → RPi] STOP error', e));
                      }
                    });

            // --- CA temperature polling from Server_PC.py ---
            // Polls fetch_sensors.php which proxies to the Flask Server_PC /get endpoint
            const caTempEl = document.getElementById('caTemp');
            async function pollCaTemp() {
              try {
                const res = await fetch('fetch_sensors.php', { cache: 'no-store' });
                if (!res.ok) throw new Error('fetch failed ' + res.status);
                const data = await res.json();
                
                // Accept multiple shapes: { from, message }, or { rpi: {...} }, or raw dict
                let msg = null;
                if (!data) msg = null;
                else if (data.message) msg = data.message;
                else if (data.rpi) msg = data.rpi;
                else msg = data;

                // Helper to read fields case-insensitively
                function getField(o, names) {
                  if (!o) return undefined;
                  for (const n of names) {
                    if (typeof o[n] !== 'undefined') return o[n];
                    const up = n.toUpperCase();
                    for (const k of Object.keys(o)) {
                      if (k.toUpperCase() === up) {
                        return o[k];
                      }
                    }
                  }
                  return undefined;
                }

                // CA temp (IMU_TEMP_C or IMU_TEMP or TEMP)
                const tval = getField(msg, ['IMU_TEMP_C', 'IMU_TEMP', 'TEMP', 'ca_temp']);
                if (typeof tval !== 'undefined' && tval !== null && tval !== '') {
                  lastKnown.ca_temp = tval;
                  // save update to storage
                  saveLastKnownToStorage();
                }
                // IMU / Fusion heading: accept many common keys from the RPi message
                try {
                  // prefer explicit yaw keys
                  const headingCandidates = ['YAW_REL_DEG','yaw_rel_deg','HEADING_FUSED_DEG','HEADING_FUSED','HEADING_DEG','HEADING','heading_fused_deg','heading_fused'];
                  let found = undefined;
                  for (const k of headingCandidates) {
                    const v = getField(msg, [k]);
                    if (typeof v !== 'undefined' && v !== null && String(v).toString().trim() !== '') { found = v; break; }
                  }

                  // also check nested containers often used by different producers
                  if (typeof found === 'undefined' && msg) {
                    const containers = ['IMU','FUSION','FUSION_KF','FUSIONKF'];
                    for (const c of containers) {
                      if (msg[c]) {
                        for (const k of headingCandidates) {
                          if (typeof msg[c][k] !== 'undefined' && msg[c][k] !== null && String(msg[c][k]).trim() !== '') { found = msg[c][k]; break; }
                        }
                        if (typeof found !== 'undefined') break;
                      }
                    }
                  }

                  // helper to sanitize numeric values (strip degree symbol and other noise)
                  function parseHeadingRaw(x) {
                    if (typeof x === 'number') return x;
                    if (typeof x !== 'string') x = String(x);
                    x = x.replace(/[^0-9+\-\.]/g, '');
                    if (x === '' || x === null) return NaN;
                    return Number(x);
                  }

                  const imuYaw = (typeof found !== 'undefined') ? parseHeadingRaw(found) : NaN;
                  if (!Number.isNaN(imuYaw)) {
                    lastRpiYaw = imuYaw;
                    lastRpiYawTs = Date.now();
                    // Immediately reflect IMU heading in UI (heading uses degrees, 0-360)
                    heading = imuYaw;
                    // persist a canonical heading value so other logic can reuse it
                    try { lastKnown.heading = imuYaw; saveLastKnownToStorage(); } catch(e){}
                    try { updateHeadingUI(); } catch(e) {}
                  }
                } catch(e) { /* tolerant */ }
                // Render CA temp from lastKnown if available
                if (typeof lastKnown.ca_temp !== 'undefined' && lastKnown.ca_temp !== null && lastKnown.ca_temp !== '') {
                  const v = parseFloat(lastKnown.ca_temp);
                  if (!Number.isNaN(v)) {
                    caTempEl.innerHTML = `<div>CA TEMP</div>${v.toFixed(1)}°C`;
                    if (v >= 60) { caTempEl.style.background = '#7f1d1d'; caTempEl.style.color = '#fff7f7'; }
                    else if (v >= 40) { caTempEl.style.background = '#b45309'; caTempEl.style.color = ''; }
                    else { caTempEl.style.background = ''; caTempEl.style.color = ''; }
                  } else {
                    caTempEl.innerHTML = `<div>CA TEMP</div>--`;
                    caTempEl.style.background = '';
                    caTempEl.style.color = '';
                  }
                }

                // Update other sensor cards if available
                const wqi = getField(msg, ['WQI', 'wqi']);
                const doV = getField(msg, ['DO', 'do', 'DISSOLVED_OXYGEN', 'DO_MGL', 'DO_MG_L', 'DO_MG/L', 'DO_MG']);
                const turb = getField(msg, ['NTU_VALUE','NTU','TURB','TURBIDITY','turbidity']);
                // Accept NH3_PPM and other common ammonia key variants
                const ammo = getField(msg, ['NH3_PPM','NH3','AMMO', 'AMMONIA', 'ammonia']);
                const ammoStatus = getField(msg, ['NH3_STATUS','AMMO_STATUS','AMMONIA_STATUS','NH3_STATUS_MSG']);
                const ph = getField(msg, ['PH', 'PH_LEVEL', 'PH_VAL', 'pH']);
                const temp = getField(msg, ['TEMP_C','IMU_TEMP_C','TEMP','temperature']);
                // battery data removed from controller UI; not collected here
                const batt = undefined;

                try { if (typeof wqi !== 'undefined') { lastKnown.wqi = wqi; saveLastKnownToStorage(); } } catch(e){}
                try { if (typeof doV !== 'undefined') { lastKnown.do = doV; saveLastKnownToStorage(); } } catch(e){}
                // DO status from message intentionally ignored on controller display
                try { if (typeof turb !== 'undefined') { lastKnown.turbidity = turb; saveLastKnownToStorage(); } } catch(e){}
                try {
                  if (typeof ammo !== 'undefined') {
                    // store as a formatted string with two decimals when numeric
                    const a = parseFloat(ammo);
                    lastKnown.ammonia = Number.isFinite(a) ? a.toFixed(2) : String(ammo);
                    saveLastKnownToStorage();
                  }
                } catch(e){}
                try { if (typeof ammoStatus !== 'undefined') { lastKnown.ammonia_status = ammoStatus; saveLastKnownToStorage(); } } catch(e){}
                try { if (typeof ph !== 'undefined') { lastKnown.ph = ph; saveLastKnownToStorage(); } } catch(e){}
                // PH status from message intentionally ignored on controller display
                try { if (typeof temp !== 'undefined') { lastKnown.temp = temp; saveLastKnownToStorage(); } } catch(e){}
                // battery persistence removed

                // Render sensor displays from lastKnown so values persist even if a message omits a field
                try {
                  // --- WQI rendering: prefer server-provided WQI, otherwise compute from available values (monitoring-tab logic) ---
                  function parseNum(v){ if (v === null || typeof v === 'undefined' || v === '') return NaN; const n = Number(String(v).toString().trim()); return Number.isFinite(n) ? n : NaN; }
                  function clamp(n, a, b){ return Math.max(a, Math.min(b, n)); }
                  function scorePH(pH){ if (!Number.isFinite(pH)) return null; const q = 100 - Math.abs(pH - 7) * 66.6666667; return clamp(Math.round(q * 10) / 10, 0, 100); }
                  function scoreDO(d){ if (!Number.isFinite(d)) return null; const q = (d / 8) * 100; return clamp(Math.round(q * 10) / 10, 0, 100); }
                  function scoreTurb(t){ if (!Number.isFinite(t)) return null; const q = 100 - (t / 25) * 100; return clamp(Math.round(q * 10) / 10, 0, 100); }
                  function scoreNH3(nh3){ if (!Number.isFinite(nh3)) return null; const q = 100 - (nh3 / 0.5) * 100; return clamp(Math.round(q * 10) / 10, 0, 100); }
                  function scoreTemp(t){ if (!Number.isFinite(t)) return null; const q = 100 - Math.abs(t - 25) * 2; return clamp(Math.round(q * 10) / 10, 0, 100); }
                  function computeWQIFromValues(vals){ const weights = { PH:0.2, DO:0.3, TURB:0.2, AMMO:0.2, TEMP:0.1 }; const qi = {}; qi.PH = Number.isFinite(vals.PH) ? scorePH(vals.PH) : null; qi.DO = Number.isFinite(vals.DO) ? scoreDO(vals.DO) : null; qi.TURB = Number.isFinite(vals.TURB) ? scoreTurb(vals.TURB) : null; qi.AMMO = Number.isFinite(vals.AMMO) ? scoreNH3(vals.AMMO) : null; qi.TEMP = Number.isFinite(vals.TEMP) ? scoreTemp(vals.TEMP) : null; let weightedSum = 0; let weightSum = 0; Object.keys(weights).forEach(k => { if (qi[k] !== null && typeof qi[k] !== 'undefined') { weightedSum += qi[k] * weights[k]; weightSum += weights[k]; } }); if (weightSum <= 0) return null; return Math.round((weightedSum / weightSum) * 10) / 10; }
                  function wqiStatusLabel(wqi){ if (!Number.isFinite(wqi)) return ''; if (wqi >= 90) return 'Excellent'; if (wqi >= 70) return 'Good'; if (wqi >= 50) return 'Medium'; if (wqi >= 25) return 'Poor'; return 'Very Poor'; }

                  const doVal = getField(msg, ['DO','DISSOLVED_OXYGEN','DO_MGL','DO_MG_L','DO_MG/L','DO_MG']);
                  const phVal = getField(msg, ['PH','PH_LEVEL','PH_VAL','pH']);
                  const wqiServer = getField(msg, ['WQI','WQI_VALUE','WATER_QUALITY_INDEX','WATER_QUALITY']);

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
                    const lkRaw = localStorage.getItem('wave_lastKnown_v1');
                    let lk = {};
                    try { lk = lkRaw ? JSON.parse(lkRaw) : {}; } catch(e){ lk = {}; }
                    const fallback = v => { const n = parseNum(v); return Number.isFinite(n) ? n : NaN; };
                    const vals = {
                      PH: Number.isFinite(parsedPH) ? parsedPH : fallback(lk.ph) || fallback(lk.PH) || NaN,
                      DO: Number.isFinite(parsedDO) ? parsedDO : fallback(lk.do) || fallback(lk.DO) || NaN,
                      TURB: Number.isFinite(parsedTurb) ? parsedTurb : fallback(lk.turbidity) || fallback(lk.TURB) || NaN,
                      AMMO: Number.isFinite(parsedAmmo) ? parsedAmmo : (function(){ const a = lk && lk.ammonia ? Number(String(lk.ammonia).replace(/[^0-9\.\-]/g,'')) : NaN; return Number.isFinite(a)?a:NaN; })(),
                      TEMP: Number.isFinite(parsedTemp) ? parsedTemp : fallback(lk.temperature) || fallback(lk.TEMP) || NaN
                    };
                    const computed = computeWQIFromValues(vals);
                    if (Number.isFinite(computed)) {
                      finalWqi = Math.round(computed * 10) / 10;
                      finalWqiStatus = wqiStatusLabel(finalWqi);
                    }
                  }

                  // render and persist into lastKnown
                  const wqiEl = document.getElementById('wqiValue');
                  if (wqiEl) {
                    if (finalWqi === null || typeof finalWqi === 'undefined' || !Number.isFinite(finalWqi)) wqiEl.textContent = '--'; else wqiEl.textContent = (finalWqi).toFixed(1);
                  }
                  if (finalWqi !== null && Number.isFinite(finalWqi)) { lastKnown.wqi = (finalWqi).toFixed(1); saveLastKnownToStorage(); }
                } catch(e) {}
                try { if (typeof lastKnown.do !== 'undefined') document.getElementById('doDisplay').textContent = `DO (mg/L): ${lastKnown.do}`; } catch(e){}
                // DO status element removed from controller UI; nothing to render
                try {
                  if (typeof lastKnown.turbidity !== 'undefined') {
                    const tv = parseFloat(lastKnown.turbidity);
                    const turbText = (Number.isFinite(tv)) ? tv.toFixed(1) : String(lastKnown.turbidity);
                    document.getElementById('turbidityDisplay').textContent = `Turbidity: ${turbText}`;
                  }
                } catch(e){}
                try { if (typeof lastKnown.ammonia !== 'undefined') document.getElementById('ammoniaDisplay').textContent = `Ammonia (mg/L): ${lastKnown.ammonia}`; } catch(e){}
                try { if (typeof lastKnown.ph !== 'undefined') document.getElementById('phDisplay').textContent = `pH Level: ${lastKnown.ph}`; } catch(e){}
                // PH status element removed from controller UI; nothing to render
                try {
                  if (typeof lastKnown.temp !== 'undefined') {
                    const tv2 = parseFloat(lastKnown.temp);
                    const tmpText = (Number.isFinite(tv2)) ? tv2.toFixed(2) : String(lastKnown.temp);
                    document.getElementById('tempDisplay').textContent = `Temperature (°C): ${tmpText}`;
                  }
                } catch(e){}
                // batteryDisplay removed from DOM; nothing to render

                // We intentionally DO NOT populate debug/raw keys inside the SENSORS card.
                // The sensors card should only show the canonical water-quality sensors.
                try {
                  const rawEl = document.getElementById('rawSensors');
                  if (rawEl) rawEl.innerHTML = '&nbsp;';
                } catch (e) { /* ignore */ }

                // --- Update status bar items (WIFI, GPS, SPEED, WATCHDOG)
                const rssi = getField(msg, ['RSSI_DBM', 'RSSI', 'rssi_dbm']);
                const wifiq = getField(msg, ['WIFI_QUALITY', 'WIFI_QUAL', 'wifi_quality']);
                const lat = getField(msg, ['LAT', 'lat']);
                const lon = getField(msg, ['LON', 'lon']);
                const speedKmh = getField(msg, ['SPEED_KMH', 'SPEED_KM_H', 'speed_kmh']);
                const speedKn = getField(msg, ['SPEED_KNOTS', 'SPEED_KNOT', 'speed_knots']);
                const gpsStatus = getField(msg, ['STATUS', 'GPS_STATUS', 'status']);
                const watchdog = getField(msg, ['WATCHDOG', 'watchdog', 'last_updated']);

                // Update lastKnown status values only when new values are present
                if (typeof rssi !== 'undefined') { lastKnown.rssi = rssi; saveLastKnownToStorage(); }
                if (typeof wifiq !== 'undefined') { lastKnown.wifiq = wifiq; saveLastKnownToStorage(); }
                if (typeof lat !== 'undefined') { lastKnown.lat = lat; saveLastKnownToStorage(); }
                if (typeof lon !== 'undefined') { lastKnown.lon = lon; saveLastKnownToStorage(); }
                if (typeof speedKmh !== 'undefined') { lastKnown.speed_kmh = speedKmh; saveLastKnownToStorage(); }
                if (typeof speedKn !== 'undefined') { lastKnown.speed_kn = speedKn; saveLastKnownToStorage(); }
                if (typeof gpsStatus !== 'undefined') { lastKnown.gps_status = gpsStatus; saveLastKnownToStorage(); }
                if (typeof watchdog !== 'undefined') { lastKnown.watchdog = watchdog; saveLastKnownToStorage(); }

                // WIFI display: show RSSI and quality where available. Prefer live values, else fall back to lastKnown.
                try {
                  const wifiEl = document.getElementById('wifiStatus');
                  if (wifiEl) {
                    // respect preserve token if active
                    if (typeof preserveUntil !== 'undefined' && Date.now() <= preserveUntil && preservedValues && preservedValues.wifi) {
                      wifiEl.innerHTML = preservedValues.wifi;
                    } else if (typeof rssi !== 'undefined' || typeof wifiq !== 'undefined' || typeof lastKnown.rssi !== 'undefined' || typeof lastKnown.wifiq !== 'undefined') {
                      const r = (typeof rssi !== 'undefined') ? rssi : lastKnown.rssi;
                      const q = (typeof wifiq !== 'undefined') ? wifiq : lastKnown.wifiq;
                      const rTxt = (typeof r !== 'undefined') ? `${r} dBm` : '';
                      const qTxt = (typeof q !== 'undefined') ? `${q}` : '';
                      wifiEl.innerHTML = `<div>WIFI</div>${(rTxt + ' ' + qTxt).trim()}`;
                    } else {
                      wifiEl.innerHTML = `<div>WIFI</div>--`;
                    }
                  }
                } catch(e){}

                // GPS display: lat,lon and status. Use lastKnown if current message omits lat/lon.
                try {
                  const gpsEl = document.getElementById('gpsStatus');
                  if (gpsEl) {
                    if (typeof preserveUntil !== 'undefined' && Date.now() <= preserveUntil && preservedValues && preservedValues.gps) {
                      gpsEl.innerHTML = preservedValues.gps;
                    } else if ((typeof lat !== 'undefined' && typeof lon !== 'undefined') || (typeof lastKnown.lat !== 'undefined' && typeof lastKnown.lon !== 'undefined')) {
                      const useLat = (typeof lat !== 'undefined') ? parseFloat(lat) : (typeof lastKnown.lat !== 'undefined' ? parseFloat(lastKnown.lat) : NaN);
                      const useLon = (typeof lon !== 'undefined') ? parseFloat(lon) : (typeof lastKnown.lon !== 'undefined' ? parseFloat(lastKnown.lon) : NaN);
                      if (!Number.isNaN(useLat) && !Number.isNaN(useLon)) {
                        gpsEl.innerHTML = `<div>GPS</div><div>LAT: ${useLat.toFixed(6)}</div><div>LON: ${useLon.toFixed(6)}</div>`;
                      } else if (!Number.isNaN(useLat)) {
                        gpsEl.innerHTML = `<div>GPS</div><div>LAT: ${useLat.toFixed(6)}</div><div>LON: --</div>`;
                      } else if (!Number.isNaN(useLon)) {
                        gpsEl.innerHTML = `<div>GPS</div><div>LAT: --</div><div>LON: ${useLon.toFixed(6)}</div>`;
                      } else {
                        gpsEl.innerHTML = `<div>GPS</div>--`;
                      }
                    } else if (gpsStatus || lastKnown.gps_status) {
                      const gs = gpsStatus ? String(gpsStatus).replace(/\(.*\)/,'').trim() : String(lastKnown.gps_status).replace(/\(.*\)/,'').trim();
                      gpsEl.innerHTML = `<div>GPS</div>${gs}`;
                    } else {
                      gpsEl.innerHTML = `<div>GPS</div>--`;
                    }
                  }
                } catch(e){}

                // SPEED display: prefer km/h, else convert from knots. Fall back to lastKnown when missing.
                try {
                  const speedEl = document.getElementById('speedStatus');
                  if (speedEl) {
                    if (typeof preserveUntil !== 'undefined' && Date.now() <= preserveUntil && preservedValues && preservedValues.speed) {
                      speedEl.innerHTML = preservedValues.speed;
                    } else {
                      // choose live value if present, otherwise use lastKnown
                      const useKmh = (typeof speedKmh !== 'undefined' && speedKmh !== null && speedKmh !== '') ? speedKmh : lastKnown.speed_kmh;
                      const useKn = (typeof speedKn !== 'undefined' && speedKn !== null && speedKn !== '') ? speedKn : lastKnown.speed_kn;
                      let display = '--';
                      if (typeof useKmh !== 'undefined' && useKmh !== null && useKmh !== '') {
                        display = `${parseFloat(useKmh).toFixed(2)} km/h`;
                      } else if (typeof useKn !== 'undefined' && useKn !== null && useKn !== '') {
                        const kmh = parseFloat(useKn) * 1.852;
                        if (!Number.isNaN(kmh)) display = `${kmh.toFixed(2)} km/h`;
                      }
                      speedEl.innerHTML = `<div>SPEED</div>${display}`;
                    }
                  }
                } catch(e){}

                // WATCHDOG display: show ALERT prominently, otherwise show a simple OK.
                // Removed relative "time ago" and any staleness color threshold per request.
                const wdEl = document.getElementById('watchdogStatus');
                if (wdEl) {
                  if (typeof preserveUntil !== 'undefined' && Date.now() <= preserveUntil && preservedValues && preservedValues.watchdog) {
                    wdEl.innerHTML = preservedValues.watchdog;
                  } else {
                    // Detect ALERT conditions in current message or lastKnown using tolerant lookup
                    const alertVal = (typeof getField === 'function') ? getField(msg, ['ALERT','alert']) : (msg && (msg.ALERT || msg.alert));
                    const unitVal = (typeof getField === 'function') ? getField(msg, ['UNIT_ID','unit_id','UNITID']) : (msg && (msg.UNIT_ID || msg.unit_id || msg.UNITID));
                    
                    // Store ALERT and UNIT_ID immediately when they arrive in the message
                    if (typeof alertVal !== 'undefined' && alertVal !== null) {
                      console.log('[Watchdog] ⚠️ ALERT received:', alertVal);
                      lastKnown.alert = alertVal;
                      saveLastKnownToStorage();
                    }
                    if (typeof unitVal !== 'undefined' && unitVal !== null) {
                      lastKnown.unit_id = unitVal;
                      saveLastKnownToStorage();
                    }
                    
                    const lastAlert = lastKnown.alert;
                    const lastUnit = lastKnown.unit_id || lastKnown.UNIT_ID;
                    let effectiveAlert = (typeof alertVal !== 'undefined') ? alertVal : lastAlert;
                    let effectiveUnit = (typeof unitVal !== 'undefined') ? unitVal : lastUnit;

                    const wdVal = (typeof watchdog !== 'undefined' && watchdog !== null && watchdog !== '') ? watchdog : lastKnown.watchdog;

                    // First check for explicit reset/clear messages (e.g. "Tamper Reset") or explicit WATCHDOG: "OK"
                    const infoVal = (typeof getField === 'function') ? getField(msg, ['INFO','info','MESSAGE','message']) : (msg && (msg.INFO || msg.info || msg.MESSAGE || msg.message));
                    const statusVal = (typeof getField === 'function') ? getField(msg, ['STATUS','status']) : (msg && (msg.STATUS || msg.status));
                    const infoUpper = (typeof infoVal !== 'undefined' && infoVal !== null) ? String(infoVal).toUpperCase() : '';
                    const statusUpper = (typeof statusVal !== 'undefined' && statusVal !== null) ? String(statusVal).toUpperCase() : '';
                    const wdUpper = (typeof wdVal !== 'undefined' && wdVal !== null) ? String(wdVal).toUpperCase() : '';

                    // Check if this message explicitly indicates OK status or reset
                    const isResetOrOK = infoUpper.match(/RESET|CLEAR|RESTORE/) || wdUpper === 'OK' || statusUpper.match(/SECURE|OK/);

                    if (isResetOrOK) {
                      console.log('[Watchdog] ✓ RESET detected - Clearing alert');
                      try { delete lastKnown.alert; delete lastKnown.unit_id; delete lastKnown.watchdog_alert_logged; saveLastKnownToStorage(); } catch(e){}
                      try { effectiveAlert = undefined; effectiveUnit = undefined; } catch (e) {}
                      wdEl.innerHTML = `<div>WATCHDOG</div>OK`;
                      wdEl.style.background = '';
                      wdEl.style.color = '';
                    } else {
                      // Determine if this is an ALERT case
                      let isAlert = false;
                      let alertReason = '';
                      
                      try {
                        if (typeof effectiveAlert !== 'undefined' && effectiveAlert !== null && String(effectiveAlert).toUpperCase().match(/WAVE|TAMPER/)) {
                          isAlert = true; alertReason = String(effectiveAlert);
                        }
                        if (!isAlert && typeof effectiveUnit !== 'undefined' && String(effectiveUnit).toUpperCase().match(/WATCHDOG|TAMPER/)) {
                          isAlert = true; alertReason = String(effectiveUnit);
                        }
                      } catch (e) { 
                        console.error('[Watchdog] Error:', e);
                      }

                      if (isAlert) {
                        console.log('[Watchdog] ⚠️ ALERT ACTIVE:', alertReason);
                        const reason = alertReason ? ` ${alertReason}` : '';
                        wdEl.innerHTML = `<div>WATCHDOG</div><strong style="color:#fff">ALERT</strong>${reason}`;
                        wdEl.style.background = '#7f1d1d'; wdEl.style.color = '#fff7f7';
                        
                        // Log the watchdog trigger event to the notification log (only once per alert)
                        try {
                          if (!lastKnown.watchdog_alert_logged) {
                            console.log('[Watchdog] Logging to notification system');
                            logWatchdogTrigger();
                            lastKnown.watchdog_alert_logged = true;
                            saveLastKnownToStorage();
                          }
                        } catch(e) { console.error('[Watchdog] Failed to log:', e); }
                      } else {
                        wdEl.innerHTML = `<div>WATCHDOG</div>OK`;
                        wdEl.style.background = '';
                        wdEl.style.color = '';
                      }
                    }
                  }
                }

              } catch (err) {
                // network or parse error — show offline/fallback
                try { caTempEl.innerHTML = `<div>CA TEMP</div>--`; caTempEl.style.background = ''; caTempEl.style.color = ''; } catch(e){}
                console.debug('CA temp poll error', err);
              }
            }

            // Load persisted lastKnown and render immediately so refresh keeps values
            try {
              loadLastKnownFromStorage();
              renderFromLastKnown();
            } catch (e) { /* ignore */ }

            // start polling every 0.5s for faster watchdog alert detection, resiliently (so earlier JS errors don't prevent poller start)
            try {
              setInterval(pollCaTemp, 500);
              // run once immediately
              pollCaTemp().catch(()=>{});
            } catch (e) {
              console.error('Failed to start sensor poller', e);
            }
          });
           </script>
</body>
</html>