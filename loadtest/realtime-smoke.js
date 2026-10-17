import http from 'k6/http';
import { check } from 'k6';
import { WebSocket } from 'k6/websockets';

export const options = {
  vus: 1,
  iterations: 1,
  thresholds: { checks: ['rate==1'] },
};

const base = __ENV.BASE_URL || 'http://app:8000';
const wsBase = __ENV.REVERB_WS_URL || 'ws://reverb:8080';
const venueId = Number(__ENV.VENUE_ID || 1);
const slotId = Number(__ENV.SLOT_ID || 1);
const partySize = Number(__ENV.PARTY_SIZE || 2);

export default function () {
  const login = http.post(`${base}/api/auth/token`, JSON.stringify({
    email: __ENV.DEMO_EMAIL || 'demo@dorsia.test',
    password: __ENV.DEMO_PASSWORD || 'password',
    device_name: 'realtime-smoke',
  }), { headers: { 'Content-Type': 'application/json', Accept: 'application/json' } });
  check(login, { 'demo login succeeds': (r) => r.status === 201 });
  if (login.status !== 201) return;
  const token = login.json('data.token');
  const memberId = login.json('data.member.id');
  const appKey = __ENV.REVERB_APP_KEY || 'dorsia-key';
  const ws = new WebSocket(`${wsBase}/app/${appKey}?protocol=7&client=js&version=8.4.0&flash=false`);
  let subscriptions = 0;
  let holdSent = false;
  let received = false;

  ws.addEventListener('open', () => {
    setTimeout(() => {
      if (!received) {
        check(false, { 'slot.held arrives within five seconds with requested slot_id': () => false });
        ws.close();
      }
    }, 5000);
  });

  ws.addEventListener('message', (message) => {
    let envelope;
    try { envelope = JSON.parse(message.data); } catch (_) { return; }
    if (envelope.event === 'pusher:connection_established') {
      const socketId = JSON.parse(envelope.data).socket_id;
      const privateChannel = `private-member.${memberId}`;
      const auth = http.post(`${base}/broadcasting/auth`, {
        socket_id: socketId,
        channel_name: privateChannel,
      }, { headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/x-www-form-urlencoded' } });
      check(auth, { 'private channel auth succeeds': (r) => r.status === 200 });
      if (auth.status !== 200) { ws.close(); return; }
      ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: `venue.${venueId}.slots` } }));
      ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: privateChannel, auth: auth.json('auth') } }));
    }
    if (envelope.event === 'pusher_internal:subscription_succeeded') {
      subscriptions++;
      if (subscriptions === 2 && !holdSent) {
        holdSent = true;
        if (__ENV.SMOKE_STUB === '1') return;
        const hold = http.post(`${base}/api/holds`, JSON.stringify({ slot_id: slotId, party_size: partySize }), {
          headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json', 'Idempotency-Key': `realtime-smoke-${Date.now()}` },
        });
        check(hold, { 'hold request succeeds': (r) => r.status === 201 });
        if (hold.status !== 201) ws.close();
      }
    }
    if (envelope.event === 'slot.held') {
      const payload = typeof envelope.data === 'string' ? JSON.parse(envelope.data) : envelope.data;
      if (Number(payload.slot_id) === slotId) {
        received = true;
        check(true, { 'slot.held arrives within five seconds with requested slot_id': (value) => value });
        ws.close();
      }
    }
  });
  ws.addEventListener('error', () => {
    check(false, { 'websocket connects and stays healthy': () => false });
  });
}
