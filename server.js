const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const axios = require('axios');
const crypto = require('crypto');

const PORT = process.env.PORT || 3000;
const PHP_LOG_ENDPOINT = process.env.PHP_LOG_ENDPOINT || 'http://localhost/wave_project/ad_dashboard.php';
const SOCKET_SECRET = process.env.SOCKET_SECRET || 'dev_socket_secret_please_change';

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
    const serverHmac = crypto.createHmac('sha256', SOCKET_SECRET).update(user + '|' + role + '|' + String(Math.floor(Date.now() / 1000))).digest('hex');
    // allow slight clock skew by accepting serverHmac or previous-second hmac
    const serverHmacPrev = crypto.createHmac('sha256', SOCKET_SECRET).update(user + '|' + role + '|' + String(Math.floor(Date.now() / 1000) - 1)).digest('hex');
    if (hmac !== serverHmac && hmac !== serverHmacPrev) {
      console.warn('invalid token for', user, 'role', role, 'from', socket.id);
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
        io.emit('log.event', { type: 'action', message: desc, user: user, role: role, ts: payload.ts, origin: (payload.origin || 'socket') });
      } catch (err) { /* non-fatal */ }
    } catch (e) { console.warn('sensor.change handler error', e); }
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

