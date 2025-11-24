<?php
// feeder.php: honor caller context so it uses the correct named session when embedded
$from = isset($_GET['from']) ? strtolower($_GET['from']) : '';
if ($from === 'admin') {
  session_name('WAVE_ADMIN');
} elseif ($from === 'user') {
  session_name('WAVE_USER');
}
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Feed Logs Section</title>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
  /* Export PDF SweetAlert Modal - Match Clear Logs Design */
  .swal2-export-modal .swal2-html-container {
    width: 100%;
    padding: 0;
    margin: 0;
  }
  .swal2-export-modal .export-modal-fields {
    display: flex;
    flex-direction: column;
    gap: 16px;
    width: 100%;
    margin: 0 auto;
    max-width: 340px;
  }
  .swal2-export-modal .export-modal-row {
    display: flex;
    flex-direction: column;
    gap: 6px;
    width: 100%;
  }
  .swal2-export-modal label {
    font-weight: 700;
    color: #1976d2;
    font-size: 1em;
    margin-bottom: 2px;
    text-align: left;
    letter-spacing: 0.5px;
  }
  .swal2-export-modal input[type="date"],
  .swal2-export-modal input[type="time"] {
    padding: 10px 12px;
    border-radius: 7px;
    border: 1.5px solid #b3b3b3;
    font-size: 1em;
    background: #f8fafc;
    color: #222;
    margin-right: 0;
    margin-bottom: 0;
    box-sizing: border-box;
    outline: none;
    transition: border 0.2s;
  }
  .swal2-export-modal input[type="date"]:focus,
  .swal2-export-modal input[type="time"]:focus {
    border: 1.5px solid #1976d2;
    background: #fff;
    color: #222;
  }
  /* SweetAlert2 Export Modal Custom Styles */
  .swal2-export-modal {
    border-radius: 16px !important;
    padding-bottom: 18px !important;
    min-width: 340px !important;
  }
  .swal2-export-confirm {
    background: linear-gradient(90deg, #1976d2 0%, #4f8cff 100%) !important;
    color: #fff !important;
    font-weight: 700 !important;
    font-size: 1.08em !important;
    border-radius: 8px !important;
    margin-left: 8px !important;
    padding: 10px 24px !important;
  }
  .swal2-export-cancel {
    background: #e0e0e0 !important;
    color: #333 !important;
    font-weight: 600 !important;
    font-size: 1.08em !important;
    border-radius: 8px !important;
    margin-right: 8px !important;
    padding: 10px 24px !important;
  }
  /* Ensure SweetAlert modal is always on top of custom overlays */
  .swal2-container {
    z-index: 10050 !important;
  }
    html, body {
      height: 100vh;
      margin: 0;
      padding: 0;
      font-family: Arial, sans-serif;
      background: #f4f6f8;
      overflow: hidden;
    }
    body {
      margin: 0;
      padding: 20px;
      height: 100vh;
      box-sizing: border-box;
      overflow: hidden;
    }
    .pagination {
      display: flex;
      justify-content: center;
      align-items: center;
      margin-top: 10px;
      gap: 10px;
    }
    .pagination-btn {
      padding: 8px 22px;
      border: none;
      border-radius: 8px;
      background: #1976f7;
      color: #fff;
      font-weight: 700;
      font-size: 16px;
      cursor: pointer;
      box-shadow: 0 2px 6px rgba(25, 118, 247, 0.08);
      transition: background 0.2s, color 0.2s, box-shadow 0.2s;
    }
    .pagination-btn.active, .pagination-btn:not(:disabled):hover {
      background: #1565c0;
      color: #fff;
      box-shadow: 0 4px 12px rgba(25, 118, 247, 0.12);
    }
    .pagination-btn:disabled {
      background: #e0e0e0;
      color: #bdbdbd;
      cursor: not-allowed;
      box-shadow: none;
    }
    /* Digital clock style */
    .digital-clock-box {
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.10);
  padding: 6px 18px;
  font-size: 1.3em;
  font-family: monospace, Arial, sans-serif;
  font-weight: bold;
  color: #222;
  margin-bottom: 0;
  margin-top: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 140px;
  max-width: 260px;
  height: 36px;
  box-sizing: border-box;
    }
    .feed-container-box {
      background: #fff;
      border-radius: 8px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
      box-sizing: border-box;
      padding: 8px 12px 8px 12px;
      display: flex;
      flex-direction: row;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      gap: 12px;
      margin-bottom: 0;
    }
    .feed-container-section {
      display: flex;
      flex-direction: column;
      gap: 12px;
      flex: 1;
    }
    .feed-container-label {
      font-weight: bold;
      font-size: 1.1em;
      margin-bottom: 4px;
      letter-spacing: 1px;
      text-transform: uppercase;
    }
    .feed-container-row {
      display: flex;
      flex-direction: row;
      gap: 18px;
      margin-bottom: 8px;
    }
    .feed-container-item {
      background: #f8fafc;
      border-radius: 10px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      border: 1.5px solid #b3b3b3;
      padding: 10px 18px;
      font-weight: bold;
      font-size: 1.08em;
      color: #222;
      display: flex;
      align-items: center;
      min-width: 200px;
      max-width: 320px;
      gap: 10px;
    }
    .feed-container-item:not(:last-child) {
      margin-right: 10px;
    }
    .feed-controls-col {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 10px;
      min-width: 180px;
      justify-content: flex-start;
    }
    .mode-controls-vertical {
      width: 100%;
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 6px;
    }
    .mode-controls-vertical .mode-btn {
      width: 100%;
    }
    .feed-status-label {
      font-weight: bold;
      margin-right: 8px;
    }
    .feed-status-value {
      background: linear-gradient(90deg, #00e676 0%, #00c853 100%);
      color: #fff;
      font-weight: bold;
      border-radius: 16px;
      padding: 4px 18px;
      border: none;
      margin-left: 2px;
      font-size: 1.1em;
      letter-spacing: 1px;
      box-shadow: 0 2px 8px rgba(0,230,118,0.10);
      text-shadow: 0 1px 2px rgba(0,0,0,0.08);
      transition: background 0.2s, box-shadow 0.2s;
    }
  /* Status progress bar styles */
  #feederStatusBar { display:flex; align-items:center; }
  #feederStatusFill { width: 100%; height:100%; }
  /* Percent label is centered above the fill so it remains visible even at 0% */
  #feederStatusPercent { position:absolute; left:50%; top:50%; transform:translate(-50%,-50%); z-index:2; color:#000; }
  /* Default neutral background for the fill; JS will set gradient and width */
  #feederStatusFill.default { background: linear-gradient(90deg, #00e676 0%, #00c853 100%); }
    .mode-controls {
      display: flex;
      flex-direction: row;
      align-items: center;
      gap: 12px;
      margin-left: 24px;
    }
    .mode-label {
      font-weight: bold;
      font-size: 1em;
      margin-right: 6px;
      text-transform: uppercase;
    }
    .mode-btn, .export-btn, .clear-logs-btn {
      border: none;
      border-radius: 8px;
      font-weight: bold;
      font-size: 1em;
  padding: 6px 20px;
      background: linear-gradient(90deg, #4f8cff 0%, #1e90ff 100%);
      color: #fff;
      margin-bottom: 6px;
      margin-right: 0;
      cursor: pointer;
      outline: none;
      box-shadow: 0 2px 8px rgba(30,144,255,0.10);
      transition: background 0.2s, box-shadow 0.2s, transform 0.1s;
    }
    .mode-btn:hover, .export-btn:hover, .clear-logs-btn:hover {
      background: linear-gradient(90deg, #1e90ff 0%, #4f8cff 100%);
      color: #fff;
      box-shadow: 0 4px 16px rgba(30,144,255,0.18);
      transform: translateY(-2px) scale(1.03);
    }
    .clear-logs-btn {
      background: linear-gradient(90deg, #ffe066 0%, #ffd700 100%);
      color: #222;
    }
    .clear-logs-btn:hover {
      background: linear-gradient(90deg, #ffd700 0%, #ffe066 100%);
      color: #222;
    }
    .main-row {
  display: flex;
  flex-direction: row;
  gap: 8px;
  width: 100%;
  align-items: stretch;
  justify-content: flex-start;
  height: auto;
  padding-left: 12px;
}
#dispenseLogsCard.main-card {
  min-width: 57%;
  max-width: 57%;
  background: #fff;
  padding: 8px 4px 8px 10px;
  border-radius: 8px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1);
  box-sizing: border-box;
  flex: 1 1 0%;
  margin-left: 12px;
  margin-right: 0;
  align-self: flex-start;
  overflow-x: auto;
  max-width: calc(55% - 24px);
  height: 385px;
  display: flex;
  flex-direction: column;
  overflow-y: hidden;
}

#scheduleCard {
  min-width: 450px;
  max-width: 500px;
  background: #fff;
  padding: 8px 4px 8px 10px;
  border-radius: 8px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1);
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  gap: 4px;
  height: 110%;
}
#autoSection.main-card {
  min-width: 450px;
  max-width: 500px;
  margin-bottom: 0;
  margin-right: 0;
  margin-left: 0;
  margin-right: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
  height: 100%;
    }
      /* Make schedule table end at Add button */
      #scheduleTable {
  max-width: 472px;
        margin-right: auto;
      }
    .main-card-header {
      font-weight: bold;
      font-size: 1.08em;
      margin-bottom: 8px;
      text-transform: uppercase;
      letter-spacing: 1px;
      text-align: left;
    }
    .form-inline {
      display: flex;
      flex-direction: row;
      gap: 8px;
      margin-bottom: 8px;
    }
    .form-inline input, .form-inline button {
      border: none;
      border-radius: 8px;
      font-weight: bold;
      font-size: 1em;
      padding: 8px 20px;
      background: linear-gradient(90deg, #ff5e62 0%, #ff9966 100%);
      color: #fff;
      margin-top: 0;
      margin-bottom: 0;
      margin-left: 0;
      cursor: pointer;
      outline: none;
      box-shadow: 0 2px 8px rgba(255,94,98,0.10);
      transition: background 0.2s, box-shadow 0.2s, transform 0.1s;
  background: #3498db;
    .export-btn:hover {
      background: linear-gradient(90deg, #ff9966 0%, #ff5e62 100%);
      color: #fff;
      box-shadow: 0 4px 16px rgba(255,94,98,0.18);
      transform: translateY(-2px) scale(1.03);
    }
  color: #fff;
  cursor: pointer;
  border: none;
  font-weight: bold;
  transition: background 0.2s;
    }
    .form-inline button:hover {
  background: #217dbb;
    }
    table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 10px;
  background: #fff;
  font-size: 0.97em;
  box-sizing: border-box;
  border-radius: 8px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.08);
  overflow: hidden;
    }
    th, td {
  border: 1px solid #ddd;
  padding: 4px 2px;
  min-width: 40px;
  text-align: center;
  font-weight: normal;
  font-size: 0.97em;
  background: #fff;
  color: #222;
  white-space: normal;
  word-break: break-word;
    }
    th {
  background: #f2f2f2;
  font-size: 1em;
  font-weight: bold;
  text-transform: uppercase;
  letter-spacing: 1px;
    }
    .clear-logs-btn {
      border: none;
      border-radius: 8px;
      font-weight: bold;
      font-size: 1em;
      padding: 8px 20px;
      background: linear-gradient(90deg, #ffe066 0%, #ffd700 100%);
      color: #222;
      margin-top: 0;
      margin-bottom: 0;
      margin-left: 0;
      cursor: pointer;
      outline: none;
      box-shadow: 0 2px 8px rgba(255,94,98,0.10);
      transition: background 0.2s, box-shadow 0.2s, transform 0.1s;
      float: right;
    }
    .clear-logs-btn:hover {
      background: linear-gradient(90deg, #ffe066 0%, #ffd700 100%);
      color: #222;
      box-shadow: 0 4px 16px rgba(255,94,98,0.18);
      transform: translateY(-2px) scale(1.03);
    }
    .form-inline input#scheduleAmount {
      width: 110px;
      min-width: 60px;
      max-width: 140px;
      box-sizing: border-box;
    }
    #manualAmount {
      width: 105px !important;
      min-width: 65px;
      max-width: 145px;
      box-sizing: border-box;
    }
    #manualCardContainer {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  margin-right: 8px;
  margin-left: -8px;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.10);
  padding: 16px 18px 18px 18px;
  height: 80px;
  min-height: unset;
  max-height: unset;
}
.card-container {
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.10);
  padding: 16px 18px 18px 18px;
  margin-bottom: 0;
  margin-right: 8px;
  margin-left: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}
#dispenseLogsTable {
  table-layout: fixed;
  width: 100%;
  word-break: break-word;
  flex: 1 1 auto;
  overflow-y: auto;
}

#dispenseLogsTable th, #dispenseLogsTable td {
  overflow: hidden;
  text-overflow: ellipsis;
}

#logsPagination {
  width: 100%;
  display: flex;
  justify-content: center;
  align-items: center;
  margin-top: 8px;
  margin-bottom: 0;
  flex: 0 0 auto;
}
.container {
  display: flex;
  flex-direction: column;
  gap: 20px;
  width: 100%;
  height: auto;
  box-sizing: border-box;
  align-items: stretch;
  justify-content: flex-start;
  padding: 0;
}
/* Ensure dropdown list items are neutral; only the selected (closed/half/full) appearance is colored */
#flowRateSelect option {
  color: #222 !important;
  background: #fff !important;
}
  </style>
</head>
<body>
  <div class="container">
    <div class="top-row">
      <div class="feed-container-box">
        <div class="feed-container-section">
          <div class="feed-container-label">Feed Container:</div>
          <div class="feed-container-row">
            <div class="feed-container-item">Remaining Feeds: <span id="feedRemaining">0</span>g</div>
            <div class="feed-container-item">Flow Rate:
              <select id="flowRateSelect" style="border:none;background:#f8fafc;padding:6px 10px;border-radius:8px;font-weight:bold;border:1.5px solid #b3b3b3;">
                <option value="closed">Closed</option>
                <option value="half">Half</option>
                <option value="full">Full Open</option>
              </select>
            </div>
            <div class="feed-container-item" style="display:flex;align-items:center;gap:12px;">
              <div style="font-weight:bold;margin-right:6px;">Status:</div>
              <div id="feederStatusBar" style="display:flex;align-items:center;gap:8px;">
                <!-- Progress bar container (percent text is absolutely centered on top of fill) -->
                <div style="position:relative;min-width:120px;max-width:260px;width:220px;height:34px;border-radius:20px;background:#f0f0f0;overflow:hidden;border:1px solid #e0e0e0;">
                  <div id="feederStatusFill" style="position:absolute;left:0;top:0;height:100%;width:100%;z-index:1;transition:width 300ms ease, background 300ms ease;"></div>
                  <span id="feederStatusPercent" style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);z-index:2;font-weight:800;color:#000;letter-spacing:1px;">100%</span>
                </div>
              </div>
            </div>
          </div>
        </div>
              <div class="digital-clock-box" style="margin-left:auto;"><span id="digitalClock"></span></div>
      </div>
    </div>
    <div class="main-row">
      <div class="card-container" id="manualCardContainer">
        <div id="manualSection" class="main-card" style="margin-top:0;min-width:450px;max-width:420px;">
          <div class="main-card-header">DISPENSE</div>
          <div style="display:flex;align-items:center;gap:10px;">
            <input type="number" id="manualAmount" placeholder="Amount" min="1" max="5000" step="1" style="padding:8px 12px;border-radius:8px;border:1.5px solid #b3b3b3;font-size:1em;">
            <button class="mode-btn" id="dispenseBtn" style="padding:8px 20px;" onclick="manualDispense()">DISPENSE</button>
  <script>
  // --- VESSEL STATUS LOGIC ---
  function setFeederEnabled(enabled) {
    document.getElementById('manualAmount').disabled = !enabled;
    document.getElementById('dispenseBtn').disabled = !enabled;
    // Update the new progress-style status bar
    const fill = document.getElementById('feederStatusFill');
    const label = document.getElementById('feederStatusPercent');
    const icon = document.getElementById('feederStatusIcon');
    if (enabled) {
      // restore based on current remaining feed
      updateContainerStatus();
    } else {
      // show current percentage but desaturate the fill to indicate inactive state
      updateContainerStatus();
      if (fill) {
        // keep width as percentage but make it gray
        fill.style.background = 'linear-gradient(90deg,#e0e0e0 0%,#bdbdbd 100%)';
      }
      if (label) {
        // keep percentage text visible and black
        label.style.color = '#000';
        label.style.fontWeight = '800';
      }
    }
  }

  function fetchVesselStatus() {
    fetch('fetch_sensors.php')
      .then(res => res.json())
      .then(data => {
        // Assume data.vessel_status is 'on' or 'off'. Adjust as needed for your backend.
        if (data.vessel_status && data.vessel_status.toLowerCase() === 'off') {
          setFeederEnabled(false);
        } else {
          setFeederEnabled(true);
        }
      })
      .catch(() => setFeederEnabled(false));
  }
  // Check vessel status every 5 seconds
  setInterval(fetchVesselStatus, 5000);
  fetchVesselStatus();
  
  // --- FLOW RATE MODE (Closed / Half / Full Open) - make select the single visual control ---
  function updateFlowRateAppearance() {
    try {
      const sel = document.getElementById('flowRateSelect');
      if (!sel) return;
      // Do not overwrite the currently selected value here; use provided value or fallbacks
      const rawMode = (arguments.length > 0 && typeof arguments[0] === 'string') ? arguments[0] : (localStorage.getItem('flowRateMode') || sel.value || 'full');
      const mode = String(rawMode || '').toLowerCase().trim();
      // update select appearance (text color and border) to reflect state
      if (mode === 'closed') {
        sel.style.color = '#e53935';
        sel.style.borderColor = '#e57373';
        sel.style.background = '#fff5f5';
      } else if (mode === 'half') {
        sel.style.color = '#ffb300';
        sel.style.borderColor = '#ffd54f';
        sel.style.background = '#fffaf0';
      } else {
        sel.style.color = '#00c853';
        sel.style.borderColor = '#81c784';
        sel.style.background = '#f3fff6';
      }
    } catch (e) { console.warn('flow appearance update failed', e); }
  }

  // Map flow mode to the command string expected by the RPi via PC
  async function sendFlowCommand(mode) {
    try {
      const mapping = {
        'closed': '14:VALVE:180',
        'half':   '14:VALVE:146',
        'full':   '14:VALVE:90',
        // fallback values for possible option labels
        'full open': '14:VALVE:90'
      };
      const key = (mode || '').toLowerCase();
      const cmd = mapping[key] || mapping['full'];
      const payload = JSON.stringify({ message: cmd });

      // Try same-origin proxy first (avoids CORS/network issues)
      const proxyUrl = 'ad_dashboard.php?api=send_flow';
      try {
        const r = await fetch(proxyUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload });
        if (r.ok) {
          try { Swal.fire({ toast: true, position: 'top-end', timer: 1400, showConfirmButton: false, icon: 'success', title: 'Flow command sent (proxy)' }); } catch (e) {}
          console.log('[FEEDER] Sent via proxy:', cmd);
          return true;
        } else {
          console.warn('Proxy responded non-ok', r.status);
          try { const txt = await r.text(); console.warn('proxy body:', txt); } catch (e) {}
        }
      } catch (e) {
        console.warn('Proxy fetch failed, will try direct Flask', e);
      }

      // Fallback: direct POST to Flask server
      const FLASK_SEND = 'http://192.168.0.2:5000/send_from_pc';
      const res = await fetch(FLASK_SEND, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: payload
      });
      if (!res.ok) {
        console.warn('sendFlowCommand: direct non-ok response', res.status);
        try { const text = await res.text(); console.warn('body:', text); } catch(e){}
        Swal.fire({ icon: 'error', title: 'Command Failed', text: 'Could not send command to PC server.' });
        return false;
      }
      try { Swal.fire({ toast: true, position: 'top-end', timer: 1400, showConfirmButton: false, icon: 'success', title: 'Flow command sent' }); } catch (e) {}
      console.log('[FEEDER] Sent PC→RPi command (direct):', cmd);
      return true;
    } catch (e) {
      console.warn('sendFlowCommand error', e);
      try { Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to send flow command.' }); } catch (e) {}
      return false;
    }
  }

  // Generic helper to send arbitrary PC->RPi commands using proxy then fallback
  async function sendPCCommand(cmd) {
    try {
      console.log('[FEEDER] sendPCCommand called with:', cmd);
      const payload = JSON.stringify({ message: cmd });
      console.log('[FEEDER] Payload:', payload);
      
      // try proxy first
      const proxyUrl = 'ad_dashboard.php?api=send_flow';
      try {
        console.log('[FEEDER] Trying proxy:', proxyUrl);
        const pr = await fetch(proxyUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload });
        console.log('[FEEDER] Proxy response status:', pr.status);
        if (pr.ok) {
          const respText = await pr.text();
          console.log('[FEEDER] Proxy response body:', respText);
          console.log('[FEEDER] ✓ sendPCCommand SUCCESS via proxy:', cmd);
          return true;
        } else {
          const errText = await pr.text();
          console.warn('[FEEDER] sendPCCommand proxy non-ok', pr.status, errText);
        }
      } catch (e) {
        console.warn('[FEEDER] sendPCCommand proxy failed', e);
      }
      
      // fallback direct
      console.log('[FEEDER] Attempting direct Flask connection...');
      const FLASK_SEND = 'http://192.168.0.2:5000/send_from_pc';
      const res = await fetch(FLASK_SEND, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload });
      console.log('[FEEDER] Direct response status:', res.status);
      if (!res.ok) {
        const errText = await res.text();
        console.warn('[FEEDER] sendPCCommand direct non-ok', res.status, errText);
        return false;
      }
      const respText = await res.text();
      console.log('[FEEDER] Direct response body:', respText);
      console.log('[FEEDER] ✓ sendPCCommand SUCCESS direct:', cmd);
      return true;
    } catch (e) {
      console.error('[FEEDER] sendPCCommand FAILED - error:', e);
      return false;
    }
  }

  function initFlowRateControl() {
    try {
      const sel = document.getElementById('flowRateSelect');
      if (!sel) return;
      // Load saved value and apply appearance
      const saved = localStorage.getItem('flowRateMode') || 'full';
      const savedNorm = String(saved || '').toLowerCase().trim();
      // If saved value matches an option, set it; otherwise default to 'full'
      const optionExists = Array.from(sel.options).some(o => String(o.value || '').toLowerCase().trim() === savedNorm);
      sel.value = optionExists ? savedNorm : 'full';
      updateFlowRateAppearance(savedNorm);
      // Use onchange to prevent duplicate listeners; replace any existing handler
      sel.onchange = function () {
        const v = String(this.value || '').toLowerCase().trim();
        localStorage.setItem('flowRateMode', v);
        updateFlowRateAppearance(v);
        updateDispenseButtonState(v);
        // NOTE: Do NOT send valve command on change — valve will be activated when Dispense is pressed
      };
      // Do not send the valve command on init; only update button state
      updateDispenseButtonState(savedNorm);
    } catch (e) { console.warn('init flow control failed', e); }
  }

  function updateDispenseButtonState(mode) {
    try {
      const v = String(mode || localStorage.getItem('flowRateMode') || document.getElementById('flowRateSelect')?.value || '').toLowerCase().trim();
      const btn = document.getElementById('dispenseBtn');
      const input = document.getElementById('manualAmount');
      if (!btn) return;
      if (v === 'closed') {
        btn.disabled = true; btn.style.opacity = 0.5; btn.style.pointerEvents = 'none'; btn.setAttribute('aria-disabled','true'); if (input) input.disabled = true;
      } else {
        btn.disabled = false; btn.style.opacity = 1; btn.style.pointerEvents = 'auto'; btn.removeAttribute('aria-disabled'); if (input) input.disabled = false;
      }
    } catch (e) { /* ignore */ }
  }
  // Initialize flow rate control on load
  initFlowRateControl();
  </script>
          </div>
          <div id="nextDispense"></div>
        </div>
      </div>
      <div id="dispenseLogsCard" class="main-card" style="margin-left: 0; margin-right: 0;">
        <div class="main-card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <span>DISPENSE LOGS</span>
          <div style="display:flex;gap:10px;align-items:center;">
            <button class="mode-btn" style="min-width:110px;" onclick="exportPDF()">Export PDF</button>
            <button class="clear-logs-btn" onclick="openClearDispenseLogsModal()">Clear Logs</button>
          </div>
<!-- Selective Clear Dispense Logs Modal -->
<style>
  #clearDispenseLogsModal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0; top: 0;
    width: 100vw; height: 100vh;
    background: rgba(30,81,98,0.18);
    align-items: center;
    justify-content: center;
  }
  #clearDispenseLogsModal .clear-logs-modal-content {
    background: #fff;
    border: 1px solid #d0d7de;
    border-radius: 12px;
    padding: 32px 24px 24px 24px;
    width: 350px;
    max-width: 95vw;
    text-align: center;
    box-shadow: 0 4px 24px rgba(30,81,98,0.10);
    color: #222;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    animation: fadeInModal 0.25s;
  }
  #clearDispenseLogsModal label {
    font-weight: 600;
    color: #1e5162;
    margin-bottom: 6px;
    margin-top: 8px;
    font-size: 1rem;
    text-align: left;
    display: block;
  }
  #clearDispenseLogsModal input[type="date"] {
    width: 100%;
    padding: 10px 12px;
    background: #f8fafc;
    border: 1px solid #d0d7de;
    border-radius: 7px;
    outline: none;
    color: #222;
    font-size: 1rem;
    box-sizing: border-box;
    text-align: left;
    transition: border 0.2s;
    margin-bottom: 8px;
  }
  #clearDispenseLogsModal .clear-logs-btns {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 10px;
  }
  #clearDispenseLogsModal .clear-logs-btns button {
    width: 100%;
    background: #1976d2;
    border: none;
    border-radius: 7px;
    padding: 10px 16px;
    font-size: 15px;
    font-weight: 700;
    color: #fff;
    cursor: pointer;
    transition: background 0.2s;
    margin-top: 0;
    box-shadow: none;
  }
  #clearDispenseLogsModal .clear-logs-btns button.cancel {
    background: #e0e0e0;
    color: #333;
    font-weight: 600;
    box-shadow: none;
  }
</style>
<div id="clearDispenseLogsModal">
  <div class="clear-logs-modal-content">
    <h3>Clear Feed Logs</h3>
    <label>From:</label>
    <div style="display:flex;gap:6px;align-items:center;">
      <input type="date" id="clearDispenseLogsFromDate" style="flex:2;">
      <input type="time" id="clearDispenseLogsFromTime" style="flex:1;">
    </div>
    <label>To:</label>
    <div style="display:flex;gap:6px;align-items:center;">
      <input type="date" id="clearDispenseLogsToDate" style="flex:2;">
      <input type="time" id="clearDispenseLogsToTime" style="flex:1;">
    </div>
    <div class="clear-logs-btns">
  <button type="button" class="cancel" onclick="closeClearDispenseLogsModal()">Cancel</button>
  <button type="button" onclick="previewClearDispenseLogs()">Preview All</button>
    </div>
  </div>
</div>
<!-- Preview Modal -->
<div id="previewDispenseLogsModal" style="display:none; position:fixed; z-index:10000; left:0; top:0; width:100vw; height:100vh; background:rgba(30,81,98,0.18); align-items:center; justify-content:center;">
  <div style="background:#fff; border-radius:12px; box-shadow:0 4px 24px rgba(30,81,98,0.10); padding:28px 24px; width:600px; max-width:98vw; max-height:80vh; overflow-y:auto; display:flex; flex-direction:column; gap:1rem;">
    <h3 style="margin:0 0 10px 0; color:#1e5162;">Logs to be Deleted</h3>
    <div id="previewDispenseLogsContent" style="font-size:0.97rem; color:#333;"></div>
    <div style="display:flex; gap:10px; justify-content:flex-end;">
      <button type="button" onclick="closePreviewDispenseLogsModal()" style="padding:7px 18px; border-radius:6px; background:#e0e0e0; color:#333; border:none; font-weight:600;">Cancel</button>
      <button type="button" id="clearDispenseLogsDeleteBtn" style="padding:7px 18px; border-radius:6px; background:#e53935; color:#fff; border:none; font-weight:600;">Delete</button>
    </div>
  </div>
</div>
<script>
function openClearDispenseLogsModal() {
  var modal = document.getElementById('clearDispenseLogsModal');
  modal.style.display = 'flex';
  modal.style.alignItems = 'center';
  modal.style.justifyContent = 'center';
}
function closeClearDispenseLogsModal() {
  var modal = document.getElementById('clearDispenseLogsModal');
  modal.style.display = 'none';
  modal.style.alignItems = '';
  modal.style.justifyContent = '';
}
function previewClearDispenseLogs() {
  const fromDate = document.getElementById('clearDispenseLogsFromDate').value;
  const fromTimeVal = document.getElementById('clearDispenseLogsFromTime').value;
  const toDate = document.getElementById('clearDispenseLogsToDate').value;
  const toTimeVal = document.getElementById('clearDispenseLogsToTime').value;
  let logs = getSafeArray('dispenseLogs');
  let fromTime = null, toTime = null;
  if (fromDate) {
    fromTime = new Date(fromDate + 'T' + (fromTimeVal ? fromTimeVal : '00:00')).getTime();
  }
  if (toDate) {
    toTime = new Date(toDate + 'T' + (toTimeVal ? toTimeVal : '23:59')).getTime();
  }
  let filtered = logs.filter(log => {
    let logTime = log.timestamp ? new Date(log.timestamp).getTime() : 0;
    const fromMatch = !fromTime || (logTime >= fromTime);
    const toMatch = !toTime || (logTime <= toTime);
    return fromMatch && toMatch;
  });
  // Sort by logId ascending
  filtered.sort((a, b) => (a.logId || 0) - (b.logId || 0));
  const content = document.getElementById('previewDispenseLogsContent');
  if (filtered.length === 0) {
    content.innerHTML = '<em>No logs found for this filter.</em>';
    document.getElementById('clearDispenseLogsDeleteBtn').style.display = 'none';
  } else {
    let table = `<div style='overflow-x:auto;'><table style='width:100%; border-collapse:collapse; font-size:0.97rem;'>`;
    table += `<thead><tr style='background:#f8fafc; color:#1e5162;'><th style='padding:6px 8px; border-bottom:1px solid #e0e0e0;'>Log ID</th><th style='padding:6px 8px; border-bottom:1px solid #e0e0e0;'>Timestamp</th><th style='padding:6px 8px; border-bottom:1px solid #e0e0e0;'>Quantity</th><th style='padding:6px 8px; border-bottom:1px solid #e0e0e0;'>Status</th></tr></thead><tbody>`;
    table += filtered.map(l => {
      const logId = l.logId !== undefined ? l.logId : '';
      const ts = l.timestamp ? l.timestamp : '';
      const qty = l.quantity || '';
      const status = l.statusText || '';
      return `<tr><td style='padding:6px 8px; border-bottom:1px solid #f0f0f0;'>${logId}</td><td style='padding:6px 8px; border-bottom:1px solid #f0f0f0; color:#1976d2;'>${ts}</td><td style='padding:6px 8px; border-bottom:1px solid #f0f0f0;'>${qty}</td><td style='padding:6px 8px; border-bottom:1px solid #f0f0f0; color:#888;'>${status}</td></tr>`;
    }).join('');
    table += `</tbody></table></div>`;
    content.innerHTML = table;
    document.getElementById('clearDispenseLogsDeleteBtn').style.display = '';
  }
  document.getElementById('clearDispenseLogsModal').style.display = 'none';
  const previewModal = document.getElementById('previewDispenseLogsModal');
  previewModal.style.display = 'flex';
  previewModal.style.alignItems = 'center';
  previewModal.style.justifyContent = 'center';
  document.getElementById('clearDispenseLogsDeleteBtn').onclick = function() {
    Swal.fire({
      title: `Delete ${filtered.length} logs?`,
      text: 'This will permanently delete the selected logs. Continue?',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#e74c3c',
      cancelButtonColor: '#3085d6',
      confirmButtonText: 'Yes, delete logs!'
    }).then((result) => {
      if (result.isConfirmed) {
        let logs = getSafeArray('dispenseLogs');
        let fromTime = null, toTime = null;
        if (fromDate) {
          fromTime = new Date(fromDate + 'T' + (fromTimeVal ? fromTimeVal : '00:00')).getTime();
        }
        if (toDate) {
          toTime = new Date(toDate + 'T' + (toTimeVal ? toTimeVal : '23:59')).getTime();
        }
        const newLogs = logs.filter(log => {
          let logTime = log.timestamp ? new Date(log.timestamp).getTime() : 0;
          const fromMatch = !fromTime || (logTime >= fromTime);
          const toMatch = !toTime || (logTime <= toTime);
          return !(fromMatch && toMatch);
        });
        safeSetItem('dispenseLogs', JSON.stringify(newLogs));
        closePreviewDispenseLogsModal();
        renderDispenseLogs();
        Swal.fire('Logs Cleared', 'Selected logs have been deleted.', 'success');
      }
    });
  };
}
function closePreviewDispenseLogsModal() {
  var modal = document.getElementById('previewDispenseLogsModal');
  modal.style.display = 'none';
  modal.style.alignItems = '';
  modal.style.justifyContent = '';
  closeClearDispenseLogsModal();
}
</script>
        </div>
        <table id="dispenseLogsTable">
          <thead>
            <tr>
              <th>Log ID</th>
              <th>TIMESTAMP</th>
              <th>QUANTITY (G)</th>
              <th>STATUS</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
  <div id="dispenseDailyQuantity">Total quantity: 0 g</div>
        <div id="logsPagination"></div>
      </div>
    </div>
  </div>
  <script>
    // Add these global variables near the top of your <script> section
const PAGE_SIZE = 5;
let logsPage = 0;

// Robust localStorage helpers (logs only)
function getSafeArray(key) {
  try {
    const val = JSON.parse(localStorage.getItem(key) || '[]');
    return Array.isArray(val) ? val : [];
  } catch (e) {
    return [];
  }
}
function safeSetItem(key, value) {
  try {
    localStorage.setItem(key, value);
    return true;
  } catch (e) {
    alert('Error: Could not save to localStorage. Data may be too large or storage is restricted.');
    return false;
  }
}

// On page load, log all localStorage keys and their sizes
console.log('DEBUG: localStorage.dispenseLogs', localStorage.getItem('dispenseLogs'));
// Clear dispense logs
function clearDispenseLogs() {
  logsPage = 0;
  let logs = getSafeArray('dispenseLogs');
  if (logs.length === 0) {
    Swal.fire('No logs', 'There are no logs to clear.', 'info');
    return;
  }
  Swal.fire({
    title: 'Clear All Logs?',
    text: 'This will permanently delete all dispense logs. Continue?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    cancelButtonColor: '#3085d6',
    confirmButtonText: 'Yes, clear logs!'
  }).then((result) => {
    if (result.isConfirmed) {
      localStorage.removeItem('dispenseLogs');
      renderDispenseLogs();
      Swal.fire('Cleared!', 'All dispense logs have been deleted.', 'success');
    }
  });
}

// No mode switching needed, only manual
function setMode(mode) {
  document.getElementById('manualCardContainer').style.display = '';
  document.getElementById('manualSection').style.display = '';
  var logsCard = document.getElementById('dispenseLogsCard');
  if (logsCard) logsCard.style.display = '';
}

// --- FEED CONTAINER LOGIC ---
const MAX_HISTORY_ROWS = Infinity;
const MAX_AMOUNT = 5000;
let feedRemaining = 2000;
localStorage.setItem('feedRemaining', feedRemaining);
// On load, set to 2000g always
feedRemaining = 2000;
localStorage.setItem('feedRemaining', feedRemaining);
let containerCapacity = 2000;
let logCounter = 0;

// Dispenser
  async function manualDispense() {
  const amount = parseInt(document.getElementById('manualAmount').value);
  if (!amount || amount <= 0) { alert('Enter a valid amount!'); return; }
  if (amount > MAX_AMOUNT) { alert('Maximum allowed is 5000g!'); return; }
  if (feedRemaining - amount < 0) {
    logDispense(amount, feedRemaining, 'failed');
    Swal.fire('Error!', 'The amount exceeds available feed.', 'error');
    return;
  }
  // Guard: don't proceed if DISPENSE button is disabled / marked aria-disabled
  const dispenseBtn = document.getElementById('dispenseBtn');
  if (dispenseBtn && (dispenseBtn.disabled || dispenseBtn.getAttribute('aria-disabled') === 'true')) {
    try { Swal.fire('Dispense blocked', 'Flow rate is Closed — change to Half/Full to dispense.', 'info'); } catch (e) {}
    return;
  }
  // Determine flow speed (g per second) based on selected flow-rate BEFORE starting impeller
  const flowSelRaw = (document.getElementById('flowRateSelect') && document.getElementById('flowRateSelect').value) || localStorage.getItem('flowRateMode') || 'full';
  const flowSel = String(flowSelRaw || '').toLowerCase().trim();
  const speeds = { 'full': 20, 'half': 10, 'closed': 0, 'full open': 20 };
  const speed = speeds[flowSel] || 10;
  if (!speed || speed <= 0) {
    // Valve is closed: do not start impeller and abort
    Swal.fire('Error', 'Flow rate is closed. Change to Half or Full Open to dispense.', 'error');
    return;
  }

  // disable controls while dispensing
  const amountInput = document.getElementById('manualAmount');
  if (dispenseBtn) { dispenseBtn.disabled = true; dispenseBtn.style.opacity = 0.6; dispenseBtn.style.pointerEvents = 'none'; dispenseBtn.setAttribute('aria-disabled','true'); }
  if (amountInput) amountInput.disabled = true;

  // STEP 1: Start impeller first and wait for it to spin up
  console.log('[DISPENSE] STEP 1: Sending IMPELLER ON command...');
  console.log('[DISPENSE] Command string: "14:IMPELLER:ON"');
  
  // Show alert that impeller command is being sent
  Swal.fire({ toast: true, position: 'top-end', timer: 2000, showConfirmButton: false, icon: 'info', title: 'Starting Impeller...' });
  
  // Send IMPELLER:ON multiple times with longer delays to ensure RPI receives it
  let started = false;
  for (let attempt = 1; attempt <= 5; attempt++) {
    console.log(`[DISPENSE] Sending IMPELLER:ON command (attempt ${attempt}/5)...`);
    const result = await sendPCCommand('14:IMPELLER:ON');
    console.log(`[DISPENSE] Attempt ${attempt} result:`, result);
    if (result) started = true;
    // Longer delay between attempts (500ms) to give RPI time to poll and process
    if (attempt < 5) await new Promise(r => setTimeout(r, 500));
  }
  
  if (!started) {
    console.error('[DISPENSE] Failed to start impeller - aborting');
    if (dispenseBtn) { dispenseBtn.disabled = false; dispenseBtn.style.opacity = 1; dispenseBtn.style.pointerEvents = 'auto'; dispenseBtn.removeAttribute('aria-disabled'); }
    if (amountInput) amountInput.disabled = false;
    Swal.fire('Error', 'Failed to start impeller. Dispense aborted.', 'error');
    return;
  }
  console.log('[DISPENSE] IMPELLER ON commands sent successfully');
  Swal.fire({ toast: true, position: 'top-end', timer: 1500, showConfirmButton: false, icon: 'success', title: 'Impeller Started!' });
  
  // Allow extra time for impeller to receive command and spin up (2 seconds)
  console.log('[DISPENSE] Waiting 2s for RPI to process and impeller to spin up...');
  await new Promise(r => setTimeout(r, 2000));
  console.log('[DISPENSE] Spin-up complete');
  
  // STEP 2: Now open the valve to the selected flow-rate
  console.log('[DISPENSE] STEP 2: Opening valve to', flowSel);
  try {
    // Send valve command multiple times with delays
    let valveSent = false;
    for (let attempt = 1; attempt <= 5; attempt++) {
      console.log(`[DISPENSE] Sending VALVE command (attempt ${attempt}/5)...`);
      const result = await sendFlowCommand(flowSel);
      console.log(`[DISPENSE] STEP 2 attempt ${attempt} result:`, result);
      if (result) valveSent = true;
      // Longer delay between valve command attempts (500ms)
      if (attempt < 5) await new Promise(r => setTimeout(r, 500));
    }
    
    if (!valveSent) {
      console.error('[DISPENSE] Failed to open valve - stopping impeller and aborting');
      // stop impeller and revert UI
      for (let i = 0; i < 3; i++) {
        await sendPCCommand('14:IMPELLER:OFF');
        await new Promise(r => setTimeout(r, 500));
      }
      if (dispenseBtn) { dispenseBtn.disabled = false; dispenseBtn.style.opacity = 1; dispenseBtn.style.pointerEvents = 'auto'; dispenseBtn.removeAttribute('aria-disabled'); }
      if (amountInput) amountInput.disabled = false;
      Swal.fire('Error', 'Failed to open valve. Dispense aborted.', 'error');
      return;
    }
    console.log('[DISPENSE] Valve commands sent, waiting 1s for RPI to process and valve to actuate...');
    // Allow extra time for RPI to receive and valve to fully actuate
    await new Promise(r => setTimeout(r, 1000));
    console.log('[DISPENSE] Valve actuation complete, starting dispense timer');
  } catch (e) {
    console.error('[DISPENSE] Valve command failed after impeller ON', e);
    for (let i = 0; i < 3; i++) {
      await sendPCCommand('14:IMPELLER:OFF');
      await new Promise(r => setTimeout(r, 500));
    }
    if (dispenseBtn) { dispenseBtn.disabled = false; dispenseBtn.style.opacity = 1; dispenseBtn.style.pointerEvents = 'auto'; dispenseBtn.removeAttribute('aria-disabled'); }
    if (amountInput) amountInput.disabled = false;
    Swal.fire('Error', 'Failed to open valve. Dispense aborted.', 'error');
    return;
  }

    // Estimate duration (ms) from amount (g) and speed (g/s)
    const durationMs = Math.max(500, Math.round((amount / speed) * 1000));
    // Provide UI feedback: change button text and show countdown
    const originalBtnText = dispenseBtn ? dispenseBtn.textContent : '';
    if (dispenseBtn) dispenseBtn.textContent = 'Dispensing...';
    let elapsed = 0;
    const tickInterval = 500;
    const countdownElId = 'dispenseCountdown';
    let countdownEl = document.getElementById(countdownElId);
    if (!countdownEl) {
      countdownEl = document.createElement('div');
      countdownEl.id = countdownElId;
      countdownEl.style.fontWeight = '700';
      countdownEl.style.marginTop = '8px';
      countdownEl.style.color = '#1e5162';
      if (amountInput && amountInput.parentElement) amountInput.parentElement.appendChild(countdownEl);
    }
    countdownEl.textContent = `Estimated: ${(durationMs/1000).toFixed(1)}s`;

    // Wait for duration while optionally updating a small timer
    console.log('[DISPENSE] Starting countdown timer for', durationMs, 'ms');
    const timerStartTime = Date.now();
    await new Promise((resolve) => {
      const intId = setInterval(() => {
        elapsed += tickInterval;
        const remaining = Math.max(0, durationMs - elapsed);
        countdownEl.textContent = `Estimated: ${(remaining/1000).toFixed(1)}s`;
        if (elapsed >= durationMs) {
          clearInterval(intId);
          const actualElapsed = Date.now() - timerStartTime;
          console.log('[DISPENSE] Timer completed. Expected:', durationMs, 'ms, Actual:', actualElapsed, 'ms');
          resolve();
        }
      }, tickInterval);
    });

    // STEP 3: Close valve first, then stop impeller
    console.log('[DISPENSE] ========================================');
    console.log('[DISPENSE] STEP 3: CLOSING VALVE THEN STOPPING IMPELLER');
    console.log('[DISPENSE] ========================================');
    console.log('[DISPENSE] Timestamp:', new Date().toISOString());
    
    // STEP 3A: Close the valve first
    console.log('[DISPENSE] STEP 3A: Closing valve...');
    let autoClosed = false;
    try {
      // Send valve close command multiple times with delays
      let closeSent = false;
      for (let attempt = 1; attempt <= 5; attempt++) {
        console.log(`[DISPENSE] Sending VALVE CLOSE command (attempt ${attempt}/5)...`);
        const result = await sendFlowCommand('closed');
        console.log(`[DISPENSE] Valve close attempt ${attempt} result:`, result);
        if (result) closeSent = true;
        // Delay between attempts (500ms)
        if (attempt < 5) await new Promise(r => setTimeout(r, 500));
      }
      
      if (!closeSent) {
        console.warn('[DISPENSE] Failed to close valve');
        Swal.fire('Warning', 'Dispensed but failed to close valve. Please check device.', 'warning');
      } else {
        autoClosed = true;
        console.log('[DISPENSE] ✓ Valve close commands sent successfully');
        // reflect closed state in UI/storage
        try {
          const sel = document.getElementById('flowRateSelect');
          if (sel) {
            sel.value = 'closed';
          }
        } catch(e){}
        localStorage.setItem('flowRateMode', 'closed');
        updateFlowRateAppearance('closed');
        updateDispenseButtonState('closed');
      }
    } catch (e) {
      console.warn('[DISPENSE] Failed to auto-close valve after dispense', e);
    }
    
    // Longer delay after valve closes to ensure RPI processes it (1 second)
    console.log('[DISPENSE] Waiting 1s for RPI to process valve close...');
    await new Promise(r => setTimeout(r, 1000));
    
    // STEP 3B: Now stop the impeller
    console.log('[DISPENSE] STEP 3B: Stopping impeller...');
    console.log('[DISPENSE] Command string: "14:IMPELLER:OFF"');
    
    Swal.fire({ toast: true, position: 'top-end', timer: 2000, showConfirmButton: false, icon: 'info', title: 'Stopping Impeller...' });
    
    // Send IMPELLER:OFF command multiple times with longer delays to ensure it's received
    let stopped = false;
    for (let attempt = 1; attempt <= 5; attempt++) {
      console.log(`[DISPENSE] Sending IMPELLER:OFF command (attempt ${attempt}/5)...`);
      const result = await sendPCCommand('14:IMPELLER:OFF');
      console.log(`[DISPENSE] Attempt ${attempt} result:`, result);
      if (result) stopped = true;
      // Longer delay between attempts (500ms) to give RPI time to poll
      if (attempt < 5) await new Promise(r => setTimeout(r, 500));
    }
    
    if (!stopped) {
      console.error('[DISPENSE] ✗ All attempts to send IMPELLER OFF command failed');
      Swal.fire('Warning', 'Dispensed but failed to stop impeller. Please check device.', 'warning');
    } else {
      console.log('[DISPENSE] ✓ IMPELLER OFF commands sent successfully');
      Swal.fire({ toast: true, position: 'top-end', timer: 1500, showConfirmButton: false, icon: 'success', title: 'Impeller Stopped!' });
    }
    
    // Final delay to ensure RPI processes impeller stop (1 second)
    console.log('[DISPENSE] Waiting 1s for RPI to process impeller stop...');
    await new Promise(r => setTimeout(r, 1000));

    // STEP 5: Perform local dispense bookkeeping after hardware is stopped
    feedRemaining -= amount;
    updateContainerStatus();
    const percent = containerCapacity > 0 ? ((feedRemaining / containerCapacity) * 100).toFixed(1) : 0;
    let status = 'success';
    if (percent < 20) status = 'warning';
    logDispense(amount, percent, status);
    document.getElementById('manualAmount').value = '';

    // cleanup UI - if we auto-closed, leave controls disabled for closed state
    if (dispenseBtn) {
      if (!autoClosed) {
        dispenseBtn.disabled = false; dispenseBtn.style.opacity = 1; dispenseBtn.style.pointerEvents = 'auto'; dispenseBtn.removeAttribute('aria-disabled');
      }
      dispenseBtn.textContent = originalBtnText || 'DISPENSE';
    }
    if (amountInput) amountInput.disabled = false;
    if (countdownEl) { countdownEl.textContent = ''; }
}

function updateContainerStatus() {
  document.getElementById("feedRemaining").textContent = feedRemaining;
  localStorage.setItem('feedRemaining', feedRemaining);
  // compute percentage based on containerCapacity
  let percent = 0;
  try {
    percent = containerCapacity > 0 ? (feedRemaining / containerCapacity) * 100 : 0;
    if (percent < 0) percent = 0;
    if (percent > 100) percent = 100;
  } catch (e) { percent = 0; }
  // update status bar appearance
  updateStatusBar(percent);
  if (feedRemaining <= 0) {
    feedRemaining = 0;
    localStorage.setItem('feedRemaining', feedRemaining);
    Swal.fire('Empty!', 'The feed container is empty. Please refill.', 'error');
  }
}

// Update the status progress bar (fill width, gradient, and percent text)
function updateStatusBar(percent) {
  const fill = document.getElementById('feederStatusFill');
  const percentLabel = document.getElementById('feederStatusPercent');
  if (!fill || !percentLabel) return;
  const pct = Math.round(percent);
  // set width (can be 0%)
  fill.style.width = pct + '%';
  // choose gradient by thresholds
  if (pct <= 20) {
    // red
    fill.style.background = 'linear-gradient(90deg,#ff6b6b 0%,#e53935 100%)';
  } else if (pct <= 60) {
    // amber
    fill.style.background = 'linear-gradient(90deg,#ffd54f 0%,#ffb300 100%)';
  } else {
    // green
    fill.style.background = 'linear-gradient(90deg,#66ffa6 0%,#00c853 100%)';
  }
  // set label (always a percent, never replaced by INACTIVE)
  percentLabel.textContent = pct + '%';
  // ensure color is black and centered above the fill
  percentLabel.style.color = '#000';
}

function logDispense(amount, percent, status, note, modeOverride) {
  // Persist logs in localStorage
  let logs = getSafeArray('dispenseLogs');
  // No log limit
  // After adding a new log, always go to the last page
  const totalLogs = logs.length + 1; // +1 for the new log
  const totalPages = Math.ceil(totalLogs / PAGE_SIZE);

  logCounter = logs.length + 1;
  const logId = logCounter;
  const now = new Date();
  const timestamp = now.toLocaleString();
  let statusText = (status === "success") ? "Completed" : (status === "warning") ? "Interrupted" : "Failed";
  let logEntry = {
    logId,
    timestamp,
    start: '',
    end: '',
    quantity: amount,
    statusText
  };
  logs.unshift(logEntry);
  if (logs.length > MAX_HISTORY_ROWS) logs = logs.slice(0, MAX_HISTORY_ROWS);
  // Set logsPage to the last page after adding
  const newTotalPages = Math.ceil(logs.length / PAGE_SIZE);
  logsPage = newTotalPages > 0 ? newTotalPages - 1 : 0;
  safeSetItem('dispenseLogs', JSON.stringify(logs));
  renderDispenseLogs(); // Ensure logs are rendered after adding
  // Log to historyTable (manual mode only)
  const tableBody = document.querySelector("#historyTable tbody");
  if (tableBody) {
    const row = document.createElement("tr");
    row.innerHTML = `
      <td>${logId}</td>
      <td>${timestamp}</td>
      <td>${amount}</td>
      <td>${percent}%</td>
    `;
    tableBody.insertBefore(row, tableBody.firstChild);
    while (tableBody.rows.length > MAX_HISTORY_ROWS) {
      tableBody.deleteRow(-1);
    }
  }
}

function renderDispenseLogs() {
  const logsTableBody = document.querySelector("#dispenseLogsTable tbody");
  logsTableBody.innerHTML = '';
  let logs = getSafeArray('dispenseLogs');
  // Sort logs by logId ascending (numerical order)
  logs.sort((a, b) => a.logId - b.logId);
  // Pagination
  const startIdx = logsPage * PAGE_SIZE;
  const endIdx = startIdx + PAGE_SIZE;
  const pageLogs = logs.slice(startIdx, endIdx);
  if (pageLogs.length === 0) {
    const row = document.createElement("tr");
    row.innerHTML = `<td colspan="4" style="text-align:center; color:#888;">No feed logs to display.</td>`;
    logsTableBody.appendChild(row);
  } else {
    pageLogs.forEach(log => {
      const row = document.createElement("tr");
      // Split timestamp into date and time for display
      let dateStr = log.timestamp;
      let datePart = dateStr, timePart = '';
      if (dateStr && dateStr.includes(',')) {
        const parts = dateStr.split(',');
        datePart = parts[0].trim();
        timePart = parts[1].trim();
      }
      row.innerHTML = `
        <td>${log.logId}</td>
        <td><span>${datePart}</span><br><span>${timePart}</span></td>
        <td>${log.quantity || ''}</td>
        <td>${log.statusText}</td>
      `;
      logsTableBody.appendChild(row);
    });
  }
  let dailyTotal = 0;
  logs.forEach(log => {
    // Sum up daily quantity (g)
    let q = parseFloat(String(log.quantity).replace(',', '.'));
    if (!isNaN(q)) dailyTotal += q;
  });
  document.getElementById('dispenseDailyQuantity').textContent = `Total quantity: ${dailyTotal} g`;
  // Pagination controls
  let pagDiv = document.getElementById('logsPagination');
  if (!pagDiv) {
    pagDiv = document.createElement('div');
    pagDiv.id = 'logsPagination';
    pagDiv.style = 'text-align:center;margin-top:8px;';
    logsTableBody.parentElement.parentElement.appendChild(pagDiv);
  }
  pagDiv.innerHTML = `
    <button class="pagination-btn" onclick="prevLogsPage()" ${logsPage === 0 ? 'disabled' : ''}>Previous</button>
    <span style='margin:0 8px;font-weight:600;font-size:15px;'>Page ${logsPage+1} / ${Math.max(1, Math.ceil(logs.length/PAGE_SIZE))}</span>
    <button class="pagination-btn" onclick="nextLogsPage()" ${(logsPage+1)*PAGE_SIZE >= logs.length ? 'disabled' : ''}>Next</button>
  `;

  // Disable Export PDF button if no logs
  const exportBtn = document.querySelector('button[onclick="exportPDF()"]');
  if (exportBtn) {
    exportBtn.disabled = logs.length === 0;
    exportBtn.style.opacity = logs.length === 0 ? 0.5 : 1;
    exportBtn.style.cursor = logs.length === 0 ? 'not-allowed' : 'pointer';
  }
}

function exportPDF() {
  const logs = getSafeArray('dispenseLogs');
  if (!logs || logs.length === 0) {
    Swal.fire('No logs', 'There are no logs to export.', 'info');
    return;
  }
  Swal.fire({
    title: '<span style="font-size:1.35em;font-weight:700;letter-spacing:0.5px;">Export Feed Logs</span>',
    html: `
      <div class="export-modal-fields">
        <div class="export-modal-row">
          <label for='exportFromDate'>From:</label>
          <div style='display:flex;gap:8px;'>
            <input type="date" id="exportFromDate" style="flex:2;">
            <input type="time" id="exportFromTime" style="flex:1;">
          </div>
        </div>
        <div class="export-modal-row">
          <label for='exportToDate'>To:</label>
          <div style='display:flex;gap:8px;'>
            <input type="date" id="exportToDate" style="flex:2;">
            <input type="time" id="exportToTime" style="flex:1;">
          </div>
        </div>
      </div>
    `,
    showCancelButton: true,
    confirmButtonText: '<span style="padding:0 18px;">Export</span>',
    cancelButtonText: '<span style="padding:0 18px;">Cancel</span>',
    customClass: {
      popup: 'swal2-export-modal',
      confirmButton: 'swal2-export-confirm',
      cancelButton: 'swal2-export-cancel'
    },
    preConfirm: () => {
      return {
        fromDate: document.getElementById('exportFromDate').value,
        fromTime: document.getElementById('exportFromTime').value,
        toDate: document.getElementById('exportToDate').value,
        toTime: document.getElementById('exportToTime').value
      };
    }
  }).then((result) => {
    if (!result.isConfirmed) return;
    const { fromDate, fromTime, toDate, toTime } = result.value;
    let fromTimeVal = fromDate ? new Date(fromDate + 'T' + (fromTime ? fromTime : '00:00')).getTime() : null;
    let toTimeVal = toDate ? new Date(toDate + 'T' + (toTime ? toTime : '23:59')).getTime() : null;
    let filteredLogs = logs.filter(log => {
      let logTime = log.timestamp ? new Date(log.timestamp).getTime() : 0;
      const fromMatch = !fromTimeVal || (logTime >= fromTimeVal);
      const toMatch = !toTimeVal || (logTime <= toTimeVal);
      return fromMatch && toMatch;
    });
    filteredLogs.sort((a, b) => (a.logId || 0) - (b.logId || 0));
    if (filteredLogs.length === 0) {
      Swal.fire('No logs', 'No logs found for the selected range.', 'info');
      return;
    }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF();
    doc.setFontSize(14);
    doc.text("Dispense History Report", 14, 15);
    let head = [['Log ID', 'Timestamp', 'Quantity (g)', 'Status']];
    let body = filteredLogs.map(log => [
      log.logId,
      log.timestamp,
      log.quantity,
      log.statusText
    ]);
    doc.autoTable({
      startY: 20,
      head: head,
      body: body
    });
    const today = new Date();
    const mm = String(today.getMonth() + 1).padStart(2, "0");
    const dd = String(today.getDate()).padStart(2, "0");
    const yyyy = today.getFullYear();
    const fileName = `${mm}-${dd}-${yyyy} DispenseRecord.pdf`;
    doc.save(fileName);
  });
}

function prevLogsPage() { if (logsPage > 0) { logsPage--; renderDispenseLogs(); } }
function nextLogsPage() { let logs = getSafeArray('dispenseLogs'); if ((logsPage+1)*PAGE_SIZE < logs.length) { logsPage++; renderDispenseLogs(); } }

    // Digital clock
    function updateClock() {
  const now = new Date();
  let hours = now.getHours();
  const minutes = String(now.getMinutes()).padStart(2, '0');
  const seconds = String(now.getSeconds()).padStart(2, '0');
  const ampm = hours >= 12 ? 'PM' : 'AM';
  hours = hours % 12;
  hours = hours ? hours : 12; // the hour '0' should be '12'
  const dateStr = now.toLocaleDateString();
  document.getElementById('digitalClock').textContent = `${dateStr} ${hours}:${minutes}:${seconds} ${ampm}`;
  document.getElementById('digitalClock').style.whiteSpace = 'nowrap';
    }
    setInterval(updateClock, 1000); updateClock();
  // Debug: Log localStorage contents on load
  console.log('DEBUG: localStorage.dispenseLogs', localStorage.getItem('dispenseLogs'));
  console.log('DEBUG: localStorage.autoSchedule', localStorage.getItem('autoSchedule'));
  console.log('DEBUG: localStorage.lastDispensedTimes', localStorage.getItem('lastDispensedTimes'));
  // Initial mode
  setMode('auto');
  // On load, update feed container display
  updateContainerStatus();
  // On load, render schedule and logs
  renderDispenseLogs();
    </script>
</body>
</html>
