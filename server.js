const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const axios = require('axios');

const PORT = process.env.PORT || 3000;
const PHP_LOG_ENDPOINT = process.env.PHP_LOG_ENDPOINT || 'http://localhost/wave_project/ad_dashboard.php';
const SOCKET_SECRET = process.env.SOCKET_SECRET || 'dev_socket_secret_please_change';

const app = express();
const server = http.createServer(app);
const io = new Server(server, {
  cors: { origin: true }
});

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
    const serverHmac = require('crypto').createHmac('sha256', SOCKET_SECRET).update(user + '|' + role + '|' + String(Math.floor(Date.now()/1000))).digest('hex');
    // allow slight clock skew by accepting serverHmac or previous-second hmac
    const serverHmacPrev = require('crypto').createHmac('sha256', SOCKET_SECRET).update(user + '|' + role + '|' + String(Math.floor(Date.now()/1000) - 1)).digest('hex');
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

  socket.on('sensor.change', async (payload) => {
    console.log(new Date().toISOString(), 'sensor.change received from', socket.id, payload);
    try {
      payload.ts = payload.ts || Date.now();
      // broadcast to others
      socket.broadcast.emit('sensor.change', payload);
      // optional: forward to PHP for DB logging
      try {
        await axios.post(PHP_LOG_ENDPOINT, new URLSearchParams({
          log_to_event_log: '1',
          user: payload.user || socket.data.user || 'unknown',
          role: payload.role || socket.data.role || 'USER',
          ts: String(payload.ts),
          desc: `${payload.user || socket.data.user || 'unknown'} toggled ${payload.key} ${payload.value ? 'ON' : 'OFF'}`,
          status: 'ACTION',
          event_source: 'socket'
        }).toString(), { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
      } catch (err) {
        console.warn('PHP POST failed', err.message);
      }
    } catch (e) { console.warn('sensor.change handler error', e); }
  });

  socket.on('vessel.change', async (payload) => {
    console.log(new Date().toISOString(), 'vessel.change received from', socket.id, payload);
    try {
      payload.ts = payload.ts || Date.now();
      socket.broadcast.emit('vessel.change', payload);
      // forward to PHP
      try {
        await axios.post(PHP_LOG_ENDPOINT, new URLSearchParams({
          log_to_event_log: '1',
          user: payload.user || socket.data.user || 'unknown',
          role: payload.role || socket.data.role || 'USER',
          ts: String(payload.ts),
          desc: `${payload.user || socket.data.user || 'unknown'} changed vessel state to ${payload.state}`,
          status: 'ALERT',
          event_source: 'socket'
        }).toString(), { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
      } catch (err) { console.warn('PHP POST failed', err.message); }
    } catch (e) { console.warn('vessel.change handler error', e); }
  });

  socket.on('log.event', async (payload) => {
    console.log(new Date().toISOString(), 'log.event received from', socket.id, payload);
    try {
      // broadcast log event to other clients
      socket.broadcast.emit('log.event', payload);
      // forward to PHP for DB logging
      try {
        await axios.post(PHP_LOG_ENDPOINT, new URLSearchParams({
          log_to_event_log: '1',
          user: payload.user || socket.data.user || 'unknown',
          role: payload.role || socket.data.role || 'USER',
          ts: String(payload.ts || Date.now()),
          desc: payload.message || payload.desc || '',
          status: (payload.type || 'INFO').toString(),
          event_source: 'socket'
        }).toString(), { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
      } catch (err) { console.warn('PHP POST failed', err.message); }
    } catch (e) { console.warn('log.event handler error', e); }
  });

  socket.on('sensors.bulk', async (payload) => {
    try {
      // Broadcast individual sensor.change for UI convenience
      const keys = Array.isArray(payload.keys) ? payload.keys : [];
      keys.forEach(k => {
        socket.broadcast.emit('sensor.change', { key: k, value: !!payload.value, user: payload.user || socket.data.user, role: payload.role || socket.data.role, ts: payload.ts || Date.now() });
      });
      // forward one summary row to PHP
      try {
        await axios.post(PHP_LOG_ENDPOINT, new URLSearchParams({
          log_to_event_log: '1',
          user: payload.user || socket.data.user || 'unknown',
          role: payload.role || socket.data.role || 'USER',
          ts: String(payload.ts || Date.now()),
          desc: `${payload.user || socket.data.user || 'unknown'} set ALL SENSORS ${payload.value ? 'ON' : 'OFF'}`,
          status: 'ACTION',
          event_source: 'socket'
        }).toString(), { headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
      } catch (err) { console.warn('PHP POST failed', err.message); }
    } catch (e) { console.warn('sensors.bulk handler error', e); }
  });

  socket.on('disconnect', () => { console.log('client disconnected', socket.id); });
});

server.listen(PORT, '0.0.0.0', () => console.log(`Realtime server running on http://0.0.0.0:${PORT}`));
