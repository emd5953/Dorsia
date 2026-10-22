import test from "node:test";
import assert from "node:assert/strict";
import {
  ApiClient,
  ApiError,
  newOperation,
} from "../../public/experience/core.mjs";
import {
  MemberSession,
  sessionKey,
  recoverCheckout,
  checkoutSnapshot,
} from "../../public/experience/session.mjs";
const storage = () => {
  const data = new Map();
  return {
    getItem: (k) => data.get(k) ?? null,
    setItem: (k, v) => data.set(k, v),
  };
};
const member = { id: 1, name: "Local member" };
const hold = () => ({
  id: 4,
  status: "active",
  party_size: 2,
  expires_at: new Date(Date.now() + 200000).toISOString(),
  quote: {
    amount_cents: 50000,
    expires_at: new Date(Date.now() + 150000).toISOString(),
  },
  slot: { id: 2 },
});

test("session survives a new client, revalidates member and clears on logout", async () => {
  const tab = storage();
  const session = new MemberSession(tab);
  session.remember("fixture-token", member);
  const api = new ApiClient(async (path, options) => {
    assert.equal(path, "/api/me");
    assert.equal(options.headers.Authorization, "Bearer fixture-token");
    return { ok: true, json: async () => ({ data: member }) };
  });
  const refreshed = new MemberSession(tab);
  assert.deepEqual(await refreshed.restore(api), member);
  refreshed.clear();
  api.logout();
  assert.equal(new MemberSession(tab).read(), null);
  assert.equal(api.token, null);
  assert.equal(tab.getItem(sessionKey), "null");
});
test("unauthorized and mismatched identity discard credentials and checkout", async () => {
  for (const response of [
    () => {
      throw new ApiError("unauthenticated", "Expired", 401);
    },
    () => ({ id: 2 }),
  ]) {
    const tab = storage();
    const session = new MemberSession(tab);
    session.remember("fixture-token", member);
    session.checkout({ holdId: 4 });
    const api = {
      token: null,
      request: async () => response(),
      logout() {
        this.token = null;
      },
    };
    await assert.rejects(session.restore(api), (e) => e.status === 401);
    assert.equal(session.read(), null);
    assert.equal(api.token, null);
  }
});
test("temporary connection failure preserves credentials and checkout for retry", async () => {
  const session = new MemberSession(storage());
  session.remember("fixture-token", member);
  session.checkout({ holdId: 4 });
  const api = {
    request: async () => {
      throw new ApiError("network_error", "Offline");
    },
    logout() {
      throw Error("must not sign out");
    },
  };
  await assert.rejects(session.restore(api));
  assert.equal(session.read().checkout.holdId, 4);
});
test("unavailable storage allows this visit and reports that refresh recovery is unavailable", async () => {
  const session = new MemberSession({
    getItem: () => null,
    setItem: () => {
      throw Error("blocked");
    },
  });
  assert.equal(session.remember("fixture-token", member), false);
  assert.equal(session.persistent, false);
  session.checkout({ holdId: 4 });
  assert.equal(session.read().checkout.holdId, 4);
  session.clear();
  assert.equal(session.read(), null);
});
test("corrupt storage and unsupported operations cannot introduce arbitrary requests", () => {
  const tab = storage();
  tab.setItem(sessionKey, "{");
  assert.equal(new MemberSession(tab).read(), null);
  for (const checkout of [
    { holdId: "4" },
    { holdOperation: newOperation("/wallet", {}) },
    { holdOperation: newOperation("/holds", { slot_id: 2, party_size: 0 }) },
  ])
    assert.equal(checkoutSnapshot(checkout), null);
  const saved = checkoutSnapshot({
    holdId: 4,
    confirmOperation: newOperation("/reservations", {
      hold_id: 5,
      card_token: null,
    }),
    paymentMethod: "actual-card",
  });
  assert.equal(saved.confirmOperation, null);
  assert.equal(saved.paymentMethod, "");
});
test("new account cannot inherit a previous member checkout", () => {
  const session = new MemberSession(storage());
  session.remember("first", member);
  session.checkout({ holdId: 4 });
  session.remember("second", { id: 2 });
  assert.equal(session.read().checkout, null);
  assert.equal(session.read().memberId, 2);
});
test("active recovery reads authoritative quote and expiry without any writes", async () => {
  const current = hold();
  const requests = [];
  const restored = await recoverCheckout(
    {
      request: async (p) => {
        requests.push(p);
        return current;
      },
    },
    { holdId: 4, paymentMethod: "approved" },
    [],
  );
  assert.deepEqual(requests, ["/holds/4"]);
  assert.equal(restored.hold, current);
  assert.equal(restored.hold.quote.expires_at, current.quote.expires_at);
  assert.equal(restored.paymentMethod, "approved");
});
test("interrupted hold and confirmation retain exact immutable body/key across reload", async () => {
  const tab = storage();
  const session = new MemberSession(tab);
  session.remember("fixture-token", member);
  const create = newOperation("/holds", { slot_id: 2, party_size: 2 });
  session.checkout({ holdOperation: create });
  const pending = await recoverCheckout(
    {},
    new MemberSession(tab).read().checkout,
    [],
  );
  assert.deepEqual(pending.holdOperation.operation, create);
  assert.equal(pending.holdOperation.uncertain, true);
  assert.ok(Object.isFrozen(pending.holdOperation.operation));
  const confirm = newOperation("/reservations", {
    hold_id: 4,
    card_token: "tok_ok",
  });
  session.checkout({
    holdId: 4,
    confirmOperation: confirm,
    confirmPlan: { coupon_cents: 0, credit_cents: 10000, card_cents: 40000 },
    paymentMethod: "approved",
  });
  const restored = await recoverCheckout(
    { request: async () => hold() },
    new MemberSession(tab).read().checkout,
    [],
  );
  assert.deepEqual(restored.confirmOperation, confirm);
  assert.equal(restored.confirmPending, true);
  assert.equal(restored.confirmPlan.card_cents, 40000);
  assert.ok(Object.isFrozen(restored.confirmOperation));
});
test("completed confirmation is recovered from reservations without resubmitting payment", async () => {
  const reservation = { id: 9, hold_id: 4, status: "confirmed" };
  const restored = await recoverCheckout(
    {
      request: () => {
        throw Error("unnecessary read");
      },
    },
    { holdId: 4 },
    [reservation],
  );
  assert.equal(restored.recoveredReservation, reservation);
  assert.equal(restored.hold, null);
  assert.equal(restored.confirmOperation, null);
});
test("expired, released, converted and ownership-hidden missing holds are discarded", async () => {
  for (const status of ["expired", "released", "converted"]) {
    const restored = await recoverCheckout(
      { request: async () => ({ ...hold(), status }) },
      { holdId: 4 },
      [],
    );
    assert.equal(restored.hold, null);
    assert.ok(restored.recoveryNotice);
  }
  const restored = await recoverCheckout(
    {
      request: async () => {
        throw new ApiError("not_found", "Missing", 404);
      },
    },
    { holdId: 4 },
    [],
  );
  assert.equal(restored.hold, null);
  assert.ok(restored.recoveryNotice);
  const expired = await recoverCheckout(
    {
      request: async () => ({ ...hold(), expires_at: "2000-01-01T00:00:00Z" }),
    },
    { holdId: 4 },
    [],
  );
  assert.equal(expired.hold, null);
});
test("hold recovery outage preserves saved state instead of permitting a duplicate hold", async () => {
  const session = new MemberSession(storage());
  session.remember("fixture-token", member);
  session.checkout({ holdId: 4 });
  await assert.rejects(
    recoverCheckout(
      {
        request: async () => {
          throw new ApiError("service_unavailable", "Offline", 503);
        },
      },
      session.read().checkout,
      [],
    ),
  );
  assert.equal(session.read().checkout.holdId, 4);
});
