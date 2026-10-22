import test from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
import { ApiClient } from "../../public/experience/core.mjs";

const origin = process.env.DORSIA_TEST_ORIGIN;
if (origin && !/^http:\/\/(localhost|127\.0\.0\.1):\d+$/.test(origin))
  throw Error("Use an explicit local origin");

test(
  "real API: posted early/late history survives fresh clients without wallet or inventory changes",
  { skip: !origin },
  async () => {
    const fixtures = JSON.parse(
      await readFile(new URL("./cancellation-fixtures.json", import.meta.url)),
    );
    const evidence = [];
    for (const device of ["desktop", "mobile", "integration"]) {
      const fixture = fixtures.accounts[device];
      const api = new ApiClient((path, options) =>
        fetch(origin + path, options),
      );
      await api.login(fixture.email, "password");
      const before = await api.request("/wallet");
      const original = await api.request("/reservations");
      const restored = new ApiClient((path, options) =>
        fetch(origin + path, options),
      );
      await restored.login(fixture.email, "password");
      const reloaded = await restored.request("/reservations");
      assert.deepEqual(reloaded, original);
      const bookings = [];
      for (const timing of ["early", "late"]) {
        const fixtureBooking = fixture.bookings[timing];
        const reservation = reloaded.find(
          (r) => r.id === fixtureBooking.reservation_id,
        );
        assert.equal(reservation.status, `cancelled_${timing}`);
        assert.deepEqual(reservation.refund, fixtureBooking.preview.refund);
        const detail = await restored.request(
          `/reservations/${reservation.id}`,
        );
        assert.deepEqual(detail, reservation);
        const slot = await restored.request(`/slots/${fixtureBooking.slot_id}`);
        assert.equal(slot.available, slot.capacity);
        bookings.push({
          id: reservation.id,
          timing,
          refund: reservation.refund,
          status: reservation.status,
          available: slot.available,
        });
      }
      assert.deepEqual(await restored.request("/wallet"), before);
      evidence.push({
        device,
        bookings,
        wallet: before,
        fresh_client_matches: true,
      });
      api.logout();
      restored.logout();
    }
    await writeFile(
      new URL("./history-live-validation.json", import.meta.url),
      JSON.stringify(
        { verified_at: new Date().toISOString(), run: fixtures.run, evidence },
        null,
        2,
      ) + "\n",
    );
  },
);
