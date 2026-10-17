import http from 'k6/http';
import { check, fail } from 'k6';
import { WebSocket } from 'k6/websockets';

export const options = { vus: 1, iterations: 1 };

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
  check(login, { 'demo login succeeds': (r) => r.status === 201 }) || fail('login failed');
  const body = login.json('data');
  const token = body.token;
  const memberId = body.member.id;
  const appKey = __ENV.REVERB_APP_KEY || 'dorsia-key';
  const ws = new WebSocket(`${wsBase}/app/${appKey}?protocol=7&client=js&version=8.4.0&flash=false`);
  let socketId;
  let held = false;
  let holdSent = false;
  let finished = false;
  let holdResponse;

  ws.onopen = () => ws.setTimeout(() => {
    if (!finished) {
      finished = true;
      ws.close();
      fail('slot.held did not arrive within five seconds');
    }
  }, 5000);

  ws.onmessage = (message) => {
    const envelope = JSON.parse(message.data);
    if (envelope.event === 'pusher:connection_established') {
      socketId = JSON.parse(envelope.data).socket_id;
      const privateChannel = `private-member.${memberId}`;
      const auth = http.post(`${base}/broadcasting/auth`, {
        socket_id: socketId,
        channel_name: privateChannel,
      }, { headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/x-www-form-urlencoded' } });
      check(auth, { 'private channel auth succeeds': (r) => r.status === 200 }) || fail('private channel auth failed');
      ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: `venue.${venueId}.slots` } }));
      ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: privateChannel, auth: auth.json('auth') } }));
    }
    if (envelope.event === 'pusher_internal:subscription_succeeded' && !holdSent) {
      // Wait until both venue and private subscriptions are acknowledged.
      ws.subscriptions = (ws.subscriptions || 0) + 1;
      if (ws.subscriptions === 2) {
        holdSent = true;
        holdResponse = http.post(`${base}/api/holds`, JSON.stringify({ slot_id: slotId, party_size: partySize }), {
          headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json', 'Idempotency-Key': `realtime-smoke-${Date.now()}` },
        });
        check(holdResponse, { 'hold request succeeds': (r) => r.status === 201 }) || fail(`hold failed: ${holdResponse.status}`);
      }
    }
    if (envelope.event === 'slot.held') {
      const payload = typeof envelope.data === 'string' ? JSON.parse(envelope.data) : envelope.data;
      if (payload.slot_id === slotId) {
        held = true;
        finished = true;
        ws.close();
      }
    }
  };

  ws.connect();
  check(held, { 'slot.held arrives with requested slot_id': (value) => value === true });
}
