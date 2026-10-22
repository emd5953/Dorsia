// C§8 transport and view helpers. No dependency on another module's internals.
export const escapeHtml = (value = "") =>
  String(value).replace(
    /[&<>"']/g,
    (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        c
      ],
  );
export const money = (cents, currency = "USD") =>
  new Intl.NumberFormat("en-US", {
    style: "currency",
    currency,
    maximumFractionDigits: cents % 100 ? 2 : 0,
  }).format(cents / 100);
export const dateInZone = (date = new Date(), timezone = "America/New_York") =>
  new Intl.DateTimeFormat("en-CA", {
    timeZone: timezone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(date);
export const addDays = (date, days) =>
  new Date(Date.parse(`${date}T12:00:00Z`) + days * 86400000)
    .toISOString()
    .slice(0, 10);
export const displayDate = (date) =>
  new Intl.DateTimeFormat("en-US", {
    weekday: "short",
    month: "short",
    day: "numeric",
    timeZone: "UTC",
  }).format(new Date(`${date.slice(0, 10)}T12:00:00Z`));
// local_starts_at already contains the venue's local date and offset. Never reinterpret it in the device zone.
export const localTime = (value) => {
  const h = Number(value.slice(11, 13));
  return `${h % 12 || 12}:${value.slice(14, 16)} ${h >= 12 ? "PM" : "AM"}`;
};
export function venueDateTime(date, hour, timezone = "America/New_York") {
  const reference = new Date(`${date}T${String(hour).padStart(2, "0")}:00:00Z`);
  const offset =
    new Intl.DateTimeFormat("en-US", {
      timeZone: timezone,
      timeZoneName: "longOffset",
    })
      .formatToParts(reference)
      .find((p) => p.type === "timeZoneName")
      .value.replace("GMT", "") || "+00:00";
  return `${date}T${String(hour).padStart(2, "0")}:00:00${offset}`;
}
export const timeLabel = (hour) =>
  `${hour % 12 || 12}:00 ${hour >= 12 ? "PM" : "AM"}`;
export function spendPlan(amount, wallet) {
  const coupon = Math.min(amount, wallet.coupon_cents);
  const credit = Math.min(amount - coupon, wallet.credit_cents);
  return {
    coupon_cents: coupon,
    credit_cents: credit,
    card_cents: amount - coupon - credit,
  };
}
export const eligibleSlots = (slots, party) =>
  slots.filter(
    (s) =>
      s.bookable &&
      s.available > 0 &&
      s.min_party_size <= party &&
      s.max_party_size >= party,
  );
export const isUpcoming = (r) =>
  r.status === "confirmed" && Date.parse(r.starts_at) > Date.now();
// Keep legacy preview records readable; live statuses follow C§5.6.
export const isCancelled = (r) =>
  ["cancelled", "cancelled_early", "cancelled_late"].includes(r.status);
export const reservationLabel = (r) =>
  isCancelled(r) ? "Cancelled" : isUpcoming(r) ? "Confirmed" : "Past visit";
export function reservationRefund(reservation) {
  if (!isCancelled(reservation)) return null;
  const refund = reservation.refund;
  if (
    !refund ||
    !Number.isSafeInteger(refund.credit_cents) ||
    refund.credit_cents < 0 ||
    !Number.isSafeInteger(refund.forfeited_cents) ||
    refund.forfeited_cents < 0 ||
    refund.credit_cents + refund.forfeited_cents !==
      reservation.min_spend_cents ||
    refund.currency !== reservation.currency ||
    typeof refund.late !== "boolean"
  )
    return null;
  return refund;
}
export const remainingSeconds = (hold) =>
  Math.max(
    0,
    Math.ceil(
      (Math.min(
        Date.parse(hold.expires_at),
        Date.parse(hold.quote.expires_at),
      ) -
        Date.now()) /
        1000,
    ),
  );
export const newOperation = (path, body, key = crypto.randomUUID()) =>
  Object.freeze({ path, body: JSON.stringify(body), key });

// Freeze the selected token with the hold so an uncertain retry cannot change
// payment methods or create a second authorization under a new request key.
export function confirmationOperation(hold, wallet, cardToken = null) {
  const plan = spendPlan(hold.quote.amount_cents, wallet);
  if (plan.card_cents > 0 && !cardToken)
    throw new ApiError(
      "card_required",
      "Choose a payment method to continue.",
      422,
    );
  return newOperation("/reservations", {
    hold_id: hold.id,
    card_token: plan.card_cents > 0 ? cardToken : null,
  });
}

// Guard the exact server-reviewed refund; retries retain the same body and key.
export function cancellationOperation(preview) {
  const refund = preview?.refund;
  if (
    !Number.isSafeInteger(preview?.reservation_id) ||
    preview.reservation_id < 1 ||
    !refund ||
    !Number.isSafeInteger(refund.credit_cents) ||
    refund.credit_cents < 0 ||
    !Number.isSafeInteger(refund.forfeited_cents) ||
    refund.forfeited_cents < 0 ||
    refund.currency !== "USD" ||
    typeof refund.late !== "boolean"
  ) {
    throw new ApiError(
      "invalid_preview",
      "Refresh the cancellation refund before continuing.",
    );
  }
  return newOperation(`/reservations/${preview.reservation_id}/cancel`, {
    expected_refund: {
      credit_cents: refund.credit_cents,
      forfeited_cents: refund.forfeited_cents,
      currency: refund.currency,
      late: refund.late,
    },
  });
}

export function cancellationPreviewDeadline(preview, requestStartedAt) {
  const boundary = preview.refund.late
    ? preview.starts_at
    : preview.early_cancellation_deadline;
  const remaining = Date.parse(boundary) - Date.parse(preview.evaluated_at);
  return (
    requestStartedAt + (Number.isFinite(remaining) ? Math.max(0, remaining) : 0)
  );
}

export function cancellationDeadlineLabel(preview) {
  return new Intl.DateTimeFormat("en-US", {
    timeZone: preview.venue_timezone,
    weekday: "short",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
    second: "2-digit",
    timeZoneName: "short",
  }).format(new Date(preview.early_cancellation_deadline));
}

// Only the explicitly labeled local demo uses a sample policy. Live amounts
// always come from the authenticated cancellation-preview endpoint.
export function demoCancellationPreview(reservation, at = Date.now()) {
  if (!reservation)
    throw new ApiError("not_found", "Reservation not found.", 404);
  if (reservation.status !== "confirmed")
    throw new ApiError(
      "reservation_not_cancellable",
      "Already cancelled.",
      409,
    );
  if (Date.parse(reservation.starts_at) <= at)
    throw new ApiError(
      "reservation_started",
      "Reservation already started.",
      409,
    );
  const cutoff = Date.parse(reservation.starts_at) - 8 * 3600000;
  const late = at > cutoff;
  const credit = late
    ? Math.floor(reservation.min_spend_cents / 2)
    : reservation.min_spend_cents;
  return {
    reservation_id: reservation.id,
    refund: {
      credit_cents: credit,
      forfeited_cents: reservation.min_spend_cents - credit,
      currency: reservation.currency,
      late,
    },
    evaluated_at: new Date(at).toISOString(),
    early_cancellation_deadline: new Date(cutoff).toISOString(),
    starts_at: reservation.starts_at,
    venue_timezone: "America/New_York",
    cancel_window_hours: 8,
  };
}

export const testPaymentMethods = Object.freeze(
  [
    { id: "approved", label: "Test card · Approved", token: "tok_ok" },
    { id: "declined", label: "Test card · Declined", token: "tok_decline" },
    {
      id: "insufficient",
      label: "Test card · Insufficient funds",
      token: "tok_insufficient_funds",
    },
    {
      id: "timeout",
      label: "Test card · Payment timeout",
      token: "tok_timeout",
    },
  ].map(Object.freeze),
);
export function testCardToken(method) {
  return testPaymentMethods.find((item) => item.id === method)?.token || null;
}

export class ApiError extends Error {
  constructor(code, message, status = 0, details = {}) {
    super(message);
    this.code = code;
    this.status = status;
    this.details = details;
  }
  get uncertain() {
    return (
      this.status >= 500 ||
      this.status === 0 ||
      ["idempotency_in_progress", "payment_in_progress"].includes(this.code)
    );
  }
}
export const errorMessage = (error) =>
  ({
    sold_out: "That table was just taken. Choose another time to continue.",
    slot_unavailable:
      "This table is no longer available. Refresh the times and try another.",
    hold_expired: "Your hold has expired. Choose a time for a fresh quote.",
    hold_not_active:
      "This hold is no longer active. Check your reservations before booking again.",
    payment_failed:
      error.details?.decline_code === "insufficient_funds"
        ? "This card has insufficient funds. Your table was released; choose a time and another payment method."
        : "The payment was declined and the table was released. Choose a time to try another payment method.",
    payment_timeout:
      "The payment result is still being resolved. Check your reservations before trying a new booking.",
    payment_in_progress:
      "Your payment is still being processed. Retry this same request in a moment.",
    idempotency_in_progress:
      "This request is still processing. Retry in a moment; your request will not be duplicated.",
    active_hold_exists:
      "You already have a hold for this table. Return to your review, or wait for that hold to expire.",
    card_required:
      "Dining credit does not cover this total. Choose a payment method to cover the remainder.",
    wallet_balance_changed:
      "Your wallet changed. Refresh your balance and review the payment again.",
    cancellation_preview_changed:
      "The refund has changed. Your reservation is still booked. Review the updated amounts before cancelling.",
    unauthenticated: "Your session has ended. Sign in again to continue.",
    invalid_credentials:
      "Your email or password was not recognized. Please try again.",
    network_error:
      "We could not reach the service. Check your connection and retry the same request.",
    service_unavailable:
      "The service is unavailable. Please try again in a moment.",
    reservation_started:
      "This reservation has already started and cannot be cancelled here.",
    reservation_not_cancellable:
      "This reservation cannot be cancelled. Refresh your bookings for its latest status.",
  })[error.code] ||
  error.message ||
  "Something went wrong. Please try again.";

export class ApiClient {
  constructor(fetcher = globalThis.fetch.bind(globalThis)) {
    this.fetcher = fetcher;
    this.token = null;
  }
  async request(path, { method = "GET", body, key } = {}) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await this.fetcher(`/api${path}`, {
        method,
        signal: controller.signal,
        headers: {
          Accept: "application/json",
          ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
          ...(this.token ? { Authorization: `Bearer ${this.token}` } : {}),
          ...(key ? { "Idempotency-Key": key } : {}),
        },
        ...(body !== undefined ? { body } : {}),
        cache: "no-store",
      });
      let payload;
      try {
        payload = await response.json();
      } catch {
        throw new ApiError(
          "service_unavailable",
          "The service returned an unreadable response.",
          response.status >= 400 ? response.status : 502,
        );
      }
      if (!response.ok)
        throw new ApiError(
          payload.error?.code || "service_unavailable",
          payload.error?.message || "The request could not be completed.",
          response.status,
          payload.error?.details || {},
        );
      if (!Object.hasOwn(payload, "data"))
        throw new ApiError(
          "service_unavailable",
          "The response was incomplete.",
          502,
        );
      return payload.data;
    } catch (error) {
      if (error instanceof ApiError) throw error;
      throw new ApiError("network_error", "Could not reach the service.");
    } finally {
      clearTimeout(timeout);
    }
  }
  async login(email, password) {
    const result = await this.request("/auth/token", {
      method: "POST",
      body: JSON.stringify({
        email,
        password,
        device_name: "Dorsia web experience",
      }),
    });
    this.token = result.token;
    return result.member;
  }
  write(operation) {
    return this.request(operation.path, {
      method: "POST",
      body: operation.body,
      key: operation.key,
    });
  }
  logout() {
    this.token = null;
  }
}

export const editorial = {
  carbone: {
    image: "carbone",
    area: "Greenwich Village",
    cuisine: "Italian",
    eyebrow: "An enduring classic",
    description:
      "A warm room, a generous table, and an evening to settle into. Make a little occasion of dinner.",
    tag: "The iconic evening",
  },
  "the-grill": {
    image: "grill",
    area: "Midtown",
    cuisine: "American",
    eyebrow: "New York, in its element",
    description:
      "A grand dining room sets the scene for a distinctly New York evening. Come for dinner. Stay for the conversation.",
    tag: "A New York institution",
  },
  torrisi: {
    image: "torrisi",
    area: "Nolita",
    cuisine: "Italian",
    eyebrow: "A downtown state of mind",
    description:
      "Gather your favorite people and make a night of it, in a room with a little downtown magic.",
    tag: "Worth making plans for",
  },
  cote: {
    image: "cote",
    area: "Flatiron",
    cuisine: "Korean",
    eyebrow: "Better together",
    description:
      "The table is the center of the evening. Settle in for a lively dinner made for sharing.",
    tag: "For the whole table",
  },
  53: {
    image: "53",
    area: "Midtown",
    cuisine: "Asian",
    eyebrow: "An artful evening",
    description:
      "An expressive dining room for an evening that feels a little out of the ordinary.",
    tag: "Something unexpected",
  },
  "chinese-tuxedo": {
    image: "tuxedo",
    area: "Chinatown",
    cuisine: "Chinese",
    eyebrow: "After dark, downtown",
    description:
      "A theatrical setting and a lively energy. Your next evening in the city starts here.",
    tag: "A downtown favorite",
  },
};
export const venueEditorial = (venue) =>
  editorial[venue.slug] || {
    area: "Discover the dining room",
    cuisine: "Dining",
    eyebrow: "A place at the table",
    description:
      "Explore available tables and review your minimum spend before reserving.",
    tag: "In the collection",
  };
export const demoCities = [
  { id: 1, name: "New York", slug: "new-york", country_code: "US" },
  { id: 2, name: "Chicago", slug: "chicago", country_code: "US" },
  { id: 3, name: "Los Angeles", slug: "los-angeles", country_code: "US" },
];
export const demoVenues = [
  [1, "Carbone", "carbone", 25000],
  [2, "The Grill", "the-grill", 30000],
  [3, "Torrisi", "torrisi", 22500],
  [4, "COTE", "cote", 18500],
  [5, "53", "53", 17500],
  [6, "Chinese Tuxedo", "chinese-tuxedo", 15000],
].map(([id, name, slug, price]) => ({
  id,
  name,
  slug,
  city_id: 1,
  timezone: "America/New_York",
  status: "active",
  base_per_person_cents: price,
  currency: "USD",
}));

export function safeRead(storage, key, fallback) {
  try {
    const value = JSON.parse(storage.getItem(key));
    return value ?? fallback;
  } catch {
    return fallback;
  }
}
export function safeSave(storage, key, value) {
  try {
    storage.setItem(key, JSON.stringify(value));
    return true;
  } catch {
    return false;
  }
}

export const walletTransactionLabel = (item) =>
  ({
    booking: "Booking payment",
    cancel_early: "Cancellation refund",
    cancel_late: "Cancellation refund",
    goodwill_credit: "Credit added",
  })[item.type] || "Wallet activity";
export const signedMoney = (cents, currency = "USD") =>
  `${cents > 0 ? "+" : cents < 0 ? "−" : ""}${money(Math.abs(cents), currency)}`;
export function walletTransactionDate(value) {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? "Date unavailable"
    : new Intl.DateTimeFormat("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
        hour: "numeric",
        minute: "2-digit",
        timeZoneName: "short",
      }).format(date);
}
export function mergeWalletTransactions(current, incoming) {
  return [
    ...new Map(
      [...current, ...incoming].map((item) => [item.id, item]),
    ).values(),
  ];
}
export function demoWalletTransactions(reservations, beforeId = null) {
  const items = [];
  for (const r of reservations) {
    if (r.payment && r.confirmed_at)
      items.push({
        id: r.id * 2,
        type: "booking",
        reservation_id: r.id,
        venue_id: r.venue_id,
        amount_cents: r.min_spend_cents,
        currency: r.currency,
        wallet_change_cents:
          0 - r.payment.credit_cents - r.payment.coupon_cents,
        ...r.payment,
        forfeited_cents: null,
        occurred_at: r.confirmed_at,
      });
    const refund = reservationRefund(r);
    if (refund && r.cancelled_at && refund.credit_cents > 0)
      items.push({
        id: r.id * 2 + 1,
        type: refund.late ? "cancel_late" : "cancel_early",
        reservation_id: r.id,
        venue_id: r.venue_id,
        amount_cents: refund.credit_cents,
        currency: refund.currency,
        wallet_change_cents: refund.credit_cents,
        credit_cents: refund.credit_cents,
        coupon_cents: 0,
        card_cents: 0,
        forfeited_cents: refund.forfeited_cents,
        occurred_at: r.cancelled_at,
      });
  }
  items.sort(
    (a, b) =>
      Date.parse(b.occurred_at) - Date.parse(a.occurred_at) || b.id - a.id,
  );
  const start =
    beforeId === null ? 0 : items.findIndex((item) => item.id === beforeId) + 1;
  if (beforeId !== null && start === 0)
    return { transactions: [], next_before_id: null };
  const page = items.slice(start, start + 20);
  return {
    transactions: page,
    next_before_id: items.length > start + 20 ? page.at(-1).id : null,
  };
}

export class DemoClient {
  constructor(storage) {
    this.storage = storage;
    this.key = "dorsia-experience-demo-v1";
    const stored = safeRead(storage, this.key, null);
    this.state =
      stored?.version === 1 &&
      Array.isArray(stored.reservations) &&
      stored.wallet
        ? stored
        : {
            version: 1,
            reservations: [],
            holds: {},
            operations: {},
            wallet: {
              credit_cents: 30000,
              coupon_cents: 10000,
              currency: "USD",
            },
          };
    this.slots = new Map();
    this.persistent = true;
    // Older demo records already retain the original cancellation receipt in their operation log.
    let recovered = false;
    for (const reservation of this.state.reservations) {
      if (!isCancelled(reservation) || reservationRefund(reservation)) continue;
      const receipt = Object.values(this.state.operations || {}).find(
        (operation) =>
          operation.result?.reservation?.id === reservation.id &&
          reservationRefund({
            ...reservation,
            refund: operation.result?.refund,
          }),
      );
      if (receipt) {
        reservation.refund = structuredClone(receipt.result.refund);
        recovered = true;
      }
    }
    if (recovered) this.save();
  }
  nextId() {
    this.state.sequence = Math.max(Date.now(), (this.state.sequence || 0) + 1);
    return this.state.sequence;
  }
  save() {
    this.persistent = safeSave(this.storage, this.key, this.state);
  }
  async request(path) {
    if (path === "/cities") return demoCities;
    if (path === "/venues") return demoVenues;
    if (path === "/wallet") return structuredClone(this.state.wallet);
    if (
      path === "/wallet/transactions" ||
      path.startsWith("/wallet/transactions?")
    ) {
      const before = new URLSearchParams(path.split("?")[1]).get("before_id");
      return structuredClone(
        demoWalletTransactions(
          this.state.reservations,
          before ? Number(before) : null,
        ),
      );
    }
    if (path === "/reservations")
      return structuredClone(this.state.reservations);
    if (/^\/reservations\/\d+\/cancellation-preview$/.test(path)) {
      return demoCancellationPreview(
        this.state.reservations.find(
          (r) => r.id === Number(path.split("/")[2]),
        ),
      );
    }
    if (path.startsWith("/holds/")) {
      const hold = this.state.holds[path.split("/").at(-1)];
      if (!hold)
        throw new ApiError("not_found", "This hold was not found.", 404);
      if (!remainingSeconds(hold) && hold.status === "active")
        hold.status = "expired";
      return structuredClone(hold);
    }
    const match = path.match(/^\/venues\/(\d+)\/availability\?(.*)$/);
    if (match) {
      const query = new URLSearchParams(match[2]);
      const date = query.get("date");
      const party = Number(query.get("party_size"));
      const venue = demoVenues.find((v) => v.id === Number(match[1]));
      const slots = [18, 19, 20, 21, 22].map((hour, i) => {
        // Demo slot identity includes the date, party band, and venue. Display time is explicitly venue local.
        const band = party <= 2 ? 1 : party <= 4 ? 3 : 5;
        const id = Number(
          `${date.replaceAll("-", "")}${venue.id}${hour}${band}`,
        );
        const local = venueDateTime(date, hour, venue.timezone);
        const booked = this.state.reservations.filter(
          (r) => r.slot_id === id && r.status === "confirmed",
        ).length;
        const held = Object.values(this.state.holds).filter(
          (h) =>
            h.slot.id === id &&
            h.status === "active" &&
            remainingSeconds(h) > 0,
        ).length;
        const capacity = i === 2 && venue.id % 2 === 0 ? 0 : 3;
        const available = Math.max(0, capacity - booked - held);
        const slot = {
          id,
          venue_id: venue.id,
          starts_at: new Date(local).toISOString(),
          local_starts_at: local,
          available,
          capacity,
          min_party_size: band,
          max_party_size: band + 1,
          bookable:
            available > 0 && party <= 6 && Date.parse(local) > Date.now(),
        };
        this.slots.set(id, slot);
        return slot;
      });
      return {
        venue_id: venue.id,
        timezone: venue.timezone,
        date,
        party_size: party,
        hour: Number(query.get("hour")),
        slots,
      };
    }
    throw new ApiError("not_found", "This preview record was not found.", 404);
  }
  async write(operation) {
    const old = this.state.operations[operation.key];
    if (old) {
      if (old.path !== operation.path || old.body !== operation.body)
        throw new ApiError(
          "idempotency_mismatch",
          "Request details changed.",
          422,
        );
      return structuredClone(old.result);
    }
    const body = JSON.parse(operation.body);
    let result;
    if (operation.path === "/holds") {
      const slot = this.slots.get(body.slot_id);
      if (!slot?.bookable || Date.parse(slot.starts_at) <= Date.now())
        throw new ApiError(
          "sold_out",
          "This time is no longer available.",
          409,
        );
      const reserved = this.state.reservations.filter(
        (r) => r.slot_id === slot.id && r.status === "confirmed",
      ).length;
      if (reserved >= slot.capacity)
        throw new ApiError(
          "sold_out",
          "This time is no longer available.",
          409,
        );
      if (
        !Number.isInteger(body.party_size) ||
        body.party_size < slot.min_party_size ||
        body.party_size > slot.max_party_size
      )
        throw new ApiError(
          "party_size_not_allowed",
          "Choose a table that fits your party.",
          422,
        );
      if (
        Object.values(this.state.holds).some(
          (h) =>
            h.slot.id === slot.id &&
            h.status === "active" &&
            remainingSeconds(h) > 0,
        )
      )
        throw new ApiError(
          "active_hold_exists",
          "This time is already held.",
          409,
        );
      const venue = demoVenues.find((v) => v.id === slot.venue_id);
      const expires = new Date(Date.now() + 300000).toISOString();
      result = {
        id: this.nextId(),
        status: "active",
        party_size: body.party_size,
        expires_at: expires,
        slot,
        quote: {
          per_person_cents: venue.base_per_person_cents,
          amount_cents: venue.base_per_person_cents * body.party_size,
          currency: "USD",
          expires_at: expires,
        },
      };
      this.state.holds[result.id] = result;
    } else if (operation.path === "/reservations") {
      const hold = this.state.holds[body.hold_id];
      if (!hold || !remainingSeconds(hold))
        throw new ApiError("hold_expired", "Hold expired.", 409);
      if (hold.status !== "active")
        throw new ApiError("hold_not_active", "Hold no longer active.", 409);
      const payment = spendPlan(hold.quote.amount_cents, this.state.wallet);
      if (payment.card_cents && !body.card_token)
        throw new ApiError(
          "card_required",
          "A test payment method is required.",
          422,
        );
      if (payment.card_cents && body.card_token !== "tok_ok") {
        hold.status = "released";
        this.save();
        if (body.card_token === "tok_timeout")
          throw new ApiError("payment_timeout", "Test payment timed out.", 504);
        throw new ApiError("payment_failed", "Test payment declined.", 402, {
          decline_code:
            body.card_token === "tok_insufficient_funds"
              ? "insufficient_funds"
              : "card_declined",
        });
      }
      result = {
        id: this.nextId(),
        hold_id: hold.id,
        status: "confirmed",
        slot_id: hold.slot.id,
        venue_id: hold.slot.venue_id,
        party_size: hold.party_size,
        starts_at: hold.slot.starts_at,
        local_starts_at: hold.slot.local_starts_at,
        min_spend_cents: hold.quote.amount_cents,
        currency: "USD",
        payment,
        confirmed_at: new Date().toISOString(),
        cancelled_at: null,
      };
      hold.status = "converted";
      this.state.reservations.unshift(result);
      this.state.wallet.credit_cents -= payment.credit_cents;
      this.state.wallet.coupon_cents -= payment.coupon_cents;
    } else if (/^\/reservations\/\d+\/cancel$/.test(operation.path)) {
      const r = this.state.reservations.find(
        (r) => r.id === Number(operation.path.split("/")[2]),
      );
      const preview = demoCancellationPreview(r);
      if (
        body.expected_refund &&
        ["credit_cents", "forfeited_cents", "currency", "late"].some(
          (key) => body.expected_refund[key] !== preview.refund[key],
        )
      )
        throw new ApiError(
          "cancellation_preview_changed",
          "Review the updated refund before cancelling.",
          409,
        );
      const late = preview.refund.late;
      const credit = preview.refund.credit_cents;
      r.status = late ? "cancelled_late" : "cancelled_early";
      r.cancelled_at = new Date().toISOString();
      r.refund = structuredClone(preview.refund);
      this.state.wallet.credit_cents += credit;
      result = {
        reservation: r,
        refund: {
          credit_cents: credit,
          forfeited_cents: r.min_spend_cents - credit,
          currency: "USD",
          late,
        },
      };
    } else throw new ApiError("not_found", "Unknown preview action.", 404);
    this.state.operations[operation.key] = {
      ...operation,
      result: structuredClone(result),
    };
    this.save();
    return structuredClone(result);
  }
}
