import { ApiError, remainingSeconds, testPaymentMethods } from "./core.mjs";

export const sessionKey = "dorsia-member-session-v1";
const positiveId = (value) => Number.isSafeInteger(value) && value > 0;

// Stored data is only a recovery hint. The API remains authoritative for
// ownership, status, quote, expiry and wallet allocation.
function operation(value, path, holdId) {
  if (
    !value ||
    value.path !== path ||
    typeof value.body !== "string" ||
    typeof value.key !== "string" ||
    !/^[A-Za-z0-9._:-]{1,200}$/.test(value.key)
  )
    return null;
  try {
    const body = JSON.parse(value.body);
    const valid =
      path === "/holds"
        ? positiveId(body.slot_id) &&
          Number.isInteger(body.party_size) &&
          body.party_size >= 1 &&
          body.party_size <= 20 &&
          Object.keys(body).length === 2
        : body.hold_id === holdId &&
          Object.keys(body).length === 2 &&
          (body.card_token === null ||
            testPaymentMethods.some((m) => m.token === body.card_token));
    return valid
      ? Object.freeze({ path, body: value.body, key: value.key })
      : null;
  } catch {
    return null;
  }
}

export function checkoutSnapshot(value) {
  if (!value || typeof value !== "object") return null;
  const holdId = positiveId(value.holdId) ? value.holdId : null;
  const holdOperation = operation(value.holdOperation, "/holds");
  const confirmOperation = holdId
    ? operation(value.confirmOperation, "/reservations", holdId)
    : null;
  if (!holdId && !holdOperation) return null;
  const plan = value.confirmPlan;
  const confirmPlan =
    confirmOperation &&
    plan &&
    ["coupon_cents", "credit_cents", "card_cents"].every(
      (k) => Number.isSafeInteger(plan[k]) && plan[k] >= 0,
    )
      ? {
          coupon_cents: plan.coupon_cents,
          credit_cents: plan.credit_cents,
          card_cents: plan.card_cents,
        }
      : null;
  return {
    holdId,
    holdOperation: holdId ? null : holdOperation,
    confirmOperation,
    confirmPlan,
    paymentMethod: testPaymentMethods.some((m) => m.id === value.paymentMethod)
      ? value.paymentMethod
      : "",
  };
}

export class MemberSession {
  constructor(storage) {
    this.storage = storage;
    this.memory = null;
    this.persistent = true;
  }
  read() {
    let value;
    try {
      value = this.persistent
        ? JSON.parse(this.storage.getItem(sessionKey))
        : this.memory;
    } catch {
      value = this.memory;
    }
    if (
      value?.version !== 1 ||
      typeof value.token !== "string" ||
      !value.token ||
      !positiveId(value.memberId)
    )
      return null;
    return {
      version: 1,
      token: value.token,
      memberId: value.memberId,
      checkout: checkoutSnapshot(value.checkout),
    };
  }
  save(value) {
    this.memory = value;
    try {
      this.storage.setItem(sessionKey, JSON.stringify(value));
      this.persistent = true;
    } catch {
      this.persistent = false;
    }
    return this.persistent;
  }
  remember(token, member) {
    return this.save({
      version: 1,
      token,
      memberId: member.id,
      checkout: null,
    });
  }
  checkout(value) {
    const current = this.read();
    return current
      ? this.save({ ...current, checkout: checkoutSnapshot(value) })
      : false;
  }
  clear() {
    this.save(null);
  }
  async restore(api) {
    const saved = this.read();
    if (!saved) return null;
    api.token = saved.token;
    try {
      const member = await api.request("/me");
      if (member.id !== saved.memberId)
        throw new ApiError("unauthenticated", "Please sign in again.", 401);
      return member;
    } catch (error) {
      if (error.status === 401) {
        this.clear();
        api.logout();
      }
      throw error;
    }
  }
}

export const emptyCheckout = () => ({
  hold: null,
  holdOperation: null,
  confirmOperation: null,
  confirmPlan: null,
  paymentMethod: "",
  confirmPending: false,
});

// Reads only: reload never books or authorizes payment. An interrupted write
// can only be retried explicitly, with its original body and key.
export async function recoverCheckout(api, saved, bookings) {
  const result = emptyCheckout();
  const snapshot = checkoutSnapshot(saved);
  if (!snapshot) return result;
  if (snapshot.holdOperation)
    return {
      ...result,
      holdOperation: { operation: snapshot.holdOperation, uncertain: true },
    };
  const reservation = bookings.find((r) => r.hold_id === snapshot.holdId);
  if (reservation) return { ...result, recoveredReservation: reservation };
  let hold;
  try {
    hold = await api.request(`/holds/${snapshot.holdId}`);
  } catch (error) {
    if (error.status === 404)
      return {
        ...result,
        recoveryNotice:
          "Your saved table is no longer available. Choose another time.",
      };
    throw error;
  }
  if (hold.status === "converted")
    return {
      ...result,
      recoveryNotice:
        "Your table was already booked. Refresh your reservations to see it.",
    };
  if (hold.status !== "active" || !remainingSeconds(hold))
    return {
      ...result,
      recoveryNotice:
        "Your previous hold has ended. Choose a time for a fresh quote.",
    };
  return {
    ...result,
    hold,
    confirmOperation: snapshot.confirmOperation,
    confirmPlan: snapshot.confirmPlan,
    paymentMethod: snapshot.paymentMethod,
    confirmPending: Boolean(snapshot.confirmOperation),
  };
}
