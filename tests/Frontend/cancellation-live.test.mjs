import test from "node:test";
import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
import {
  ApiClient,
  cancellationOperation,
} from "../../public/experience/core.mjs";
const origin = process.env.DORSIA_TEST_ORIGIN;
if (origin && !/^http:\/\/(localhost|127\.0\.0\.1):\d+$/.test(origin))
  throw Error("Use an explicit local origin");
test(
  "real API: exact early/late preview, guarded cancellation, replay, wallet and inventory",
  { skip: !origin },
  async () => {
    const fixtures = JSON.parse(
      await readFile(new URL("./cancellation-fixtures.json", import.meta.url)),
    );
    const fixture = fixtures.accounts.integration;
    const api = new ApiClient((p, o) => fetch(origin + p, o));
    await api.login(fixture.email, "password");
    const evidence = [];
    for (const timing of ["early", "late"]) {
      const { reservation_id: id, slot_id } = fixture.bookings[timing];
      const before = await api.request("/wallet");
      const slotBefore = await api.request(`/slots/${slot_id}`);
      const preview = await api.request(
        `/reservations/${id}/cancellation-preview`,
      );
      assert.equal(preview.refund.late, timing === "late");
      assert.deepEqual(await api.request("/wallet"), before);
      assert.equal(
        (await api.request(`/slots/${slot_id}`)).available,
        slotBefore.available,
      );
      const stale = cancellationOperation({
        ...preview,
        refund: {
          ...preview.refund,
          credit_cents: preview.refund.credit_cents + 1,
        },
      });
      await assert.rejects(
        api.write(stale),
        (e) => e.code === "cancellation_preview_changed",
      );
      assert.equal(
        (await api.request(`/reservations/${id}`)).status,
        "confirmed",
      );
      const op = cancellationOperation(preview);
      const result = await api.write(op);
      assert.deepEqual(result.refund, preview.refund);
      assert.equal(result.reservation.status, `cancelled_${timing}`);
      assert.deepEqual(await api.write(op), result);
      const wallet = await api.request("/wallet");
      assert.equal(
        wallet.credit_cents,
        before.credit_cents + preview.refund.credit_cents,
      );
      const slot = await api.request(`/slots/${slot_id}`);
      assert.equal(slot.available, slot.capacity);
      await assert.rejects(
        api.request(`/reservations/${id}/cancellation-preview`),
        (e) => e.code === "reservation_not_cancellable",
      );
      evidence.push({
        timing,
        reservation_id: id,
        preview,
        actual: result.refund,
        status: result.reservation.status,
        wallet_before: before,
        wallet_after: wallet,
        restored_availability: slot.available,
        exact_replay: true,
        stale_preview_rejected: true,
      });
    }
    await writeFile(
      new URL("./cancellation-live-validation.json", import.meta.url),
      JSON.stringify(
        { verified_at: new Date().toISOString(), run: fixtures.run, evidence },
        null,
        2,
      ) + "\n",
    );
    api.logout();
  },
);
