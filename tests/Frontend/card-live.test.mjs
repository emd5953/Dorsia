// Run payment-fixtures first. These fresh local accounts keep this repeatable.
import test from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
import {
  ApiClient,
  newOperation,
  confirmationOperation,
  testCardToken,
  spendPlan,
  addDays,
  dateInZone,
  eligibleSlots,
} from "../../public/experience/core.mjs";

const origin = process.env.DORSIA_TEST_ORIGIN;
if (origin && !/^http:\/\/(localhost|127\.0\.0\.1):\d+$/.test(origin))
  throw new Error("DORSIA_TEST_ORIGIN must be an explicit local HTTP origin.");

test(
  "real test gateway: declines, timeout, missing card, lost-response retry and cancellation",
  { skip: !origin },
  async (t) => {
    const fixture = JSON.parse(
      await readFile(new URL("./payment-fixtures.json", import.meta.url)),
    );
    let loseResponse = false;
    const confirmations = [];
    const api = new ApiClient(async (path, options) => {
      const response = await fetch(origin + path, options);
      if (
        path === "/api/reservations" &&
        options.method === "POST" &&
        response.ok
      ) {
        confirmations.push({
          body: await response.clone().text(),
          key: options.headers["Idempotency-Key"],
        });
        if (loseResponse) {
          loseResponse = false;
          throw new TypeError("Simulated connection loss after backend commit");
        }
      }
      return response;
    });
    await api.login(fixture.accounts.integration.email, "password");
    const before = await api.request("/wallet");
    assert.equal(
      before.credit_cents,
      10000,
      "Generate fresh payment fixtures before running this test",
    );
    const venue = (await api.request("/venues")).find(
      (v) => v.slug === "mw-restaurant",
    );
    const date = addDays(dateInZone(new Date(), venue.timezone), 2);
    const slots = (
      await api.request(
        `/venues/${venue.id}/availability?date=${date}&hour=18&party_size=1`,
      )
    ).slots;
    const slot = eligibleSlots(slots, 1).find(
      (s) => s.local_starts_at.slice(11, 13) === "18",
    );
    const holdTable = () =>
      api.write(newOperation("/holds", { slot_id: slot.id, party_size: 1 }));
    const failures = [];
    for (const [method, code] of [
      ["declined", "payment_failed"],
      ["insufficient", "payment_failed"],
      ["timeout", "payment_timeout"],
    ]) {
      const hold = await holdTable();
      const operation = confirmationOperation(
        hold,
        before,
        testCardToken(method),
      );
      await assert.rejects(api.write(operation), (e) => {
        assert.equal(e.code, code);
        if (method === "insufficient")
          assert.equal(e.details.decline_code, "insufficient_funds");
        return true;
      });
      if (method !== "timeout")
        await assert.rejects(api.write(operation), (e) => e.code === code);
      assert.equal((await api.request(`/holds/${hold.id}`)).status, "released");
      assert.equal(
        (await api.request(`/slots/${slot.id}`)).available,
        slot.available,
      );
      assert.deepEqual(await api.request("/wallet"), before);
      assert.equal((await api.request("/reservations")).length, 0);
      failures.push({
        method,
        code,
        hold_id: hold.id,
        wallet_unchanged: true,
        inventory_restored: true,
      });
    }

    const hold = await holdTable();
    await assert.rejects(
      api.write(
        newOperation("/reservations", { hold_id: hold.id, card_token: null }),
      ),
      (e) => e.code === "card_required",
    );
    assert.equal((await api.request(`/holds/${hold.id}`)).status, "active");
    const operation = confirmationOperation(
      hold,
      before,
      testCardToken("approved"),
    );
    const plan = spendPlan(hold.quote.amount_cents, before);
    assert.ok(plan.card_cents > 0);
    assert.equal(plan.credit_cents, 10000);
    let reservation;
    try {
      loseResponse = true;
      await assert.rejects(
        api.write(operation),
        (e) => e.code === "network_error" && e.uncertain,
      );
      reservation = await api.write(operation);
      assert.equal(reservation.status, "confirmed");
      assert.deepEqual(reservation.payment, plan);
      assert.equal(confirmations[0].body, confirmations[1].body);
      assert.equal(confirmations[0].key, confirmations[1].key);
      assert.equal((await api.request("/reservations")).length, 1);
      assert.equal((await api.request("/wallet")).credit_cents, 0);
      const cancel = newOperation(`/reservations/${reservation.id}/cancel`, {});
      const result = await api.write(cancel);
      assert.equal(result.refund.credit_cents, hold.quote.amount_cents);
      assert.deepEqual(await api.write(cancel), result);
      assert.equal(
        (await api.request("/wallet")).credit_cents,
        hold.quote.amount_cents,
      );
      assert.equal(
        (await api.request(`/slots/${slot.id}`)).available,
        slot.available,
      );
      const evidence = {
        run: fixture.run,
        failures,
        reservation_id: reservation.id,
        hold_id: hold.id,
        payment: reservation.payment,
        refund: result.refund,
        exact_retry: true,
      };
      await writeFile(
        new URL("./card-integration-validation.json", import.meta.url),
        JSON.stringify(evidence, null, 2) + "\n",
      );
      t.diagnostic(JSON.stringify(evidence));
    } finally {
      if (
        reservation &&
        (await api.request(`/reservations/${reservation.id}`)).status ===
          "confirmed"
      )
        await api.write(
          newOperation(`/reservations/${reservation.id}/cancel`, {}),
        );
      api.logout();
    }
  },
);
