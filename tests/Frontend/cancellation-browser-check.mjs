import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
import { ApiClient } from "../../public/experience/core.mjs";

const origin = process.env.DORSIA_TEST_ORIGIN;
if (!origin || !/^http:\/\/(localhost|127\.0\.0\.1):\d+$/.test(origin))
  throw Error("Set DORSIA_TEST_ORIGIN to an explicit local origin");
const fixtures = JSON.parse(
  await readFile(new URL("./cancellation-fixtures.json", import.meta.url)),
);
const evidence = [];
for (const device of ["desktop", "mobile"]) {
  const fixture = fixtures.accounts[device];
  const api = new ApiClient((path, options) => fetch(origin + path, options));
  await api.login(fixture.email, "password");
  const reservations = await api.request("/reservations");
  let expectedCredit = fixture.initial_credit_cents;
  const bookings = [];
  for (const timing of ["early", "late"]) {
    const booking = fixture.bookings[timing];
    const reservation = await api.request(
      `/reservations/${booking.reservation_id}`,
    );
    const slot = await api.request(`/slots/${booking.slot_id}`);
    assert.equal(reservation.status, `cancelled_${timing}`);
    assert.equal(slot.available, slot.capacity);
    assert.equal(
      reservations.filter((item) => item.hold_id === reservation.hold_id)
        .length,
      1,
    );
    expectedCredit +=
      booking.preview.refund.credit_cents - reservation.payment.credit_cents;
    bookings.push({
      timing,
      reservation,
      fixture_preview: booking.preview,
      slot: { id: slot.id, available: slot.available, capacity: slot.capacity },
      one_reservation_for_hold: true,
    });
  }
  const wallet = await api.request("/wallet");
  assert.equal(wallet.credit_cents, expectedCredit);
  assert.equal(wallet.coupon_cents, 0);
  assert.equal(wallet.currency, "USD");
  evidence.push({ device, bookings, wallet });
  api.logout();
}
await writeFile(
  new URL("./cancellation-browser-validation.json", import.meta.url),
  JSON.stringify(
    {
      verified_at: new Date().toISOString(),
      run: fixtures.run,
      source: "Read-only API checks after CUA early/late cancellations",
      api_checks: evidence,
    },
    null,
    2,
  ) + "\n",
);
console.log(
  "Both browser accounts: early/late cancelled, wallet exact, capacity restored, one reservation per hold.",
);
