import test from "node:test";
import assert from "node:assert/strict";
import {
  ApiClient,
  ApiError,
  DemoClient,
  newOperation,
  confirmationOperation,
  errorMessage,
  testCardToken,
  spendPlan,
  eligibleSlots,
  localTime,
  displayDate,
  dateInZone,
  venueDateTime,
  addDays,
  escapeHtml,
  remainingSeconds,
  isUpcoming,
  isCancelled,
  reservationLabel,
} from "../../public/experience/core.mjs";
import { OperationsStore } from "../../public/experience/operations.mjs";
const storage = () => {
  const data = new Map();
  return { getItem: (k) => data.get(k), setItem: (k, v) => data.set(k, v) };
};
const future = () => addDays(dateInZone(), 2);
const response = (data, status = 200) => ({
  ok: status < 400,
  status,
  json: async () => data,
});
async function holdFor(client, venue = 1, hour = 0) {
  const availability = await client.request(
    `/venues/${venue}/availability?date=${future()}&hour=19&party_size=2`,
  );
  return client.write(
    newOperation("/holds", {
      slot_id: availability.slots[hour].id,
      party_size: 2,
    }),
  );
}

test("money applies coupons then credit with integer remainders", () => {
  assert.deepEqual(
    spendPlan(75101, { coupon_cents: 10002, credit_cents: 20003 }),
    { coupon_cents: 10002, credit_cents: 20003, card_cents: 45096 },
  );
  assert.deepEqual(spendPlan(999, { coupon_cents: 2000, credit_cents: 4000 }), {
    coupon_cents: 999,
    credit_cents: 0,
    card_cents: 0,
  });
});
test("availability removes sold out, unreleased, and wrong party bands", () => {
  const base = {
    id: 1,
    available: 1,
    bookable: true,
    min_party_size: 1,
    max_party_size: 2,
  };
  assert.deepEqual(
    eligibleSlots(
      [
        base,
        { ...base, id: 2, available: 0 },
        { ...base, id: 3, bookable: false },
        { ...base, id: 4, min_party_size: 3, max_party_size: 4 },
      ],
      2,
    ),
    [base],
  );
});
test("venue-local time survives device timezone and DST boundaries", () => {
  assert.equal(localTime("2026-11-08T20:00:00-05:00"), "8:00 PM");
  assert.equal(localTime("2026-11-08T00:30:00-05:00"), "12:30 AM");
  assert.equal(displayDate("2026-11-08T20:00:00-05:00"), "Sun, Nov 8");
  assert.equal(venueDateTime("2026-03-07", 19), "2026-03-07T19:00:00-05:00");
  assert.equal(venueDateTime("2026-03-08", 19), "2026-03-08T19:00:00-04:00");
  assert.equal(venueDateTime("2026-10-31", 19), "2026-10-31T19:00:00-04:00");
  assert.equal(venueDateTime("2026-11-01", 19), "2026-11-01T19:00:00-05:00");
  assert.equal(
    venueDateTime("2026-11-01", 19, "Pacific/Honolulu"),
    "2026-11-01T19:00:00-10:00",
  );
});
test("hold countdown uses the earlier quote or hold expiry", () => {
  const now = Date.now();
  assert.equal(
    remainingSeconds({
      expires_at: new Date(now + 60000).toISOString(),
      quote: { expires_at: new Date(now - 1).toISOString() },
    }),
    0,
  );
});
test("both contract cancellation statuses display as cancelled, including future bookings", () => {
  const futureBooking = {
    starts_at: new Date(Date.now() + 86400000).toISOString(),
  };
  for (const status of ["cancelled_early", "cancelled_late", "cancelled"]) {
    const booking = { ...futureBooking, status };
    assert.equal(isCancelled(booking), true);
    assert.equal(isUpcoming(booking), false);
    assert.equal(reservationLabel(booking), "Cancelled");
  }
  assert.equal(
    reservationLabel({ ...futureBooking, status: "confirmed" }),
    "Confirmed",
  );
  assert.equal(
    reservationLabel({
      starts_at: "2020-01-01T00:00:00Z",
      status: "confirmed",
    }),
    "Past visit",
  );
});
test("API login uses documented body and a memory-only bearer for subsequent reads", async () => {
  const calls = [];
  const api = new ApiClient(async (path, options) => {
    calls.push({ path, options });
    return response(
      path.endsWith("/token")
        ? { data: { token: "test-token", member: { id: 1, name: "Ada" } } }
        : { data: [] },
      path.endsWith("/token") ? 201 : 200,
    );
  });
  assert.equal(
    (await api.login("ada@example.test", "test-password")).name,
    "Ada",
  );
  await api.request("/venues");
  assert.equal(calls[0].path, "/api/auth/token");
  assert.deepEqual(JSON.parse(calls[0].options.body), {
    email: "ada@example.test",
    password: "test-password",
    device_name: "Dorsia web experience",
  });
  assert.equal(calls[0].options.headers.Authorization, undefined);
  assert.equal(calls[1].options.headers.Authorization, "Bearer test-token");
  api.logout();
  await api.request("/me");
  assert.equal(calls[2].options.headers.Authorization, undefined);
});
test("network loss retries exactly the same write body and idempotency key", async () => {
  const calls = [];
  const api = new ApiClient(async (path, options) => {
    calls.push({ path, options });
    if (calls.length === 1) throw new TypeError("offline");
    return response({ data: { id: 42 } }, 201);
  });
  const body = { hold_id: 8, card_token: null };
  const operation = newOperation("/reservations", body, "booking-retry");
  body.hold_id = 99;
  await assert.rejects(
    api.write(operation),
    (e) => e.code === "network_error" && e.uncertain,
  );
  assert.equal((await api.write(operation)).id, 42);
  assert.equal(calls[0].options.body, calls[1].options.body);
  assert.equal(calls[1].options.body, '{"hold_id":8,"card_token":null}');
  assert.equal(calls[0].options.headers["Idempotency-Key"], "booking-retry");
  assert.equal(calls[1].options.headers["Idempotency-Key"], "booking-retry");
});
test("card checkout requires a method only for the remainder and freezes it for retries", async () => {
  const hold = { id: 21, quote: { amount_cents: 75000 } };
  const wallet = { coupon_cents: 10000, credit_cents: 20000 };
  assert.throws(
    () => confirmationOperation(hold, wallet),
    (e) => e.code === "card_required",
  );
  const operation = confirmationOperation(hold, wallet, "tok_ok");
  assert.deepEqual(JSON.parse(operation.body), {
    hold_id: 21,
    card_token: "tok_ok",
  });
  assert.ok(Object.isFrozen(operation));
  hold.id = 99;
  wallet.credit_cents = 0;
  const calls = [];
  const api = new ApiClient(async (path, options) => {
    calls.push(options);
    if (calls.length === 1) throw new TypeError("response lost");
    return response({ data: { id: 7 } }, 201);
  });
  await assert.rejects(api.write(operation), (e) => e.uncertain);
  await api.write(operation);
  assert.equal(calls[0].body, calls[1].body);
  assert.equal(
    calls[0].headers["Idempotency-Key"],
    calls[1].headers["Idempotency-Key"],
  );
  assert.equal(JSON.parse(calls[1].body).card_token, "tok_ok");
  assert.equal(
    JSON.parse(
      confirmationOperation(
        hold,
        { coupon_cents: 75000, credit_cents: 0 },
        "tok_ok",
      ).body,
    ).card_token,
    null,
  );
});
test("decline details produce an actionable insufficient-funds message", async () => {
  const api = new ApiClient(async () =>
    response(
      {
        error: {
          code: "payment_failed",
          message: "Declined",
          details: { decline_code: "insufficient_funds" },
        },
      },
      402,
    ),
  );
  await assert.rejects(api.request("/reservations"), (e) => {
    assert.equal(e.details.decline_code, "insufficient_funds");
    assert.match(errorMessage(e), /insufficient funds/);
    assert.equal(e.uncertain, false);
    return true;
  });
});
test("test-card choices only map to the existing gateway tokens", () => {
  assert.equal(testCardToken("approved"), "tok_ok");
  assert.equal(testCardToken("declined"), "tok_decline");
  assert.equal(testCardToken("insufficient"), "tok_insufficient_funds");
  assert.equal(testCardToken("timeout"), "tok_timeout");
  assert.equal(testCardToken("4111111111111111"), null);
  assert.equal(testCardToken(""), null);
});
test("preview card declines and timeouts release the hold without spending the wallet", async () => {
  for (const [method, code] of [
    ["declined", "payment_failed"],
    ["insufficient", "payment_failed"],
    ["timeout", "payment_timeout"],
  ]) {
    const client = new DemoClient(storage());
    const wallet = await client.request("/wallet");
    const hold = await holdFor(client);
    const operation = confirmationOperation(
      hold,
      wallet,
      testCardToken(method),
    );
    await assert.rejects(client.write(operation), (e) => e.code === code);
    assert.equal(client.state.holds[hold.id].status, "released");
    assert.deepEqual(await client.request("/wallet"), wallet);
    assert.equal((await client.request("/reservations")).length, 0);
    assert.equal((await holdFor(client)).status, "active");
  }
});
test("API errors never produce fake success and distinguish pending operations", async () => {
  for (const [code, status, uncertain] of [
    ["sold_out", 409, false],
    ["idempotency_in_progress", 409, true],
    ["payment_in_progress", 409, true],
    ["payment_failed", 402, false],
    ["unauthenticated", 401, false],
    ["server_error", 500, true],
  ]) {
    const api = new ApiClient(async () =>
      response({ error: { code, message: "A service error" } }, status),
    );
    await assert.rejects(
      api.request("/holds/2"),
      (e) =>
        e instanceof ApiError && e.code === code && e.uncertain === uncertain,
    );
  }
});
test("malformed response and missing data become recoverable errors", async () => {
  const invalid = new ApiClient(async () => ({
    ok: true,
    status: 200,
    json: async () => {
      throw new Error("html");
    },
  }));
  await assert.rejects(
    invalid.request("/wallet"),
    (e) => e.code === "service_unavailable" && e.uncertain,
  );
  const missing = new ApiClient(async () => response({}));
  await assert.rejects(missing.request("/wallet"), (e) => e.status === 502);
});
test("booking preview supports hold, confirm replay, cancel replay, and persistent wallet", async () => {
  const disk = storage(),
    client = new DemoClient(disk),
    hold = await holdFor(client);
  assert.equal(hold.quote.amount_cents, 50000);
  const operation = newOperation(
    "/reservations",
    { hold_id: hold.id, card_token: "tok_ok" },
    "confirm-1",
  );
  const reservation = await client.write(operation);
  assert.deepEqual(await client.write(operation), reservation);
  assert.deepEqual(reservation.payment, {
    coupon_cents: 10000,
    credit_cents: 30000,
    card_cents: 10000,
  });
  assert.equal((await client.request("/reservations")).length, 1);
  assert.equal((await client.request("/wallet")).credit_cents, 0);
  const cancellation = newOperation(
    `/reservations/${reservation.id}/cancel`,
    {},
    "cancel-1",
  );
  const result = await client.write(cancellation);
  assert.equal(result.refund.credit_cents, 50000);
  assert.equal(result.refund.late, false);
  assert.deepEqual(await client.write(cancellation), result);
  assert.equal((await client.request("/wallet")).credit_cents, 50000);
  const reloaded = new DemoClient(disk);
  assert.equal(
    (await reloaded.request("/reservations"))[0].status,
    "cancelled_early",
  );
  assert.equal((await reloaded.request("/wallet")).credit_cents, 50000);
});
test("expired holds and changed idempotency bodies cannot book", async () => {
  const client = new DemoClient(storage());
  const hold = await holdFor(client);
  client.state.holds[hold.id].expires_at = new Date(0).toISOString();
  await assert.rejects(
    client.write(
      newOperation("/reservations", { hold_id: hold.id, card_token: "tok_ok" }),
    ),
    (e) => e.code === "hold_expired",
  );
  const second = await holdFor(client, 2);
  const operation = newOperation(
    "/reservations",
    { hold_id: second.id, card_token: "tok_ok" },
    "same-key",
  );
  await client.write(operation);
  await assert.rejects(
    client.write(
      newOperation(
        "/reservations",
        { hold_id: 999, card_token: "tok_ok" },
        "same-key",
      ),
    ),
    (e) => e.code === "idempotency_mismatch",
  );
});
test("preview rejects sold out slots and parties that do not fit", async () => {
  const client = new DemoClient(storage());
  const availability = await client.request(
    `/venues/2/availability?date=${future()}&hour=19&party_size=2`,
  );
  await assert.rejects(
    client.write(
      newOperation("/holds", {
        slot_id: availability.slots[2].id,
        party_size: 2,
      }),
    ),
    (e) => e.code === "sold_out",
  );
  await assert.rejects(
    client.write(
      newOperation("/holds", {
        slot_id: availability.slots[0].id,
        party_size: 6,
      }),
    ),
    (e) => e.code === "party_size_not_allowed",
  );
});
test("operations service transitions, notes, and capacity constraints survive reload", () => {
  const disk = storage(),
    ops = new OperationsStore(disk);
  ops.transition(1, "arrived");
  ops.transition(1, "seated");
  assert.throws(
    () => ops.transition(1, "confirmed"),
    (e) => e.code === "invalid_transition",
  );
  ops.updateGuest(1, { notes: "Anniversary welcome arranged.", table: "12" });
  const slot = ops.data.inventory[0];
  assert.throws(
    () => ops.updateCapacity(slot.id, 1, false),
    (e) => e.code === "invalid_capacity",
  );
  assert.throws(
    () => ops.updateCapacity(slot.id, 8.5, false),
    (e) => e.code === "invalid_capacity",
  );
  ops.updateCapacity(slot.id, 12, true);
  const reloaded = new OperationsStore(disk);
  assert.equal(reloaded.data.guests[0].status, "seated");
  assert.equal(reloaded.data.guests[0].table, "12");
  assert.equal(reloaded.data.inventory[0].capacity, 12);
  assert.equal(reloaded.data.inventory[0].paused, true);
});
test("exceptions require a resolution and record the handoff once", () => {
  const ops = new OperationsStore(storage());
  assert.throws(
    () => ops.resolve(1, "ok"),
    (e) => e.code === "reason_required",
  );
  ops.resolve(1, "Kitchen confirmed a separate preparation area.");
  assert.equal(ops.data.exceptions[0].status, "resolved");
  assert.equal(ops.data.audit.length, 1);
  assert.throws(
    () => ops.resolve(1, "Again"),
    (e) => e.code === "invalid_exception",
  );
});
test("unavailable storage keeps a functional in-memory preview", async () => {
  const disk = {
    getItem() {
      throw new Error("denied");
    },
    setItem() {
      throw new Error("denied");
    },
  };
  const client = new DemoClient(disk);
  await holdFor(client);
  assert.equal(client.persistent, false);
  const ops = new OperationsStore(disk);
  ops.transition(1, "arrived");
  assert.equal(ops.persistent, false);
  assert.equal(ops.data.guests[0].status, "arrived");
});
test("untrusted names and service notes are escaped for HTML rendering", () => {
  assert.equal(
    escapeHtml('<img src=x onerror="alert(1)">'),
    "&lt;img src=x onerror=&quot;alert(1)&quot;&gt;",
  );
  assert.equal(escapeHtml("O'Neil & Co"), "O&#39;Neil &amp; Co");
});

test("preview IDs remain unique when requests complete within one millisecond", async () => {
  const original = Date.now;
  const frozen = original();
  Date.now = () => frozen;
  try {
    const client = new DemoClient(storage());
    const first = await holdFor(client, 1);
    const second = await holdFor(client, 2);
    assert.notEqual(first.id, second.id);
    assert.equal(Object.keys(client.state.holds).length, 2);
  } finally {
    Date.now = original;
  }
});

test("development proxy reports an unreachable API as retryable JSON, not a missing page", async () => {
  const { spawn } = await import("node:child_process");
  const child = spawn(
    process.execPath,
    [new URL("./serve.mjs", import.meta.url).pathname],
    {
      env: {
        ...process.env,
        PORT: "0",
        DORSIA_API_ORIGIN: "http://127.0.0.1:1",
      },
      stdio: ["ignore", "pipe", "pipe"],
    },
  );
  try {
    const address = await new Promise((resolve, reject) => {
      const timer = setTimeout(
        () => reject(new Error("Preview server did not start")),
        5000,
      );
      child.once("error", (error) => {
        clearTimeout(timer);
        reject(error);
      });
      child.stdout.once("data", (data) => {
        clearTimeout(timer);
        resolve(data.toString().match(/http:\/\/127\.0\.0\.1:\d+/)[0]);
      });
    });
    const result = await fetch(`${address}/api/reservations`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Idempotency-Key": "proxy-retry",
      },
      body: "{}",
    });
    assert.equal(result.status, 502);
    assert.equal((await result.json()).error.code, "service_unavailable");
  } finally {
    child.kill();
  }
});
