import test from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
import { ApiClient } from "../../public/experience/core.mjs";
const origin = process.env.DORSIA_TEST_ORIGIN;
if (origin && !/^http:\/\/(localhost|127\.0\.0\.1):\d+$/.test(origin))
  throw Error("Use an explicit local origin");
test(
  "real wallet history: allocations, posted refunds, pagination and new-client refresh are exact",
  { skip: !origin },
  async () => {
    const cancellation = JSON.parse(
      await readFile(new URL("./cancellation-fixtures.json", import.meta.url)),
    );
    const payment = JSON.parse(
      await readFile(new URL("./payment-fixtures.json", import.meta.url)),
    );
    const evidence = [];
    for (const [label, fixture] of [
      ["early-late-desktop", cancellation.accounts.desktop],
      ["early-late-mobile", cancellation.accounts.mobile],
      ["split-card", payment.accounts.desktop],
      ["card-only", payment.accounts.mobile],
      ["demo", { email: "demo@dorsia.test" }],
    ]) {
      const make = () => new ApiClient((p, o) => fetch(origin + p, o));
      const api = make();
      await api.login(fixture.email, "password");
      const before = await api.request("/wallet");
      const bookings = await api.request("/reservations");
      const readAll = async (client) => {
        let items = [],
          cursor = null;
        do {
          const page = await client.request(
            "/wallet/transactions" + (cursor ? `?before_id=${cursor}` : ""),
          );
          items.push(...page.transactions);
          assert.notEqual(page.next_before_id, cursor || -1);
          cursor = page.next_before_id;
        } while (cursor);
        return items;
      };
      const items = await readAll(api);
      assert.equal(new Set(items.map((t) => t.id)).size, items.length);
      for (const r of bookings) {
        const paid = items.filter(
          (t) => t.type === "booking" && t.reservation_id === r.id,
        );
        assert.equal(paid.length, 1);
        assert.equal(paid[0].amount_cents, r.min_spend_cents);
        assert.equal(
          paid[0].wallet_change_cents,
          0 - r.payment.credit_cents - r.payment.coupon_cents,
        );
        for (const field of ["credit_cents", "coupon_cents", "card_cents"])
          assert.equal(paid[0][field], r.payment[field]);
        if (r.refund?.credit_cents > 0) {
          const refund = items.filter(
            (t) => t.type.startsWith("cancel_") && t.reservation_id === r.id,
          );
          assert.equal(refund.length, 1);
          assert.equal(refund[0].amount_cents, r.refund.credit_cents);
          assert.equal(refund[0].forfeited_cents, r.refund.forfeited_cents);
        }
      }
      const restored = make();
      await restored.login(fixture.email, "password");
      assert.deepEqual(await readAll(restored), items);
      assert.deepEqual(await restored.request("/wallet"), before);
      assert.equal(
        items.reduce((sum, t) => sum + t.wallet_change_cents, 0),
        before.credit_cents + before.coupon_cents,
      );
      evidence.push({
        label,
        count: items.length,
        wallet: before,
        transactions: items,
        fresh_client_matches: true,
        balances_unchanged: true,
      });
      api.logout();
      restored.logout();
    }
    await writeFile(
      new URL("./wallet-history-live-validation.json", import.meta.url),
      JSON.stringify(
        { verified_at: new Date().toISOString(), evidence },
        null,
        2,
      ) + "\n",
    );
  },
);
