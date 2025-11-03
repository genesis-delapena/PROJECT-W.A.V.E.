const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const axios = require('axios');
const crypto = require('crypto');

const PORT = process.env.PORT || 3000;
const PHP_LOG_ENDPOINT = process.env.PHP_LOG_ENDPOINT || 'http://localhost/wave_project/ad_dashboard.php';
const SOCKET_SECRET = process.env.SOCKET_SECRET || 'dev_socket_secret_please_change';
// Bridge endpoints for polling RPi server (Server_PC.py)
const RPI_BRIDGE = process.env.RPI_BRIDGE || 'http://192.168.0.2:5000';

const app = express();
const server = http.createServer(app);
const io = new Server(server, { cors: { origin: true } });

// Simple status endpoint to verify the server is reachable
app.get('/status', (req, res) => {
  res.json({ status: 'ok', ts: Date.now() });
});

io.on('connection', (socket) => {
  // Verify token in socket handshake (auth.token expected in format: hmac::username::role::timestampClient)
  try {
    const authToken = socket.handshake.auth && socket.handshake.auth.token;
    if (!authToken || typeof authToken !== 'string') {
      console.warn('rejecting connection without token', socket.id);
      socket.disconnect(true);
      return;
    }
    const parts = authToken.split('::');
    if (parts.length < 4) { socket.disconnect(true); return; }
    const [hmac, user, role, tsClient] = parts;
    // Allow a small clock skew window when validating the HMAC token. PHP may
    // have generated the token a few seconds before the client connected, and
    // small clock differences between machines can cause verification to fail.
    const nowSec = Math.floor(Date.now() / 1000);
    let tokenOk = false;
    const maxSkewSeconds = 5; // accept tokens signed within the last N seconds
    for (let s = 0; s <= maxSkewSeconds; s++) {
      const candidate = crypto.createHmac('sha256', SOCKET_SECRET).update(user + '|' + role + '|' + String(nowSec - s)).digest('hex');
      if (hmac === candidate) { tokenOk = true; break; }
    }
    if (!tokenOk) {
      console.warn('invalid token for', user, 'role', role, 'from', socket.id, '(possible clock skew or mismatched secret)');
      // Optional debug output: enable by setting DEBUG_SOCKET_TOKENS=1 in the Node environment
      try {
        if (process.env.DEBUG_SOCKET_TOKENS === '1') {
          console.log('DEBUG_SOCKET_TOKENS: received auth token:', authToken);
          console.log('DEBUG_SOCKET_TOKENS: parsed parts:', { hmac, user, role, tsClient });
          // compute expected hmac for the client-supplied ts (if numeric)
          const expectedClient = crypto.createHmac('sha256', SOCKET_SECRET).update(user + '|' + role + '|' + String(tsClient)).digest('hex');
          console.log('DEBUG_SOCKET_TOKENS: expected hmac for client ts:', expectedClient);
          console.log('DEBUG_SOCKET_TOKENS: server nowSec:', nowSec, 'client ts:', tsClient, 'diff:', (Number(nowSec) - Number(tsClient || 0)));
        }
      } catch (dbgErr) { /* ignore debug logging errors */ }
      socket.emit('auth.error', { msg: 'invalid token' });
      socket.disconnect(true);
      return;
    }
    socket.data.user = user;
    socket.data.role = role;
  } catch (e) {
    console.warn('token verification error', e);
    socket.disconnect(true);
    return;
  }

  console.log(new Date().toISOString(), 'client connected', socket.id, 'user=', socket.data.user, 'role=', socket.data.role);

  // sensor change
  socket.on('sensor.change', async (payload) => {
    console.log(new Date().toISOString(), 'sensor.change received from', socket.id, payload);
    try {
      payload.ts = payload.ts || Date.now();
      // broadcast the raw sensor change for UI updates to other clients
      socket.broadcast.emit('sensor.change', payload);

      // Build authoritative user/role from socket authentication (server-side)
      const user = socket.data.user || payload.user || 'unknown';
      const role = (socket.data.role || payload.role || 'USER').toUpperCase();
      const keyLabel = String(payload.key || '').replace(/_/g, ' ').toLowerCase();

      // Compose a canonical description used both for DB and client display
      const humanKey = payload.key ? payload.key.toUpperCase() : (keyLabel ? keyLabel.toUpperCase() : 'UNKNOWN');
      const desc = `[${role}] ${humanKey} sensor turned ${payload.value ? 'ON' : 'OFF'} by ${user}`;

      // forward to PHP for DB logging (best-effort)
      try {
        await axios.post(PHP_LOG_ENDPOINT, new URLSearchParams({
          log_to_event_log: '1',
          user: user,
          role: role,
          ts: String(payload.ts),
          desc: desc,
          status: 'ACTION',
          event_source: 'socket'
        }).toString(), { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
      } catch (err) {
        console.warn('PHP POST failed', err.message);
      }

      // Broadcast a single normalized log.event (includes role and username) so all clients show the authoritative message
      try {
        const evt = { type: 'action', message: desc, user: user, role: role, ts: payload.ts, origin: (payload.origin || 'socket') };
        console.log(new Date().toISOString(), 'broadcasting log.event', evt);
        io.emit('log.event', evt);
      } catch (err) { /* non-fatal */ }
    } catch (e) { console.warn('sensor.change handler error', e); }
  });

  // presence announcements from clients (so clients can show 'active' status)
  socket.on('presence', payload => {
    try {
      console.log(new Date().toISOString(), 'presence from', socket.id, payload);
      // broadcast to other clients so they can update their UI
      socket.broadcast.emit('presence', payload);
    } catch (e) { console.warn('presence handler error', e); }
  });

  // state announcement: clients may announce their current local state on connect
  // so other clients can immediately adopt it (useful when joining without a full refresh)
  socket.on('announce.state', payload => {
    try {
      console.log(new Date().toISOString(), 'state announcement from', socket.id, payload && payload.user);
      // Broadcast to other clients so they can update UI/state without a refresh
      socket.broadcast.emit('state.announce', payload);
    } catch (e) { console.warn('announce.state handler error', e); }
  });

  // vessel change
  socket.on('vessel.change', async (payload) => {
    console.log(new Date().toISOString(), 'vessel.change received from', socket.id, payload);
    try {
      payload.ts = payload.ts || Date.now();
      socket.broadcast.emit('vessel.change', payload);
      // forward to PHP and broadcast a normalized log.event for clients
      try {
        const user = socket.data.user || payload.user || 'unknown';
        const role = (socket.data.role || payload.role || 'USER').toUpperCase();
        const state = String(payload.state || '').toLowerCase();
        const desc = `[${role}] Vessel ${state.toUpperCase()} by ${user}`;
        await axios.post(PHP_LOG_ENDPOINT, new URLSearchParams({
          log_to_event_log: '1',
          user: user,
          role: role,
          ts: String(payload.ts),
          desc: desc,
          status: 'ALERT',
          event_source: 'socket'
        }).toString(), { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
        // Broadcast canonical log.event to all clients (including originator)
        io.emit('log.event', { type: 'alert', message: desc, user: user, role: role, ts: payload.ts, origin: (payload.origin || 'socket') });
      } catch (err) { console.warn('PHP POST failed', err.message); }
    } catch (e) { console.warn('vessel.change handler error', e); }
  });

  // generic log events (clients may emit arbitrary messages which we clean and persist)
  socket.on('log.event', async (payload) => {
    console.log(new Date().toISOString(), 'log.event received from', socket.id, payload);
    try {
      // normalize message: remove any leading [ROLE] prefix and any trailing "by <user>" so we can rebuild
      const raw = String(payload.message || payload.desc || '').trim();
      const user = payload.user || socket.data.user || 'unknown';
      const role = (payload.role || socket.data.role || 'USER').toUpperCase();
      // strip leading bracketed prefix like [USER] or [ADMIN]
      let core = raw.replace(/^\[.*?\]\s*/g, '');
      // Remove any trailing "by ..." fragments to avoid double 'by' when rebuilding
      core = core.replace(/\s+by\s+.*$/i, '').trim();

      // Special-case login/logout so description becomes: "(username) logged in." or "(username) logged out."
      const lowCore = (core || '').toLowerCase();
      if (/^logged in$/i.test(lowCore) || /^logged out$/i.test(lowCore)) {
        const action = /^logged in$/i.test(lowCore) ? 'logged in' : 'logged out';
        const loginDesc = `(${user}) ${action}.`;
        payload.message = loginDesc;
        payload.desc = loginDesc;
      } else {
        const desc = `[${role}] ${core} by ${user}`.trim();
        // replace payload message with normalized desc so clients receive the cleaned form
        payload.message = desc;
        payload.desc = desc;
      }

      // Broadcast the cleaned log event to all clients (including originator)
  console.log(new Date().toISOString(), 'broadcasting log.event', payload);
  io.emit('log.event', payload);

      // forward to PHP for DB logging
      try {
        await axios.post(PHP_LOG_ENDPOINT, new URLSearchParams({
          log_to_event_log: '1',
          user: user,
          role: role,
          ts: String(payload.ts || Date.now()),
          desc: payload.desc,
          status: (payload.type || 'INFO').toString(),
          event_source: 'socket'
        }).toString(), { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
      } catch (err) { console.warn('PHP POST failed', err.message); }
    } catch (e) { console.warn('log.event handler error', e); }
  });

  // bulk sensors
  socket.on('sensors.bulk', async (payload) => {
    try {
      // Broadcast individual sensor.change for UI convenience
      const keys = Array.isArray(payload.keys) ? payload.keys : [];
      keys.forEach(k => {
        socket.broadcast.emit('sensor.change', { key: k, value: !!payload.value, user: payload.user || socket.data.user, role: payload.role || socket.data.role, ts: payload.ts || Date.now() });
      });
      // forward one summary row to PHP
      try {
        const user = socket.data.user || payload.user || 'unknown';
        const role = (socket.data.role || payload.role || 'USER').toUpperCase();
        const desc = `[${role}] all sensors turned ${payload.value ? 'ON' : 'OFF'} by ${user}`;
        await axios.post(PHP_LOG_ENDPOINT, new URLSearchParams({
          log_to_event_log: '1',
          user: user,
          role: role,
          ts: String(payload.ts || Date.now()),
          desc: desc,
          status: 'ACTION',
          event_source: 'socket'
        }).toString(), { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
        // Broadcast summary log to all clients (including originator)
        io.emit('log.event', { type: 'action', message: desc, user: user, role: role, ts: payload.ts || Date.now(), origin: (payload.origin || 'socket') });
      } catch (err) { console.warn('PHP POST failed', err.message); }
    } catch (e) { console.warn('sensors.bulk handler error', e); }
  });

  socket.on('disconnect', () => { console.log('client disconnected', socket.id); });
});

server.listen(PORT, '0.0.0.0', () => console.log(`Realtime server running on http://0.0.0.0:${PORT}`));

// Periodically poll the RPi bridge for latest sensor/IMU data and emit to connected clients
const POLL_INTERVAL_MS = Number(process.env.POLL_INTERVAL_MS || 800); // ~800ms
async function pollRpiBridge() {
  try {
    const resp = await axios.get(RPI_BRIDGE + '/get', { timeout: 1200 });
    if (resp && resp.data && resp.data.message) {
      const msg = resp.data.message || {};
      // If the RPi bridge includes an IMU block or a YAW_REL_DEG key, normalize and emit
      // Accept either msg.YAW_REL_DEG or msg.IMU && msg.IMU.YAW_REL_DEG
      let yaw = null;
      if (typeof msg.YAW_REL_DEG !== 'undefined') yaw = Number(msg.YAW_REL_DEG);
      else if (msg.IMU && typeof msg.IMU.YAW_REL_DEG !== 'undefined') yaw = Number(msg.IMU.YAW_REL_DEG);
      if (yaw !== null && !Number.isNaN(yaw)) {
        // Emit a dedicated imu.update event so clients can react to YAW changes
        io.emit('imu.update', { yaw_rel_deg: yaw, ts: Date.now(), raw: msg });
      }
      // Optionally emit the full sensor payload as sensor.bulk for UI convenience
      io.emit('rpi.sensors', { message: msg, ts: Date.now() });
    }
  } catch (e) {
    // non-fatal; log at debug level
    if (process.env.DEBUG_RPI_POLL === '1') console.warn('RPI poll error', e.message || e);
  } finally {
    setTimeout(pollRpiBridge, POLL_INTERVAL_MS);
  }
}

// Start polling after server is listening
setTimeout(pollRpiBridge, 500);

// Accept best-effort pushes from the Flask bridge (Server_PC.py)
app.post('/rpi/push', express.json({ limit: '64kb' }), (req, res) => {
  try {
    const body = req.body || {};
    const msg = body.message || body;
    // If present, pull yaw
    let yaw = null;
    if (typeof msg === 'object') {
      if (typeof msg.YAW_REL_DEG !== 'undefined') yaw = Number(msg.YAW_REL_DEG);
      else if (msg.IMU && typeof msg.IMU.YAW_REL_DEG !== 'undefined') yaw = Number(msg.IMU.YAW_REL_DEG);
    }
    if (yaw !== null && !Number.isNaN(yaw)) {
      io.emit('imu.update', { yaw_rel_deg: yaw, ts: Date.now(), raw: msg });
    }
    io.emit('rpi.sensors', { message: msg, ts: Date.now() });
    res.json({ status: 'ok' });
  } catch (e) {
    console.warn('rpi.push handler error', e && e.message);
    res.status(500).json({ status: 'error' });
  }
});

