import test from "node:test";
import assert from "node:assert/strict";
import {
  DemoClient,
  newOperation,
  demoWalletTransactions,
  mergeWalletTransactions,
  walletTransactionLabel,
  walletTransactionDate,
  signedMoney,
} from "../../public/experience/core.mjs";
const record = (id = 1) => ({
  id,
  venue_id: 1,
  status: "confirmed",
  min_spend_cents: 75001,
  currency: "USD",
  payment: { coupon_cents: 10000, credit_cents: 20000, card_cents: 45001 },
  confirmed_at: "2026-10-22T12:00:00Z",
  starts_at: new Date(Date.now() + 3600000).toISOString(),
});
test("wallet history distinguishes booking total from wallet movement and card-only spend", () => {
  const rows = demoWalletTransactions([
    record(),
    {
      ...record(2),
      payment: { coupon_cents: 0, credit_cents: 0, card_cents: 75001 },
    },
  ]).transactions;
  assert.equal(rows[0].amount_cents, 75001);
  assert.equal(rows[0].wallet_change_cents, 0);
  assert.equal(rows[1].wallet_change_cents, -30000);
  assert.equal(rows[1].card_cents, 45001);
});
test("wallet activity survives fresh demo clients and immutable cancellation replay", async () => {
  const map = new Map();
  const storage = {
    getItem: (k) => map.get(k),
    setItem: (k, v) => map.set(k, v),
  };
  const demo = new DemoClient(storage);
  demo.state.reservations = [record()];
  const op = newOperation("/reservations/1/cancel", {});
  const result = await demo.write(op);
  await demo.write(op);
  const page = await new DemoClient(storage).request("/wallet/transactions");
  assert.equal(page.transactions.length, 2);
  assert.equal(page.transactions[0].amount_cents, result.refund.credit_cents);
  assert.equal(page.transactions[0].forfeited_cents, 37501);
  assert.equal(page.transactions[1].wallet_change_cents, -30000);
  assert.deepEqual(
    await new DemoClient(storage).request("/wallet/transactions"),
    page,
  );
});
test("sample history paginates stable records without duplicates and handles empty history", () => {
  const records = Array.from({ length: 25 }, (_, i) => record(i + 1));
  const page = demoWalletTransactions(records);
  const older = demoWalletTransactions(records, page.next_before_id);
  assert.equal(page.transactions.length, 20);
  assert.equal(older.transactions.length, 5);
  assert.equal(older.next_before_id, null);
  assert.equal(
    new Set([...page.transactions, ...older.transactions].map((t) => t.id))
      .size,
    25,
  );
  assert.deepEqual(demoWalletTransactions([]), {
    transactions: [],
    next_before_id: null,
  });
  assert.deepEqual(demoWalletTransactions(records, 999999), {
    transactions: [],
    next_before_id: null,
  });
});
test("history never synthesizes missing refunds or changes signed cents", () => {
  const r = {
    ...record(),
    status: "cancelled_late",
    cancelled_at: "2026-10-22T13:00:00Z",
  };
  assert.equal(demoWalletTransactions([r]).transactions.length, 1);
  assert.equal(signedMoney(-45001), "−$450.01");
  assert.equal(signedMoney(0), "$0");
  assert.equal(signedMoney(37500), "+$375");
  assert.equal(walletTransactionLabel({ type: "booking" }), "Booking payment");
  assert.equal(
    walletTransactionLabel({ type: "cancel_late" }),
    "Cancellation refund",
  );
  assert.equal(walletTransactionDate("bad"), "Date unavailable");
  assert.match(walletTransactionDate("2026-10-22T12:00:00Z"), /2026/);
});
test("older page merge is idempotent and retains existing rows", () => {
  const first = [{ id: 5 }, { id: 4 }];
  const older = [{ id: 4 }, { id: 3 }];
  assert.deepEqual(mergeWalletTransactions(first, older), [
    { id: 5 },
    { id: 4 },
    { id: 3 },
  ]);
  assert.deepEqual(
    mergeWalletTransactions(mergeWalletTransactions(first, older), older),
    [{ id: 5 }, { id: 4 }, { id: 3 }],
  );
});
