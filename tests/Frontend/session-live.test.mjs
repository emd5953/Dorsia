// Local real-API refresh recovery with a new client/session instance each time.
import test from "node:test";
import assert from "node:assert/strict";
import { writeFile } from "node:fs/promises";
import {
  ApiClient,
  newOperation,
  dateInZone,
  addDays,
  eligibleSlots,
} from "../../public/experience/core.mjs";
import {
  MemberSession,
  recoverCheckout,
} from "../../public/experience/session.mjs";
const origin = process.env.DORSIA_TEST_ORIGIN;
if (origin && !/^http:\/\/(localhost|127\.0\.0\.1):\d+$/.test(origin))
  throw Error("Use an explicit local origin");
test(
  "real API: reload session/hold, reconcile a lost confirmation, and sign out",
  { skip: !origin },
  async () => {
    const data = new Map();
    const storage = {
      getItem: (k) => data.get(k) ?? null,
      setItem: (k, v) => data.set(k, v),
    };
    const writes = [];
    const client = () =>
      new ApiClient(async (path, options) => {
        if (options.method === "POST")
          writes.push({
            path,
            body: options.body,
            key: options.headers["Idempotency-Key"],
          });
        return fetch(origin + path, options);
      });
    let api = client();
    let session = new MemberSession(storage);
    const member = await api.login("demo@dorsia.test", "password");
    session.remember(api.token, member);
    const before = await api.request("/wallet");
    const venue = (await api.request("/venues")).sort(
      (a, b) => a.base_per_person_cents - b.base_per_person_cents,
    )[0];
    const date = addDays(dateInZone(new Date(), venue.timezone), 3);
    const available = await api.request(
      `/venues/${venue.id}/availability?date=${date}&hour=18&party_size=1`,
    );
    const slot = eligibleSlots(available.slots, 1).find(
      (s) => s.local_starts_at.slice(11, 13) === "18",
    );
    assert.ok(slot);
    const pending = newOperation("/holds", { slot_id: slot.id, party_size: 1 });
    session.checkout({ holdOperation: pending });
    const original = await api.write(pending); // Simulate refresh before the hold response is applied to UI.
    api = client();
    session = new MemberSession(storage);
    assert.equal((await session.restore(api)).id, member.id);
    let restored = await recoverCheckout(
      api,
      session.read().checkout,
      await api.request("/reservations"),
    );
    assert.deepEqual(restored.holdOperation.operation, pending);
    const hold = await api.write(restored.holdOperation.operation);
    assert.equal(hold.id, original.id);
    session.checkout({ holdId: hold.id });
    api = client();
    session = new MemberSession(storage);
    await session.restore(api);
    const count = writes.length;
    restored = await recoverCheckout(
      api,
      session.read().checkout,
      await api.request("/reservations"),
    );
    assert.equal(writes.length, count);
    assert.deepEqual(restored.hold, await api.request(`/holds/${hold.id}`));
    assert.equal(restored.hold.expires_at, original.expires_at);
    assert.deepEqual(restored.hold.quote, original.quote);
    assert.equal(
      (await api.request(`/slots/${slot.id}`)).available,
      slot.available - 1,
    );
    const confirm = newOperation("/reservations", {
      hold_id: hold.id,
      card_token: null,
    });
    session.checkout({ holdId: hold.id, confirmOperation: confirm });
    const reservation = await api.write(confirm); // Simulate refresh after commit but before applying success.
    try {
      api = client();
      session = new MemberSession(storage);
      await session.restore(api);
      const writesBeforeRecovery = writes.length;
      restored = await recoverCheckout(
        api,
        session.read().checkout,
        await api.request("/reservations"),
      );
      assert.equal(restored.recoveredReservation.id, reservation.id);
      assert.equal(writes.length, writesBeforeRecovery);
      assert.equal(restored.confirmOperation, null);
      session.checkout(null);
      assert.equal(
        (await api.request("/reservations")).filter(
          (r) => r.hold_id === hold.id,
        ).length,
        1,
      );
    } finally {
      await api.write(
        newOperation(`/reservations/${reservation.id}/cancel`, {}),
      );
    }
    assert.deepEqual(await api.request("/wallet"), before);
    assert.equal(
      (await api.request(`/slots/${slot.id}`)).available,
      slot.available,
    );
    session.clear();
    api.logout();
    const refreshed = client();
    assert.equal(await new MemberSession(storage).restore(refreshed), null);
    // A rejected token is forgotten, without accidentally logging into demo as live.
    session.remember("invalid-local-test-token", member);
    await assert.rejects(session.restore(refreshed), (e) => e.status === 401);
    assert.equal(session.read(), null);
    await writeFile(
      new URL("./session-live-validation.json", import.meta.url),
      JSON.stringify(
        {
          verified_at: new Date().toISOString(),
          member_id: member.id,
          hold_id: hold.id,
          reservation_id: reservation.id,
          original_expiry: original.expires_at,
          quote_cents: hold.quote.amount_cents,
          hold_replay_same_id: true,
          recovery_performed_no_writes: true,
          one_reservation: true,
          cancelled: true,
          wallet_restored: true,
          restored_availability: slot.available,
          signed_out_reload: true,
          rejected_token_cleared: true,
        },
        null,
        2,
      ) + "\n",
    );
  },
);
