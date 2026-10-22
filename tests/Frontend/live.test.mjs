// Opt-in integration test: creates and cancels one wallet-funded local booking.
// Uses the same ApiClient as the member UI, through the development proxy.
import test from "node:test";
import assert from "node:assert/strict";
import {
  ApiClient,
  newOperation,
  dateInZone,
  addDays,
  eligibleSlots,
  spendPlan,
} from "../../public/experience/core.mjs";

const origin = process.env.DORSIA_TEST_ORIGIN;
if (origin && !/^http:\/\/(localhost|127\.0\.0\.1):\d+$/.test(origin))
  throw new Error("DORSIA_TEST_ORIGIN must be an explicit local HTTP origin.");

test(
  "real API: wallet booking, exact retries, cancellation, and released inventory",
  { skip: !origin },
  async (t) => {
    const writes = [];
    const api = new ApiClient(async (path, options) => {
      const response = await fetch(origin + path, options);
      if (options.key || options.headers["Idempotency-Key"])
        writes.push({
          path,
          status: response.status,
          body: await response.clone().text(),
        });
      return response;
    });
    const member = await api.login("demo@dorsia.test", "password");
    assert.equal((await api.request("/me")).id, member.id);
    const [walletBefore, venues] = await Promise.all([
      api.request("/wallet"),
      api.request("/venues"),
    ]);
    // The least expensive seeded venue avoids needing a card or extra credit.
    const venue = [...venues].sort(
      (a, b) => a.base_per_person_cents - b.base_per_person_cents,
    )[0];
    assert.ok(venue, "Seed the local catalogue first");
    const date = addDays(dateInZone(new Date(), venue.timezone), 2);
    const availability = await api.request(
      `/venues/${venue.id}/availability?date=${date}&hour=18&party_size=1`,
    );
    const slot = eligibleSlots(availability.slots, 1).find(
      (s) => s.local_starts_at.slice(11, 13) === "18",
    );
    assert.ok(slot, "An available seeded 6 PM table is required");
    const holdOperation = newOperation("/holds", {
      slot_id: slot.id,
      party_size: 1,
    });
    const hold = await api.write(holdOperation);
    assert.equal(hold.status, "active");
    assert.equal(hold.slot.id, slot.id);
    assert.equal(
      (await api.request(`/slots/${slot.id}`)).available,
      slot.available - 1,
    );
    assert.deepEqual(await api.write(holdOperation), hold);
    assert.equal(writes[0].status, 201);
    assert.equal(
      writes[1].body,
      writes[0].body,
      "Hold replay is byte-identical",
    );
    await assert.rejects(
      api.write(
        newOperation(
          "/holds",
          { slot_id: slot.id, party_size: 2 },
          holdOperation.key,
        ),
      ),
      (error) => error.code === "idempotency_mismatch" && error.status === 422,
    );
    const plan = spendPlan(hold.quote.amount_cents, walletBefore);
    assert.equal(
      plan.card_cents,
      0,
      "Seeded dining credit must cover this test",
    );

    const confirmOperation = newOperation("/reservations", {
      hold_id: hold.id,
      card_token: null,
    });
    let reservation;
    let cancelled = false;
    try {
      reservation = await api.write(confirmOperation);
      assert.equal(reservation.status, "confirmed");
      assert.equal(reservation.min_spend_cents, hold.quote.amount_cents);
      assert.deepEqual(reservation.payment, plan);
      assert.deepEqual(await api.write(confirmOperation), reservation);
      assert.equal(
        writes.at(-1).body,
        writes.at(-2).body,
        "Confirmation replay is byte-identical",
      );
      assert.equal(
        (await api.request(`/slots/${slot.id}`)).available,
        slot.available - 1,
      );
      assert.deepEqual(await api.request("/wallet"), {
        currency: walletBefore.currency,
        coupon_cents: walletBefore.coupon_cents - plan.coupon_cents,
        credit_cents: walletBefore.credit_cents - plan.credit_cents,
      });

      const cancellation = newOperation(
        `/reservations/${reservation.id}/cancel`,
        {},
      );
      const result = await api.write(cancellation);
      cancelled = true;
      assert.equal(result.reservation.status, "cancelled_early");
      assert.equal(result.refund.credit_cents, reservation.min_spend_cents);
      assert.equal(result.refund.forfeited_cents, 0);
      assert.equal(result.refund.late, false);
      assert.deepEqual(await api.write(cancellation), result);
      assert.equal(
        writes.at(-1).body,
        writes.at(-2).body,
        "Cancellation replay is byte-identical",
      );
      assert.equal(
        (await api.request(`/slots/${slot.id}`)).available,
        slot.available,
      );
      assert.deepEqual(await api.request("/wallet"), {
        currency: walletBefore.currency,
        coupon_cents: walletBefore.coupon_cents - plan.coupon_cents,
        credit_cents:
          walletBefore.credit_cents -
          plan.credit_cents +
          result.refund.credit_cents,
      });
      assert.equal(
        (await api.request(`/reservations/${reservation.id}`)).status,
        "cancelled_early",
      );
      assert.equal(
        (await api.request("/reservations")).filter(
          (r) => r.hold_id === hold.id,
        ).length,
        1,
      );
      t.diagnostic(
        JSON.stringify({
          venue: venue.name,
          slot_id: slot.id,
          hold_id: hold.id,
          reservation_id: reservation.id,
          amount_cents: reservation.min_spend_cents,
          restored_availability: slot.available,
          refund: result.refund,
        }),
      );
    } finally {
      if (reservation && !cancelled)
        await api.write(
          newOperation(`/reservations/${reservation.id}/cancel`, {}),
        );
      api.logout();
    }
  },
);
