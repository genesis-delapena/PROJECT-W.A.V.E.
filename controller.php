<?php
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
      padding: 10px;
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
    .heading-readout { color: white; margin-top: 8px; font-size: 16px; font-weight: bold; text-align: center; }

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
      padding: 18px;
      width: 75%;
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-start;
      gap: 18px;
      min-height: 520px; /* increased to occupy removed feeds space */
    }

    /* Telegraph */
      .telegraph {
        position: relative;
        width: 85px; height: 380px; /* taller telegraph for larger lever travel */
        min-height: 360px;
        background: #0f172a;
        border: 2px solid #334155;
        border-radius: 10px;
        margin-bottom: 8px;
        overflow: visible;
        padding-top: 10px;
      }
    .scale {
      position: absolute;
      top: 0; left: 50%;
      transform: translateX(-50%);
      width: 4px; height: 100%;
      background: linear-gradient(to bottom, #4ade80 0% 40%, #444 40% 60%, #e74c3c 60% 100%);
      border-radius: 2px;
    }
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
    }
    .lever.disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    /* Helm */
    .helm { display: flex; justify-content: center; gap: 25px; }
    .helm button {
      background: #0f172a;
      color: #e2e8f0;
      border: 1px solid #334155;
      border-radius: 8px;
      padding: 10px 18px;
      font-size: 20px;
      cursor: pointer;
      transition: transform 0.2s, border-color 0.2s, opacity 0.3s;
    }
    .helm button:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    .helm button:hover:not(:disabled) { border-color: #38bdf8; transform: scale(1.1); }
    .helm-label { text-align: center; margin-top: 4px; font-size: 10px; }

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
        <p id="wqiDisplay" name="wqi_value">WQI: --</p>
        <p id="doDisplay" name="dissolve_sensor">DO (mg/L): --</p>
        <p id="turbidityDisplay" name="turbidity_sensor">Turbidity: --</p>
        <p id="ammoniaDisplay" name="ammonia_sensor">Ammonia (mg/L): --</p>
        <p id="phDisplay" name="ph_sensor">pH Level: --</p>
        <p id="tempDisplay" name="temperature_sensor">Temperature (°C): --</p>

      </div>
      <div class="extra-box">
        <h2>Battery Percentage</h2>
        <p id="batteryDisplay">Additional data...</p>
      </div>
    </div>

    <!-- Center: Compass -->
    <section class="heading-display">
      <div class="heading-readout" id="headingReadout">Heading: 0°</div>
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
    </section>

    <!-- Right: Controls -->
    <aside class="panel">
      <div class="telegraph" id="telegraph">
        <div class="scale"></div>
        <div class="lever" id="lever">STOP</div>
      </div>
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
    <div class="status-item"><div>WIFI</div>--</div>
    <div class="status-item"><div>GPS</div>--</div>
    <div class="status-item"><div>SPEED</div>--</div>
    <div class="status-item"><div>WATCHDOG</div>--</div>
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
const speeds = ['FULL AHEAD','HALF AHEAD','SLOW AHEAD','DEAD SLOW AHEAD','STOP','DEAD SLOW ASTERN','SLOW ASTERN','HALF ASTERN','FULL ASTERN'];
let currentIndex = speeds.indexOf('STOP');
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
        function normalize(d) { d %= 360; if (d < 0) d += 360; return d; }
        function updateHeadingUI() {
          boat.style.transform = `translate(-50%,-50%) rotate(${heading}deg)`;
          headingReadout.textContent = `Heading: ${normalize(heading).toFixed(0)}°`;
        }
        const portBtn = document.getElementById('port');
        const starboardBtn = document.getElementById('starboard');
        function startHelm(direction) {
          if (helmInterval) clearInterval(helmInterval);
          helmInterval = setInterval(() => {
            heading += direction * 2; // direction = -1 or +1
            updateHeadingUI();
          }, 50);
        }
        function stopHelm() {
          if (helmInterval) { clearInterval(helmInterval); helmInterval = null; }
        }
        portBtn.addEventListener('pointerdown', () => startHelm(-1));
        starboardBtn.addEventListener('pointerdown', () => startHelm(+1));
        window.addEventListener('pointerup', stopHelm);
        window.addEventListener('pointercancel', stopHelm);
        // keyboard controls for helm
        window.addEventListener('keydown', (e) => {
          if (e.key === 'ArrowLeft') startHelm(-1);
          if (e.key === 'ArrowRight') startHelm(+1);
        });
        window.addEventListener('keyup', (e) => {
          if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') stopHelm();
        });
      });
  </script>
</body>
</html>