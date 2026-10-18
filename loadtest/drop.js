import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';

const confirmed = new Counter('confirmed');
const soldOut = new Counter('sold_out');
const serverErrors = new Counter('server_errors');
const unexpected = new Counter('unexpected');

export const options = {
  scenarios: {
    drop: {
      executor: 'per-vu-iterations',
      vus: Number(__ENV.DROP_VUS || 5000),
      iterations: 1,
      maxDuration: '3m',
    },
  },
  thresholds: {
    'http_req_duration{name:hold}': ['p(95)<300'],
    'http_req_duration{name:confirm}': ['p(95)<300'],
    server_errors: ['count==0'],
    unexpected: ['count==0'],
    confirmed: ['count==50'],
  },
};

const base = __ENV.BASE_URL || 'http://app:8000';
const tokens = JSON.parse(open('/loadtest/.data/tokens.json'));
const drop = JSON.parse(open('/loadtest/.data/drop.json'));

function uuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = Math.floor(Math.random() * 16);
    return (c === 'x' ? r : ((r & 0x3) | 0x8)).toString(16);
  });
}

function request(url, body, token, key, name) {
  const response = http.post(url, JSON.stringify(body), {
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'Idempotency-Key': key,
    },
    tags: { name },
  });
  if (response.status >= 500) serverErrors.add(1);
  return response;
}

function sameResponse(first, retry, label) {
  const equal = first.status === retry.status && first.body === retry.body;
  check(equal, { [`${label} same-key retry is byte-identical`]: () => equal });
  if (!equal) unexpected.add(1);
  return equal;
}

export default function () {
  const member = tokens[(__VU - 1) % tokens.length];
  const delay = Math.random() * 10;
  const wait = Date.parse(drop.opens_at) - Date.now() + delay * 1000;
  if (wait > 0) sleep(wait / 1000);

  const holdBody = { slot_id: drop.slot_id, party_size: 2 };
  const holdKey = uuid();
  const hold = request(`${base}/api/holds`, holdBody, member.token, holdKey, 'hold');
  const holdRetry = request(`${base}/api/holds`, holdBody, member.token, holdKey, 'hold');
  if (!sameResponse(hold, holdRetry, 'hold')) return;

  if (hold.status === 409 && hold.json('error.code') === 'sold_out') {
    soldOut.add(1);
    return;
  }
  if (hold.status !== 201) {
    unexpected.add(1);
    return;
  }

  const confirmBody = { hold_id: hold.json('data.id'), card_token: 'tok_ok' };
  const confirmKey = uuid();
  const confirm = request(`${base}/api/reservations`, confirmBody, member.token, confirmKey, 'confirm');
  const confirmRetry = request(`${base}/api/reservations`, confirmBody, member.token, confirmKey, 'confirm');
  if (!sameResponse(confirm, confirmRetry, 'confirm')) return;

  if (confirm.status === 201) {
    confirmed.add(1);
    return;
  }
  if (confirm.status === 409 && confirm.json('error.code') === 'sold_out') {
    soldOut.add(1);
    return;
  }
  unexpected.add(1);
}

export function handleSummary(data) {
  return {
    [`/loadtest/results/${new Date().toISOString().replace(/[:.]/g, '-')}.json`]: JSON.stringify(data, null, 2),
    stdout: JSON.stringify({
      confirmed: data.metrics.confirmed?.values.count ?? 0,
      sold_out: data.metrics.sold_out?.values.count ?? 0,
      server_errors: data.metrics.server_errors?.values.count ?? 0,
      unexpected: data.metrics.unexpected?.values.count ?? 0,
      hold_p95_ms: data.metrics['http_req_duration{name:hold}']?.values['p(95)'] ?? null,
      confirm_p95_ms: data.metrics['http_req_duration{name:confirm}']?.values['p(95)'] ?? null,
    }, null, 2),
  };
}
