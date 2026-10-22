import test from "node:test";
import assert from "node:assert/strict";
import {
  DemoClient,
  newOperation,
  cancellationOperation,
  cancellationPreviewDeadline,
  cancellationDeadlineLabel,
  demoCancellationPreview,
  errorMessage,
} from "../../public/experience/core.mjs";
const reservation = (at) => ({
  id: 1,
  status: "confirmed",
  starts_at: new Date(at + 8 * 3600000).toISOString(),
  min_spend_cents: 75001,
  currency: "USD",
});
test("cancellation binds the exact reviewed refund and immutable retry identity", () => {
  const at = Date.parse("2026-11-07T17:00:00Z");
  const preview = demoCancellationPreview(reservation(at), at);
  const operation = cancellationOperation(preview);
  const body = operation.body;
  preview.refund.credit_cents = 1;
  assert.equal(operation.body, body);
  assert.ok(Object.isFrozen(operation));
  assert.deepEqual(JSON.parse(body).expected_refund, {
    credit_cents: 75001,
    forfeited_cents: 0,
    currency: "USD",
    late: false,
  });
  assert.equal(operation.path, "/reservations/1/cancel");
});
test("incomplete or invalid previews cannot enable cancellation", () => {
  for (const preview of [
    null,
    {},
    { reservation_id: 1, refund: {} },
    {
      reservation_id: 1,
      refund: {
        credit_cents: -1,
        forfeited_cents: 1,
        currency: "USD",
        late: false,
      },
    },
  ])
    assert.throws(
      () => cancellationOperation(preview),
      (e) => e.code === "invalid_preview",
    );
});
test("deadline checking uses server evaluation plus monotonic elapsed time, not device clock", () => {
  const p = {
    refund: { late: false },
    evaluated_at: "2026-11-07T16:59:59Z",
    early_cancellation_deadline: "2026-11-07T17:00:00Z",
    starts_at: "2026-11-08T01:00:00Z",
  };
  assert.equal(cancellationPreviewDeadline(p, 500), 1500);
  assert.equal(
    cancellationPreviewDeadline(
      { ...p, refund: { late: true }, evaluated_at: "2026-11-08T00:59:59Z" },
      500,
    ),
    1500,
  );
  assert.equal(
    cancellationPreviewDeadline({ ...p, evaluated_at: "invalid" }, 500),
    500,
  );
});
test("the deadline label includes venue time zone and seconds", () => {
  const label = cancellationDeadlineLabel({
    early_cancellation_deadline: "2026-11-07T17:00:00Z",
    venue_timezone: "America/New_York",
  });
  assert.match(label, /12:00:00 PM/);
  assert.match(label, /EST/);
});
test("sample preview respects the exact cutoff and floors odd cents, and rejects started/cancelled bookings", () => {
  const at = Date.parse("2026-11-07T17:00:00Z");
  const r = reservation(at);
  assert.equal(demoCancellationPreview(r, at).refund.credit_cents, 75001);
  assert.deepEqual(demoCancellationPreview(r, at + 1).refund, {
    credit_cents: 37500,
    forfeited_cents: 37501,
    currency: "USD",
    late: true,
  });
  assert.throws(
    () => demoCancellationPreview(r, Date.parse(r.starts_at)),
    (e) => e.code === "reservation_started",
  );
  assert.throws(
    () => demoCancellationPreview({ ...r, status: "cancelled_early" }, at),
    (e) => e.code === "reservation_not_cancellable",
  );
});
test("sample guard rejects changed refunds without wallet writes and supports exact replay", async () => {
  const data = new Map();
  const demo = new DemoClient({
    getItem: (k) => data.get(k),
    setItem: (k, v) => data.set(k, v),
  });
  const at = Date.now();
  const r = reservation(at + 3600000);
  demo.state.reservations = [r];
  const preview = await demo.request("/reservations/1/cancellation-preview");
  const before = demo.state.wallet.credit_cents;
  await assert.rejects(
    demo.write(
      cancellationOperation({
        ...preview,
        refund: { ...preview.refund, credit_cents: 1 },
      }),
    ),
    (e) => e.code === "cancellation_preview_changed",
  );
  assert.equal(demo.state.wallet.credit_cents, before);
  assert.equal(r.status, "confirmed");
  const operation = cancellationOperation(preview);
  const result = await demo.write(operation);
  assert.deepEqual(result.refund, preview.refund);
  assert.deepEqual(await demo.write(operation), result);
  assert.equal(
    demo.state.wallet.credit_cents,
    before + preview.refund.credit_cents,
  );
  assert.match(
    errorMessage({ code: "cancellation_preview_changed" }),
    /still booked/,
  );
});

test("sample history keeps original refunds after a new client loads storage", async () => {
  const data = new Map();
  const storage = {
    getItem: (key) => data.get(key),
    setItem: (key, value) => data.set(key, value),
  };
  for (const hours of [10, 1]) {
    const client = new DemoClient(storage);
    const r = {
      ...reservation(Date.now()),
      id: hours,
      starts_at: new Date(Date.now() + hours * 3600000).toISOString(),
    };
    client.state.reservations.push(r);
    const result = await client.write(
      newOperation(`/reservations/${r.id}/cancel`, {}),
    );
    assert.deepEqual(result.reservation.refund, result.refund);
    const restored = new DemoClient(storage);
    const history = (await restored.request("/reservations")).find(
      (item) => item.id === r.id,
    );
    assert.deepEqual(history.refund, result.refund);
    assert.equal(history.refund.credit_cents, hours === 10 ? 75001 : 37500);
  }
});

test("old sample history recovers stored receipts without estimating missing amounts", async () => {
  const data = new Map();
  const storage = {
    getItem: (key) => data.get(key),
    setItem: (key, value) => data.set(key, value),
  };
  const client = new DemoClient(storage);
  const r = {
    ...reservation(Date.now()),
    starts_at: new Date(Date.now() + 3600000).toISOString(),
  };
  client.state.reservations = [r];
  const result = await client.write(newOperation("/reservations/1/cancel", {}));
  delete r.refund;
  client.state.reservations.push({ ...r, id: 2 });
  client.save();
  const restored = new DemoClient(storage);
  const history = await restored.request("/reservations");
  assert.deepEqual(history.find((r) => r.id === 1).refund, result.refund);
  assert.equal(history.find((r) => r.id === 2).refund, undefined);
});

test("history distinguishes a zero refund from missing or inconsistent data", async () => {
  const { reservationRefund } = await import(
    "../../public/experience/core.mjs"
  );
  const r = {
    ...reservation(Date.now()),
    status: "cancelled_late",
    refund: {
      credit_cents: 0,
      forfeited_cents: 75001,
      currency: "USD",
      late: true,
    },
  };
  assert.deepEqual(reservationRefund(r), r.refund);
  assert.equal(reservationRefund({ ...r, status: "confirmed" }), null);
  assert.equal(reservationRefund({ ...r, refund: null }), null);
  assert.equal(
    reservationRefund({ ...r, refund: { ...r.refund, credit_cents: 1 } }),
    null,
  );
  assert.equal(
    reservationRefund({ ...r, refund: { ...r.refund, currency: "EUR" } }),
    null,
  );
});
