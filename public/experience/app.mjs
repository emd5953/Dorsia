import { icon } from "./icons.mjs";
import {
  escapeHtml as e,
  money,
  dateInZone,
  addDays,
  displayDate,
  localTime,
  timeLabel,
  spendPlan,
  eligibleSlots,
  isUpcoming,
  isCancelled,
  reservationLabel,
  reservationRefund,
  walletTransactionLabel,
  walletTransactionDate,
  signedMoney,
  mergeWalletTransactions,
  remainingSeconds,
  newOperation,
  confirmationOperation,
  cancellationOperation,
  cancellationPreviewDeadline,
  cancellationDeadlineLabel,
  testPaymentMethods,
  testCardToken,
  ApiClient,
  ApiError,
  DemoClient,
  errorMessage,
  venueEditorial,
  demoVenues,
  safeRead,
  safeSave,
} from "./core.mjs";
import { MemberSession, emptyCheckout, recoverCheckout } from "./session.mjs";
import { OperationsStore } from "./operations.mjs";

const app = document.querySelector("#app");
const dialog = document.querySelector("#dialog");
const memoryStorage = new Map();
let storage;
try {
  storage = localStorage;
} catch {
  storage = {
    getItem: (k) => memoryStorage.get(k),
    setItem: (k, v) => memoryStorage.set(k, v),
  };
}
let tabStorage;
try {
  tabStorage = sessionStorage;
} catch {
  tabStorage = null;
}
const session = new MemberSession(tabStorage);
const savedSession = session.read();
let restoreReview = Boolean(savedSession);
const api = new ApiClient();
const demo = new DemoClient(storage);
const ops = new OperationsStore(storage);
const rawSaved = safeRead(storage, "dorsia-saved-v1", []);
const state = {
  mode: savedSession ? "live" : "demo",
  client: savedSession ? api : demo,
  member: {
    name: savedSession ? "Member" : "Alex",
    email: "alex@example.test",
  },
  cities: [],
  venues: [],
  bookings: [],
  wallet: null,
  walletHistory: [],
  walletHistoryLoaded: false,
  walletHistoryLoading: false,
  walletHistoryError: null,
  walletNext: null,
  loading: true,
  error: null,
  city: "1",
  date: addDays(dateInZone(), 1),
  party: 2,
  query: "",
  cuisine: "All tables",
  saved: new Set(Array.isArray(rawSaved) ? rawSaved : []),
  bookingTab: "upcoming",
  hold: null,
  holdOperation: null,
  confirmOperation: null,
  confirmPlan: null,
  paymentMethod: "",
  cancelOperation: null,
  cancelPreview: null,
  cancelPreviewUntil: 0,
  confirmPending: false,
  slots: [],
  slotsLoading: false,
  slotsError: null,
  selectedVenue: null,
  opsFilter: "all",
  opsQuery: "",
  opsVenue: "all",
  opsDate: dateInZone(),
  busy: false,
};
function persistCheckout() {
  if (state.mode !== "live") return;
  session.checkout({
    holdId: state.hold?.id,
    holdOperation: state.holdOperation?.operation,
    confirmOperation: state.confirmOperation,
    confirmPlan: state.confirmPlan,
    paymentMethod: state.paymentMethod,
  });
}
async function signOut(message = "Signed out. You are back in the preview.") {
  session.clear();
  api.logout();
  availabilityEpoch++;
  resetWalletHistory();
  closeDialog();
  Object.assign(state, emptyCheckout(), {
    mode: "demo",
    client: demo,
    member: { name: "Alex", email: "alex@example.test" },
    cancelOperation: null,
    cancelPreview: null,
    bookings: [],
    wallet: null,
    slots: [],
    busy: false,
  });
  restoreReview = false;
  await loadData();
  toast(message);
}
async function sessionError(error) {
  if (state.mode !== "live" || error.status !== 401) return false;
  await signOut("Your session has ended. Sign in again to continue.");
  loginDialog();
  return true;
}
let walletHistoryEpoch = 0;
let cancelReviewEpoch = 0;
let routeEpoch = 0,
  availabilityEpoch = 0,
  toastTimer,
  returnFocus = null,
  modalType = null;
const route = () => location.hash.slice(1) || "discover";
const activePage = () => route().split("/")[0];
const venueById = (id) => state.venues.find((v) => v.id === Number(id));
const previewVenue = (id) => demoVenues.find((v) => v.id === Number(id));
const button = (label, action, cls = "primary", attrs = "") =>
  `<button class="btn ${cls}" data-action="${action}" ${attrs}>${label}</button>`;
const badge = (text, kind = "") =>
  `<span class="badge ${kind}">${e(text)}</span>`;
const sectionHeading = (eyebrow, title, description = "") =>
  `<div class="section-heading"><p class="eyebrow">${eyebrow}</p><h1>${title}</h1>${description ? `<p class="muted">${description}</p>` : ""}</div>`;
const empty = (
  title,
  description,
  action = "discover",
  label = "Explore tables",
) =>
  `<div class="empty-state">${icon("spark")}<h2>${e(title)}</h2><p>${e(description)}</p>${button(label, action, "secondary")}</div>`;
const errorBox = (message, action = "reload", label = "Try again") =>
  `<div class="error-box" role="alert">${icon("alert")}<div><strong>Let’s try that again</strong><p>${e(message)}</p></div>${button(label, action, "secondary small")}</div>`;
function announce(message) {
  document.querySelector("#announcer").textContent = message;
}
function toast(message) {
  const element = document.querySelector("#toast");
  element.textContent = message;
  element.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (element.hidden = true), 4500);
}
function navigate(path) {
  if (route() === path) render();
  else location.hash = path;
}
function imageHtml(venue, cls = "", priority = false) {
  const meta = venueEditorial(venue);
  return meta.image
    ? `<img class="${cls}" src="./assets/${meta.image}.jpg" alt="${e(venue.name)} dining room" ${priority ? 'fetchpriority="high"' : 'loading="lazy"'}>`
    : `<div class="image-placeholder ${cls}" role="img" aria-label="${e(venue.name)} — photography coming soon">${icon("spark")}<span>${e(venue.name)}</span></div>`;
}
function mainNavigation() {
  return [
    ["discover", "Discover", "home"],
    ["reservations", "Reservations", "ticket"],
    ["saved", "Saved", "heart"],
    ["membership", "Membership", "user"],
  ]
    .map(
      ([path, label, symbol]) =>
        `<a href="#${path}" ${activePage() === path ? 'aria-current="page"' : ""}>${icon(symbol)}<span>${label}</span></a>`,
    )
    .join("");
}
function shell(content, operations = false) {
  document.body.classList.toggle("operations-mode", operations);
  app.innerHTML =
    `<div class="mode-strip ${operations ? "ops-strip" : ""}"><span>${operations ? "OPERATIONS PREVIEW · Sample service data. Changes stay in this browser." : state.mode === "demo" ? "PREVIEW EXPERIENCE · Sample tables. No payments are made." : "CONNECTED ACCOUNT · Test payments. No real card is charged."}</span>${operations ? '<a href="#discover">Return to member experience ' + icon("arrow") + "</a>" : state.mode === "demo" ? '<button data-action="login">Sign in ' + icon("arrow") + "</button>" : `<button data-action="logout" ${state.busy ? "disabled" : ""}>Sign out ${icon("logout")}</button>`}</div>
  <header class="site-header"><a href="#discover" class="wordmark" aria-label="Dorsia home">DORSIA</a>${operations ? '<span class="workspace-label">SERVICE DESK</span>' : `<nav class="desktop-nav" aria-label="Main navigation">${mainNavigation()}</nav>`}<div class="header-actions">${operations ? '<span class="service-dot"></span><span class="small-copy">Service in focus</span>' : `<a class="ops-link" href="#operations" aria-label="Operations">${icon("grid")}<span>Operations</span></a><button class="avatar" data-action="account" aria-label="View account">${e(state.member.name.slice(0, 1))}</button>`}</div></header>
  ${state.hold && (remainingSeconds(state.hold) > 0 || state.confirmPending) && !operations ? `<div class="active-hold"><span>${icon("clock")} ${state.confirmPending ? "Your confirmation needs a check" : "Your table is held for"} <strong class="hold-timer">${state.confirmPending ? "" : countdown()}</strong></span><button data-action="resume-hold">Return to review ${icon("arrow")}</button></div>` : ""}
  ${state.holdOperation?.uncertain && !operations ? `<div class="active-hold"><span>${icon("clock")} Your table request needs a check</span><button data-action="retry-hold" ${state.busy ? "disabled" : ""}>Retry table request ${icon("arrow")}</button></div>` : ""}
  ${content}
  ${operations ? "" : `<footer class="site-footer"><a class="wordmark" href="#discover">DORSIA</a><p>A place at the table. A world of possibility.</p><a href="https://www.dorsia.com/faq" target="_blank" rel="noopener noreferrer">About Dorsia ${icon("arrow")}</a></footer><nav class="mobile-nav" aria-label="Mobile navigation">${mainNavigation()}</nav>`}`.replaceAll(
      "\n+",
      "\n",
    );
}
function skeleton() {
  return `<main id="main" class="page" tabindex="-1"><div class="skeleton skeleton-title"></div><div class="skeleton skeleton-hero"></div><div class="venue-grid">${[1, 2, 3].map(() => '<div class="skeleton skeleton-card"></div>').join("")}</div><span class="sr-only" role="status">Loading your experience</span></main>`;
}
async function loadData() {
  resetWalletHistory();
  const epoch = ++routeEpoch;
  state.loading = true;
  state.error = null;
  render();
  try {
    if (state.mode === "live") {
      const member = await session.restore(api);
      if (epoch !== routeEpoch) return;
      if (!member) {
        await signOut();
        return;
      }
      state.member = member;
    }
    const [cities, venues, bookings, wallet] = await Promise.all(
      ["/cities", "/venues", "/reservations", "/wallet"].map((path) =>
        state.client.request(path),
      ),
    );
    if (epoch !== routeEpoch) return;
    const recovered =
      state.mode === "live"
        ? await recoverCheckout(api, session.read()?.checkout, bookings)
        : null;
    if (epoch !== routeEpoch) return;
    Object.assign(
      state,
      { cities, venues, bookings, wallet, loading: false },
      recovered,
    );
    if (recovered?.hold) {
      state.date = recovered.hold.slot.local_starts_at.slice(0, 10);
      state.party = recovered.hold.party_size;
    }
    persistCheckout();
    if (!cities.some((c) => String(c.id) === state.city))
      state.city = String(cities[0]?.id || "");
    render();
    if (activePage() === "venue") loadAvailability();
    if (route() === "membership/wallet") loadWalletHistory();
    if (restoreReview && recovered?.hold && activePage() !== "operations")
      reviewDialog();
    if (recovered?.recoveredReservation) {
      state.bookingTab = isUpcoming(recovered.recoveredReservation)
        ? "upcoming"
        : "past";
      navigate("reservations");
      toast(
        "Your booking was already completed. Your reservations are up to date.",
      );
    } else if (recovered?.recoveryNotice) toast(recovered.recoveryNotice);
    restoreReview = false;
  } catch (error) {
    if (epoch !== routeEpoch) return;
    if (await sessionError(error)) return;
    state.loading = false;
    state.error = error;
    render();
  }
}
function filters(detail = false) {
  return `<form id="search-form" class="search-controls ${detail ? "detail-controls" : ""}">
    ${!detail ? `<label class="filter-field">${icon("pin")}<span><span class="field-caption">Where to?</span><select name="city" aria-label="City">${state.cities.map((c) => `<option value="${c.id}" ${state.city === String(c.id) ? "selected" : ""}>${e(c.name)}</option>`).join("")}</select></span></label>` : ""}
    <label class="filter-field">${icon("calendar")}<span><span class="field-caption">Your evening</span><input type="date" name="date" required aria-label="Reservation date" value="${state.date}" min="${dateInZone(new Date(), detail ? state.selectedVenue?.timezone : "America/New_York")}"></span></label>
    <label class="filter-field">${icon("users")}<span><span class="field-caption">At the table</span><select name="party" aria-label="Party size">${[1, 2, 3, 4, 5, 6, 7, 8, 10, 12, 16, 20].map((n) => `<option value="${n}" ${state.party === n ? "selected" : ""}>${n} ${n === 1 ? "guest" : "guests"}</option>`).join("")}</select></span></label>
    <button class="btn primary search-submit" type="submit">${icon("search")}<span>${detail ? "Find times" : "Explore tables"}</span></button>
  </form>`;
}
function venueCard(venue) {
  const meta = venueEditorial(venue),
    saved = state.saved.has(venue.slug);
  return `<article class="venue-card"><div class="card-image"><a href="#venue/${venue.id}" aria-label="Explore ${e(venue.name)}">${imageHtml(venue)}</a><span class="image-label">${e(meta.tag)}</span><button class="save-button ${saved ? "saved" : ""}" data-action="save" data-id="${venue.id}" aria-label="${saved ? "Unsave" : "Save"} ${e(venue.name)}" aria-pressed="${saved}">${icon("heart")}</button></div><div class="card-heading"><a href="#venue/${venue.id}"><h3>${e(venue.name)}</h3></a>${icon("arrow")}</div><p class="venue-meta">${e(meta.area)}<span>·</span>${e(meta.cuisine)}</p><div class="card-bottom"><p><strong>${money(venue.base_per_person_cents, venue.currency)}</strong><span> base / person</span></p><a href="#venue/${venue.id}">Find a table ${icon("arrow")}</a></div></article>`;
}
function discover(savedPage = false) {
  const venues = state.venues.filter(
    (v) =>
      (savedPage
        ? state.saved.has(v.slug)
        : String(v.city_id) === state.city) &&
      (state.cuisine === "All tables" ||
        venueEditorial(v).cuisine === state.cuisine) &&
      `${v.name} ${venueEditorial(v).area} ${venueEditorial(v).cuisine}`
        .toLowerCase()
        .includes(state.query.toLowerCase()),
  );
  const featured =
    state.venues.find(
      (v) => String(v.city_id) === state.city && v.slug === "the-grill",
    ) || state.venues.find((v) => String(v.city_id) === state.city);
  return `<main id="main" class="page" tabindex="-1">${savedPage ? sectionHeading("YOUR LITTLE BLACK BOOK", "Places to come back to.", "Keep the tables you have your eye on, all in one place.") : `<div class="welcome-row"><p class="eyebrow">THE WORLD IS YOUR DINING ROOM</p><span>${icon("pin")}${e(state.cities.find((c) => String(c.id) === state.city)?.name || "Your city")}</span></div>${featured ? `<section class="hero" aria-label="Featured dining room">${imageHtml(featured, "hero-image", true)}<div class="hero-shade"></div><div class="hero-copy"><span class="hero-kicker">THE DORSIA EDIT · 01</span><h1>The art of<br>a night <em>out.</em></h1><p>Extraordinary tables. Even better company.</p><a href="#venue/${featured.id}" class="btn light">Discover ${e(featured.name)} ${icon("arrow")}</a></div><div class="hero-caption"><span>${e(featured.name).toUpperCase()}</span><span>${e(venueEditorial(featured).area)} · ${e(state.cities.find((c) => c.id === featured.city_id)?.name || "")}</span></div></section>` : ""}${filters()}`}
    <section class="collection" aria-labelledby="collection-title"><div class="collection-title"><div><p class="eyebrow">${savedPage ? "SAVED FOR LATER" : "CURATED FOR YOUR EVENING"}</p><h2 id="collection-title">${savedPage ? "Your collection" : "A table with your name on it."}</h2></div><span class="count" role="status" aria-live="polite">${venues.length} ${venues.length === 1 ? "place" : "places"} to discover</span></div>
    <div class="collection-toolbar"><div class="chips" aria-label="Filter by cuisine">${["All tables", ...new Set(state.venues.filter((v) => (savedPage ? state.saved.has(v.slug) : String(v.city_id) === state.city)).map((v) => venueEditorial(v).cuisine))].map((c) => `<button class="chip ${state.cuisine === c ? "active" : ""}" data-action="cuisine" data-value="${e(c)}" aria-pressed="${state.cuisine === c}">${e(c)}</button>`).join("")}</div><label class="inline-search">${icon("search")}<input id="venue-query" name="query" type="search" placeholder="Find a restaurant" aria-label="Find a restaurant" value="${e(state.query)}"></label></div>
    <div class="venue-grid">${venues.map(venueCard).join("")}</div>${!venues.length ? empty(savedPage ? "Your next favorite is out there." : "A different evening awaits.", savedPage ? "Tap the heart on a restaurant to add it to your collection." : "Try another city, cuisine, or restaurant name.", "reset-filters", savedPage ? "Discover restaurants" : "Reset filters") : ""}</section>
    ${!savedPage ? `<section class="member-banner"><div class="ornament" aria-hidden="true">✳</div><div><p class="eyebrow">MORE THAN A RESERVATION</p><h2>Make yourself at home in the world.</h2><p>Discover how membership and dining credit work together.</p></div><a class="btn secondary" href="#membership">Your membership ${icon("arrow")}</a></section>` : ""}
  </main>`;
}
function availability() {
  if (state.slotsLoading)
    return `<div class="slots-loading" aria-busy="true"><span class="spinner"></span><span>Finding your table…</span></div>`;
  if (state.slotsError)
    return errorBox(
      errorMessage(state.slotsError),
      "availability-retry",
      "Refresh times",
    );
  const slots = eligibleSlots(state.slots, state.party);
  if (!slots.length)
    return `<div class="empty-times"><h3>No tables for this evening.</h3><p>Try another date or a smaller party.</p>${button("Try the next day " + icon("arrow"), "next-date", "secondary")}</div>`;
  return `<p class="availability-heading">${icon("check")} ${slots.length} available ${slots.length === 1 ? "time" : "times"}<span>Venue local time</span></p><div class="time-grid">${slots.map((s) => `<button class="time-slot" data-action="hold" data-id="${s.id}" ${state.busy ? "disabled" : ""}><strong>${localTime(s.local_starts_at)}</strong><span>${s.available === 1 ? "Last table" : "View minimum"}</span></button>`).join("")}</div><p class="fine-print">Select a time to hold your table and see the exact minimum spend.</p>`;
}
function venueDetail() {
  const venue = venueById(route().split("/")[1]);
  state.selectedVenue = venue;
  if (!venue)
    return `<main class="page" id="main">${empty("This dining room is unavailable.", "Return to the collection to discover another table.")}</main>`;
  const meta = venueEditorial(venue);
  return `<main class="page detail-page" id="main" tabindex="-1"><a class="back-link" href="#discover">${icon("back")} Back to the collection</a><div class="detail-hero">${imageHtml(venue, "", true)}<div class="hero-shade"></div><div class="detail-title"><p class="eyebrow">${e(meta.eyebrow)}</p><h1>${e(venue.name)}</h1><p>${icon("pin")}${e(meta.area)}<span>·</span>${e(meta.cuisine)}</p></div><button class="save-button ${state.saved.has(venue.slug) ? "saved" : ""}" data-action="save" data-id="${venue.id}" aria-label="${state.saved.has(venue.slug) ? "Unsave" : "Save"} ${e(venue.name)}" aria-pressed="${state.saved.has(venue.slug)}">${icon("heart")}</button></div><div class="detail-layout"><section class="venue-story"><p class="eyebrow">THE EXPERIENCE</p><h2>An evening worth<br>making time for.</h2><p class="story-copy">${e(meta.description)}</p><div class="detail-facts"><div>${icon("users")}<strong>Good company, guaranteed</strong><p>Choose your party size to see tables that fit.</p></div><div>${icon("card")}<strong>A minimum, made clear</strong><p>Your prepaid minimum goes toward dining. The final quote appears before you confirm.</p></div></div><details class="policy-disclosure"><summary>Before your evening ${icon("chevron")}</summary><p>Minimum spend covers dining; any extras, tax, and gratuity are settled separately. Unused minimum spend is not refunded.</p><p>Review the cancellation terms before confirming. Returned funds are dining credit, not a card refund.</p></details></section><section class="availability-panel" aria-labelledby="availability-title"><div class="panel-heading"><p class="eyebrow">YOUR INVITATION</p><h2 id="availability-title">Find your table.</h2><p>Base minimum <strong>${money(venue.base_per_person_cents, venue.currency)}</strong> per person</p></div>${filters(true)}<div id="availability-results" aria-live="polite">${availability()}</div></section></div></main>`;
}
async function loadAvailability() {
  const venue = state.selectedVenue;
  if (!venue) return;
  const epoch = ++availabilityEpoch;
  state.slotsLoading = true;
  state.slotsError = null;
  updateAvailability();
  try {
    const query = new URLSearchParams({
      date: state.date,
      hour: "19",
      party_size: String(state.party),
    });
    const result = await state.client.request(
      `/venues/${venue.id}/availability?${query}`,
    );
    if (epoch !== availabilityEpoch) return;
    state.slots = result.slots;
    state.slotsLoading = false;
    updateAvailability();
  } catch (error) {
    if (epoch !== availabilityEpoch) return;
    if (await sessionError(error)) return;
    state.slotsLoading = false;
    state.slotsError = error;
    updateAvailability();
  }
}
function updateAvailability() {
  const region = document.querySelector("#availability-results");
  if (region) region.innerHTML = availability();
}
function cancellationHistory(r, compact = false) {
  if (!isCancelled(r)) return "";
  const refund = reservationRefund(r);
  if (!refund)
    return '<p class="fine-print">Refund details are unavailable for this reservation.</p>';
  return `<section class="cancellation-history ${compact ? "compact" : ""}" aria-label="Cancellation refund"><dl class="money-lines"><div><dt>Dining credit returned</dt><dd>${money(refund.credit_cents, refund.currency)}</dd></div><div><dt>Amount forfeited</dt><dd>${money(refund.forfeited_cents, refund.currency)}</dd></div></dl>${compact ? "" : '<p class="fine-print">Returned as dining credit for a future evening, not a card refund.</p>'}</section>`;
}
function bookingCard(r) {
  const venue = venueById(r.venue_id) || { name: "Your restaurant", slug: "" };
  return `<article class="booking-card">${imageHtml(venue)}<div class="booking-content"><div class="row-between">${badge(reservationLabel(r), isCancelled(r) ? "muted-badge" : "positive")}<span class="booking-ref">D${r.id}</span></div><h2>${e(venue.name)}</h2><p>${icon("calendar")}${displayDate(r.local_starts_at)} · ${localTime(r.local_starts_at)}</p><p>${icon("users")}${r.party_size} guests <span>·</span> ${money(r.min_spend_cents, r.currency)} minimum</p>${cancellationHistory(r, true)}<button class="text-button" data-action="booking-detail" data-id="${r.id}">View reservation ${icon("arrow")}</button></div></article>`;
}
function reservations() {
  state.bookingTab = route().split("/")[1] === "past" ? "past" : "upcoming";
  const bookings = state.bookings.filter((r) =>
    state.bookingTab === "upcoming" ? isUpcoming(r) : !isUpcoming(r),
  );
  return `<main class="page" id="main" tabindex="-1">${sectionHeading("GOOD TIMES, ON THE CALENDAR", "Your next great evening.", "The details are taken care of. All that’s left is to be there.")}<div class="row-between booking-toolbar"><div class="segmented">${["upcoming", "past"].map((tab) => `<button data-action="booking-tab" data-value="${tab}" class="${state.bookingTab === tab ? "active" : ""}" aria-pressed="${state.bookingTab === tab}">${tab === "upcoming" ? "Upcoming" : "Past & cancelled"}</button>`).join("")}</div>${button(icon("refresh") + " Refresh", "reload", "text-button")}</div><div class="bookings-grid">${bookings.map(bookingCard).join("")}</div>${!bookings.length ? empty(state.bookingTab === "upcoming" ? "Your next evening is unwritten." : "A fresh start.", "Find a table worth looking forward to. Your reservations will appear here.") : ""}</main>`;
}
function resetWalletHistory() {
  walletHistoryEpoch++;
  Object.assign(state, {
    walletHistory: [],
    walletHistoryLoaded: false,
    walletHistoryLoading: false,
    walletHistoryError: null,
    walletNext: null,
  });
}
async function loadWalletHistory(more = false) {
  if (
    state.loading ||
    state.walletHistoryLoading ||
    (more && !state.walletNext)
  )
    return;
  const epoch = ++walletHistoryEpoch;
  const client = state.client;
  state.walletHistoryLoading = true;
  state.walletHistoryError = null;
  render();
  try {
    const page = await client.request(
      `/wallet/transactions${more ? `?before_id=${state.walletNext}` : ""}`,
    );
    if (epoch !== walletHistoryEpoch || client !== state.client) return;
    state.walletHistory = mergeWalletTransactions(
      more ? state.walletHistory : [],
      page.transactions,
    );
    state.walletNext = page.next_before_id;
    state.walletHistoryLoaded = true;
  } catch (error) {
    if (epoch !== walletHistoryEpoch || client !== state.client) return;
    if (await sessionError(error)) return;
    state.walletHistoryError = error;
  } finally {
    if (epoch === walletHistoryEpoch) {
      state.walletHistoryLoading = false;
      if (route() === "membership/wallet") render();
    }
  }
}
function walletTransactionRow(item) {
  const booking = item.type === "booking";
  const venue = venueById(item.venue_id);
  const reference = item.reservation_id
    ? `Reservation D${item.reservation_id}${venue ? ` · ${e(venue.name)}` : ""}`
    : "Member wallet";
  const breakdown = booking
    ? `${money(item.credit_cents, item.currency)} dining credit · ${money(item.coupon_cents, item.currency)} Fun Coupons · ${money(item.card_cents, item.currency)} test card`
    : `${item.coupon_cents ? "Added to Fun Coupons" : "Added to dining credit"}${item.forfeited_cents !== null ? ` · ${money(item.forfeited_cents, item.currency)} forfeited` : ""}`;
  return `<li class="wallet-transaction"><div class="wallet-transaction-heading"><div><h3>${walletTransactionLabel(item)}</h3><p>${reference}</p></div><div class="transaction-amount ${booking ? "" : "credit"}"><strong>${signedMoney(booking ? -item.amount_cents : item.amount_cents, item.currency)}</strong><span>${booking ? "Payment total" : item.type === "goodwill_credit" ? "Credit added" : "Credit returned"}</span></div></div><p class="transaction-breakdown">${breakdown}</p><div class="transaction-foot"><time datetime="${e(item.occurred_at)}">${e(walletTransactionDate(item.occurred_at))}</time><span>Wallet ${signedMoney(item.wallet_change_cents, item.currency)}</span></div></li>`;
}
function walletHistoryPage() {
  const wallet = state.wallet;
  return `<main class="page wallet-history-page" id="main" tabindex="-1"><a class="back-link" href="#membership">${icon("back")} Membership</a>${sectionHeading("YOUR DINING CREDIT", "Every evening, accounted for.", "Booking payments and returned credit, in one place.")}<div class="wallet-summary"><div><span>Dining credit</span><strong>${money(wallet.credit_cents, wallet.currency)}</strong></div><div><span>Fun Coupons</span><strong>${money(wallet.coupon_cents, wallet.currency)}</strong></div></div><section class="wallet-history" aria-labelledby="wallet-history-title"><div class="row-between"><h2 id="wallet-history-title">Transaction history</h2>${button(icon("refresh") + " Refresh", "reload", "text-button")}</div><p class="muted">Payment totals include test-card spend. Wallet changes show dining credit and Fun Coupons only. Refunds return as dining credit.</p>${state.walletHistory.length ? `<ul class="wallet-transactions">${state.walletHistory.map(walletTransactionRow).join("")}</ul>` : ""}${state.walletHistoryLoading ? '<p role="status" class="history-status">Loading transactions…</p>' : ""}${state.walletHistoryError ? errorBox(errorMessage(state.walletHistoryError), state.walletHistoryLoaded ? "wallet-more" : "wallet-retry", "Retry transactions") : ""}${state.walletHistoryLoaded && !state.walletHistory.length ? '<div class="empty-state"><h3>No transactions yet.</h3><p>Your booking payments and cancellation refunds will appear here.</p></div>' : ""}${state.walletNext && !state.walletHistoryError ? button("Load older transactions", "wallet-more", "secondary full", state.walletHistoryLoading ? "disabled" : "") : ""}<p class="fine-print">${state.mode === "demo" ? "Sample activity from saved preview bookings. Your starting preview balance is separate." : "Showing posted transactions, newest first."} Dates are shown in your local time.</p></section></main>`;
}
function membership() {
  return `<main class="page" id="main" tabindex="-1">${sectionHeading("A WORLD OF POSSIBILITY", "Good taste. Great company.", "Your account, dining credit, and a little more about life with Dorsia.")}<div class="membership-layout"><section class="membership-card"><div class="row-between"><span class="wordmark">DORSIA</span>${icon("spark")}</div><div><p class="eyebrow">${state.mode === "demo" ? "PREVIEW MEMBER" : "MEMBER ACCOUNT"}</p><h2>${e(state.member.name)}</h2><p>${e(state.member.email)}</p></div><div class="card-foot"><span>THE WORLD IS YOUR DINING ROOM</span><span>✳</span></div></section><section class="wallet-panel"><p class="eyebrow">READY FOR YOUR NEXT EVENING</p><h2>Dining wallet</h2><div class="wallet-total">${money(state.wallet.credit_cents + state.wallet.coupon_cents, state.wallet.currency)}</div><dl class="money-lines"><div><dt>Dining credit</dt><dd>${money(state.wallet.credit_cents, state.wallet.currency)}</dd></div><div><dt>Fun Coupons</dt><dd>${money(state.wallet.coupon_cents, state.wallet.currency)}</dd></div></dl><p class="fine-print">Coupons apply first, then dining credit. Any remaining amount requires a payment method.</p><a class="btn secondary" href="#membership/wallet">View transactions ${icon("arrow")}</a></section></div><div class="membership-info"><section><p class="eyebrow">THE DETAILS, MADE SIMPLE</p><h2>Membership opens the door.<br>Dining spend sets the table.</h2><p>A subscription provides access. Each reservation has its own prepaid minimum that applies toward your evening.</p><a class="btn secondary" href="https://www.dorsia.com/faq" target="_blank" rel="noopener noreferrer">Explore membership details ${icon("arrow")}</a></section><div class="faq-list"><details open><summary>What membership do I have? ${icon("chevron")}</summary><p>${state.mode === "demo" ? "You are exploring a sample member account." : "Your member profile is connected."} Membership tier and renewal details are not available in this build.</p></details><details><summary>How does dining credit work? ${icon("chevron")}</summary><p>Eligible credit is automatically applied when you book. Cancellation refunds return as dining credit, not cash.</p></details><details><summary>What happens if plans change? ${icon("chevron")}</summary><p>Open your reservation to review cancellation terms. Before cancelling, review the exact dining credit returned and amount forfeited, calculated by the booking service.</p></details></div></div></main>`;
}

function render() {
  const page = activePage();
  document.title =
    page === "operations"
      ? "Dorsia — Service desk"
      : "Dorsia — A place at the table";
  if (page === "operations") {
    renderOperations();
    return;
  }
  if (state.loading) {
    shell(skeleton());
    return;
  }
  if (state.error) {
    shell(
      `<main class="page" id="main">${sectionHeading("YOUR EXPERIENCE", "A moment, please.")} ${errorBox(errorMessage(state.error))}${state.error.code === "unauthenticated" ? button("Sign in again", "login") : ""}</main>`,
    );
    return;
  }
  const content =
    page === "venue"
      ? venueDetail()
      : page === "reservations"
        ? reservations()
        : page === "membership"
          ? route() === "membership/wallet"
            ? walletHistoryPage()
            : membership()
          : discover(page === "saved");
  shell(content);
}
function openDialog(content, type) {
  if (!dialog.open) returnFocus = document.activeElement;
  modalType = type;
  dialog.innerHTML = `<button class="dialog-close" data-action="close" aria-label="Close dialog">${icon("close")}</button>${content}`;
  if (!dialog.open) dialog.showModal();
  document.body.classList.add("dialog-open");
  const focus =
    dialog.querySelector("[autofocus]") || dialog.querySelector("h2");
  if (focus) {
    focus.setAttribute("tabindex", "-1");
    focus.focus();
  }
}
function closeDialog() {
  if (state.busy) return;
  dialog.close();
  cancelReviewEpoch++;
  modalType = null;
  document.body.classList.remove("dialog-open");
  if (returnFocus?.isConnected) returnFocus.focus();
  else document.querySelector("#main")?.focus();
}
function modalError(error) {
  const target = dialog.querySelector(".modal-error");
  if (target)
    target.innerHTML = `<p role="alert">${icon("alert")}${e(errorMessage(error))}</p>`;
}
function countdown() {
  const seconds = state.hold ? remainingSeconds(state.hold) : 0;
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")}`;
}
function loginDialog() {
  openDialog(
    `<div class="modal-body"><p class="eyebrow">WELCOME BACK</p><h2 id="dialog-title">Your evening awaits.</h2><p class="muted">Sign in to connect your reservations and dining wallet. You’ll stay signed in when you refresh this tab.</p><form id="login-form" class="stack-form"><label>Email<input name="email" type="email" autocomplete="username" required placeholder="you@example.com"></label><label>Password<input name="password" type="password" autocomplete="current-password" required minlength="1"></label><div class="modal-error"></div><button class="btn primary" type="submit">Sign in ${icon("arrow")}</button></form><p class="fine-print">Use your account on this Dorsia rebuild. The public Dorsia app is a separate service.</p></div>`,
    "login",
  );
}
function testPaymentFields(payment, expired) {
  const locked = Boolean(state.confirmOperation) || state.busy || expired;
  return `<fieldset class="payment-method" ${locked ? "disabled" : ""}><legend>Cover the remaining ${money(payment.card_cents, state.hold.quote.currency)}</legend><p id="payment-help">Test payments only. Choose a sample card; no real card details are needed or charged.</p><label for="payment-method">Test card</label><select id="payment-method" aria-describedby="payment-help"><option value="">Choose a test card</option>${testPaymentMethods.map((method) => `<option value="${method.id}" ${state.paymentMethod === method.id ? "selected" : ""}>${e(method.label)}</option>`).join("")}</select>${state.confirmPending ? '<p role="status">Your payment choice is locked while we check this confirmation. Retrying uses the same request.</p>' : ""}</fieldset>`;
}
function reviewDialog() {
  if (!state.hold) return;
  const hold = state.hold;
  const venue = venueById(hold.slot.venue_id);
  const payment =
    state.confirmPlan ||
    (state.wallet ? spendPlan(hold.quote.amount_cents, state.wallet) : null);
  const expired = remainingSeconds(hold) === 0 || hold.status !== "active";
  const needsCard = payment?.card_cents > 0;
  const canRetry = state.confirmPending && state.confirmOperation;
  openDialog(
    `<div class="modal-body"><p class="eyebrow">YOUR TABLE IS WAITING</p><h2 id="dialog-title">An evening at ${e(venue?.name || "your restaurant")}.</h2><div class="hold-status ${expired ? "expired" : ""}">${icon("clock")}<span>${expired ? "This hold has expired." : "Your table is held for"} <strong class="hold-timer">${expired ? "" : countdown()}</strong></span></div><div class="review-details"><p>${icon("calendar")}${displayDate(hold.slot.local_starts_at)} · ${localTime(hold.slot.local_starts_at)}</p><p>${icon("users")}${hold.party_size} guests <span>· Venue local time</span></p></div><dl class="money-lines"><div><dt>Minimum per person</dt><dd>${money(hold.quote.per_person_cents, hold.quote.currency)}</dd></div><div class="total"><dt>Reservation minimum</dt><dd>${money(hold.quote.amount_cents, hold.quote.currency)}</dd></div>${payment ? `<div><dt>Fun Coupons</dt><dd>−${money(payment.coupon_cents, hold.quote.currency)}</dd></div><div><dt>Dining credit</dt><dd>−${money(payment.credit_cents, hold.quote.currency)}</dd></div><div class="total"><dt>Test card payment</dt><dd>${money(payment.card_cents, hold.quote.currency)}</dd></div>` : ""}</dl><div class="policy-note"><strong>A little certainty for your evening.</strong><p>Your minimum applies toward dining. Extras, tax, and gratuity are settled separately. Unspent minimum is not refunded.</p><p>${state.mode === "demo" ? "Preview cancellation policy: at least 8 hours before, the full minimum returns as dining credit. Later, 50% returns." : "Cancellation refunds return as dining credit under the service’s current policy. Review the exact refund and forfeited amount before cancelling your reservation."}</p></div>${needsCard ? testPaymentFields(payment, expired && !canRetry) : ""}<div class="modal-error"></div><label class="checkbox-line"><input type="checkbox" id="accept-terms" ${expired && !canRetry ? "disabled" : ""}>I have reviewed the minimum and cancellation information.</label><button class="btn primary full" data-action="confirm" ${(expired && !canRetry) || state.busy ? "disabled" : ""}>${canRetry ? "Retry same confirmation" : needsCard ? `Confirm · ${money(payment.card_cents, hold.quote.currency)} test card` : state.mode === "demo" ? "Confirm preview reservation" : "Confirm reservation"} ${icon("arrow")}</button><button class="text-button full" data-action="close">Keep browsing</button><p class="fine-print">Test payments only. No real card is charged. The service calculates your final wallet allocation when you confirm. Closing this review keeps the hold until it expires.</p></div>`,
    "review",
  );
}
async function placeHold(id) {
  if (state.busy) return;
  if (
    state.hold &&
    ((remainingSeconds(state.hold) > 0 && state.hold.status === "active") ||
      state.confirmPending)
  ) {
    reviewDialog();
    return;
  }
  if (state.holdOperation?.uncertain) {
    openDialog(
      `<div class="modal-body"><h2 id="dialog-title">Your table request needs a check.</h2><p>Retry the pending request before choosing another time.</p><div class="modal-error"></div>${button("Retry table request", "retry-hold")}</div>`,
      "hold-error",
    );
    return;
  }
  Object.assign(state, emptyCheckout());
  state.holdOperation = {
    operation: newOperation("/holds", { slot_id: id, party_size: state.party }),
    uncertain: false,
  };
  await submitHold();
}
async function submitHold() {
  if (!state.holdOperation || state.busy) return;
  state.busy = true;
  state.holdOperation.uncertain = true;
  persistCheckout();
  updateAvailability();
  try {
    const hold = await state.client.write(state.holdOperation.operation);
    state.hold = hold;
    state.confirmOperation = null;
    state.confirmPlan = null;
    state.paymentMethod = "";
    state.confirmPending = false;
    state.holdOperation = null;
    persistCheckout();
    state.busy = false;
    render();
    reviewDialog();
  } catch (error) {
    if (await sessionError(error)) return;
    state.busy = false;
    state.holdOperation.uncertain = error.uncertain;
    if (!error.uncertain) state.holdOperation = null;
    persistCheckout();
    updateAvailability();
    openDialog(
      `<div class="modal-body"><p class="eyebrow">A CHANGE OF PLANS</p><h2 id="dialog-title">Let’s find your table.</h2><div class="modal-error">${errorBox(errorMessage(error), error.uncertain ? "retry-hold" : "retry-times", error.uncertain ? "Retry same request" : "Refresh times")}</div></div>`,
      "hold-error",
    );
  }
}
async function confirmReservation() {
  if (state.busy || !state.hold) return;
  if (!dialog.querySelector("#accept-terms")?.checked) {
    modalError(
      new Error("Please review and acknowledge the booking information."),
    );
    return;
  }
  if (!remainingSeconds(state.hold) && !state.confirmOperation) {
    reviewDialog();
    return;
  }
  if (!state.confirmOperation) {
    try {
      state.confirmOperation = confirmationOperation(
        state.hold,
        state.wallet,
        testCardToken(state.paymentMethod),
      );
      state.confirmPlan = spendPlan(
        state.hold.quote.amount_cents,
        state.wallet,
      );
    } catch (error) {
      modalError(error);
      dialog.querySelector("#payment-method")?.focus();
      return;
    }
  }
  state.confirmPending = true;
  persistCheckout();
  state.busy = true;
  const b = dialog.querySelector('[data-action="confirm"]');
  b.disabled = true;
  b.textContent = "Confirming your table…";
  const method = dialog.querySelector("#payment-method");
  if (method) method.disabled = true;
  try {
    const reservation = await state.client.write(state.confirmOperation);
    state.bookings = [
      reservation,
      ...state.bookings.filter((r) => r.id !== reservation.id),
    ];
    state.hold = null;
    state.confirmOperation = null;
    state.confirmPlan = null;
    state.paymentMethod = "";
    state.confirmPending = false;
    persistCheckout();
    state.busy = false;
    resetWalletHistory();
    try {
      state.wallet = await state.client.request("/wallet");
    } catch (error) {
      if (await sessionError(error)) return;
      toast(
        "Reservation confirmed. Refresh your wallet to see the latest balance.",
      );
    }
    state.bookingTab = "upcoming";
    navigate("reservations");
    openDialog(
      `<div class="modal-body confirmation"><div class="success-mark">${icon("check")}</div><p class="eyebrow">${state.mode === "demo" ? "PREVIEW RESERVATION CONFIRMED" : "YOU’RE ON THE LIST"}</p><h2 id="dialog-title">We’ll save you a seat.</h2><p>${e(venueById(reservation.venue_id)?.name || "Your restaurant")}</p><div class="confirmation-details"><strong>${displayDate(reservation.local_starts_at)} · ${localTime(reservation.local_starts_at)}</strong><span>${reservation.party_size} guests · ${money(reservation.min_spend_cents, reservation.currency)} minimum</span></div><p class="fine-print">Reference D${reservation.id} · Test payment. No real card charged.</p>${button("View my reservations " + icon("arrow"), "view-bookings", "primary full")}</div>`,
      "confirmed",
    );
    announce("Reservation confirmed");
  } catch (error) {
    if (await sessionError(error)) return;
    state.busy = false;
    state.confirmPending = error.uncertain;
    modalError(error);
    b.disabled = false;
    b.textContent = error.uncertain
      ? "Retry same confirmation"
      : "Confirm reservation";
    if (
      [
        "payment_failed",
        "payment_timeout",
        "hold_expired",
        "hold_not_active",
      ].includes(error.code)
    ) {
      b.disabled = true;
      state.hold = null;
      state.confirmOperation = null;
      state.confirmPlan = null;
      state.confirmPending = false;
      state.paymentMethod = "";
      persistCheckout();
      render();
      openDialog(
        `<div class="modal-body"><p class="eyebrow">RESERVATION NOT CONFIRMED</p><h2 id="dialog-title">${error.code === "payment_failed" ? "Let’s try another card." : "Your payment needs a check."}</h2><div class="modal-error"><p role="alert">${e(errorMessage(error))}</p></div>${button(error.code === "payment_failed" ? "Choose another time" : "Check my reservations", error.code === "payment_failed" ? "retry-times" : "check-bookings", "primary full")}<p class="fine-print">Test payments only. No real card is charged.</p></div>`,
        "payment-error",
      );
      announce(errorMessage(error));
      return;
    }
    if (["wallet_balance_changed", "card_required"].includes(error.code)) {
      state.confirmOperation = null;
      state.confirmPlan = null;
      dialog
        .querySelector(".modal-error")
        .insertAdjacentHTML(
          "beforeend",
          button("Refresh wallet & review", "refresh-wallet", "secondary full"),
        );
      b.disabled = true;
    }
    persistCheckout();
  }
}
function bookingDetail(id) {
  const r = state.bookings.find((r) => r.id === id);
  if (!r) return;
  const venue = venueById(r.venue_id);
  openDialog(
    `<div class="modal-body"><p class="eyebrow">RESERVATION D${r.id}</p><h2 id="dialog-title">${e(venue?.name || "Your restaurant")}</h2>${badge(reservationLabel(r), isCancelled(r) ? "muted-badge" : "positive")}<div class="review-details"><p>${icon("calendar")}${displayDate(r.local_starts_at)} · ${localTime(r.local_starts_at)}</p><p>${icon("users")}${r.party_size} guests · Venue local time</p></div><dl class="money-lines"><div class="total"><dt>Dining minimum</dt><dd>${money(r.min_spend_cents, r.currency)}</dd></div><div><dt>Dining credit used</dt><dd>${money(r.payment.credit_cents, r.currency)}</dd></div><div><dt>Fun Coupons used</dt><dd>${money(r.payment.coupon_cents, r.currency)}</dd></div><div><dt>Test card</dt><dd>${money(r.payment.card_cents, r.currency)}</dd></div></dl>${cancellationHistory(r)}<p class="fine-print">${state.mode === "demo" ? "This is a sample reservation." : "Your reservation details are supplied by the booking service."}</p>${isUpcoming(r) ? button("Change of plans? Cancel reservation", "cancel-review", "text-button danger", `data-id="${r.id}"`) : ""}</div>`,
    "booking",
  );
}
function renderCancellation(r, preview, pending = false) {
  const refund = preview.refund;
  openDialog(
    `<div class="modal-body"><p class="eyebrow">${state.mode === "demo" ? "PREVIEW CANCELLATION" : "A CHANGE OF PLANS"}</p><h2 id="dialog-title">Cancel your evening?</h2><p>${e(venueById(r.venue_id)?.name || "Your restaurant")} · ${displayDate(r.local_starts_at)} · ${localTime(r.local_starts_at)}</p>${badge(refund.late ? "Late cancellation" : "Early cancellation", refund.late ? "" : "positive")}<dl class="money-lines"><div><dt>Original dining minimum</dt><dd>${money(r.min_spend_cents, r.currency)}</dd></div><div class="total"><dt>Dining credit returned</dt><dd>${money(refund.credit_cents, refund.currency)}</dd></div><div><dt>Amount forfeited</dt><dd>${money(refund.forfeited_cents, refund.currency)}</dd></div></dl><div class="policy-note"><strong>${refund.late ? "The early cancellation deadline has passed." : "Your full minimum returns as dining credit."}</strong><p>Full-refund deadline: ${e(cancellationDeadlineLabel(preview))}. The deadline itself is included.</p><p>${state.mode === "demo" ? "Sample policy." : "Calculated by the booking service."} Credit can be used for a future evening; it is not refunded to your card. Cancelling releases your table.</p></div><div class="modal-error">${pending ? '<p role="status">Your cancellation needs a check. Retrying uses the same request and refund.</p>' : ""}</div>${button(pending ? "Retry same cancellation" : `Cancel ${state.mode === "demo" ? "preview " : ""}reservation · return ${money(refund.credit_cents, refund.currency)}`, "cancel-confirm", "danger-btn full")}${button("Keep my table", "close", "secondary full")}<p class="fine-print">If the policy or timing changes before cancellation, we’ll ask you to review the updated refund.</p></div>`,
    "cancel",
  );
}
async function cancelReview(id) {
  if (state.busy) return;
  const r = state.bookings.find((r) => r.id === id);
  if (!r) return;
  if (state.cancelOperation?.id === id && state.cancelOperation.uncertain) {
    renderCancellation(r, state.cancelOperation.preview, true);
    return;
  }
  const epoch = ++cancelReviewEpoch;
  state.cancelOperation = null;
  state.cancelPreview = null;
  openDialog(
    `<div class="modal-body"><h2 id="dialog-title">Reviewing your refund…</h2><p role="status">Checking the current cancellation policy.</p>${button("Keep my table", "close", "secondary full")}</div>`,
    "cancel",
  );
  const startedAt = performance.now();
  try {
    const preview = await state.client.request(
      `/reservations/${id}/cancellation-preview`,
    );
    if (epoch !== cancelReviewEpoch || modalType !== "cancel") return;
    if (preview.reservation_id !== id)
      throw new Error("The refund could not be verified. Please refresh it.");
    cancellationOperation(preview); // Validate the complete money response before enabling cancellation.
    state.cancelPreview = preview;
    state.cancelPreviewUntil = cancellationPreviewDeadline(preview, startedAt);
    renderCancellation(r, preview);
  } catch (error) {
    if (epoch !== cancelReviewEpoch || modalType !== "cancel") return;
    if (await sessionError(error)) return;
    openDialog(
      `<div class="modal-body"><h2 id="dialog-title">We couldn’t confirm your refund.</h2><p role="alert">${e(errorMessage(error))}</p>${button("Refresh refund", "cancel-review", "secondary full", `data-id="${id}"`)}${button("Back to reservations", "view-bookings", "text-button full")}</div>`,
      "cancel",
    );
  }
}
async function cancelConfirm() {
  if (state.busy || (!state.cancelPreview && !state.cancelOperation)) return;
  if (!state.cancelOperation) {
    if (performance.now() >= state.cancelPreviewUntil) {
      await cancelReview(state.cancelPreview.reservation_id);
      modalError(
        new Error("Please review the current refund before cancelling."),
      );
      return;
    }
    state.cancelOperation = {
      id: state.cancelPreview.reservation_id,
      operation: cancellationOperation(state.cancelPreview),
      preview: state.cancelPreview,
      uncertain: true,
    };
  }
  state.busy = true;
  const b = dialog.querySelector('[data-action="cancel-confirm"]');
  b.disabled = true;
  b.textContent = "Cancelling…";
  try {
    const result = await state.client.write(state.cancelOperation.operation);
    state.bookings = state.bookings.map((r) =>
      r.id === result.reservation.id
        ? { ...result.reservation, refund: result.refund }
        : r,
    );
    state.cancelOperation = null;
    state.cancelPreview = null;
    state.busy = false;
    resetWalletHistory();
    try {
      state.wallet = await state.client.request("/wallet");
    } catch (error) {
      if (await sessionError(error)) return;
      toast(
        "Reservation cancelled. Refresh your wallet for the latest balance.",
      );
    }
    render();
    openDialog(
      `<div class="modal-body confirmation"><div class="success-mark">${icon("check")}</div><h2 id="dialog-title">Until next time.</h2><p>Your reservation has been cancelled.</p><div class="confirmation-details"><strong>${money(result.refund.credit_cents, result.refund.currency)} dining credit returned</strong><span>${money(result.refund.forfeited_cents, result.refund.currency)} forfeited</span></div>${button("Back to reservations", "view-bookings", "primary full")}</div>`,
      "cancelled",
    );
  } catch (error) {
    if (await sessionError(error)) return;
    state.busy = false;
    if (error.code === "cancellation_preview_changed") {
      const id = state.cancelOperation.id;
      state.cancelOperation = null;
      await cancelReview(id);
      modalError(error);
      return;
    }
    state.cancelOperation.uncertain = error.uncertain;
    modalError(error);
    b.disabled = !error.uncertain;
    b.textContent = error.uncertain
      ? "Retry same cancellation"
      : "Cancel reservation";
  }
}

function opsFilters() {
  return `<div class="ops-toolbar"><label class="inline-search">${icon("search")}<input type="search" id="ops-query" placeholder="Search guests or restaurants" aria-label="Search service" value="${e(state.opsQuery)}"></label><label class="compact-select"><span class="sr-only">Filter restaurant</span><select id="ops-venue"><option value="all">All restaurants</option>${demoVenues.map((v) => `<option value="${v.id}" ${state.opsVenue === String(v.id) ? "selected" : ""}>${e(v.name)}</option>`).join("")}</select></label><label class="compact-select">${icon("calendar")}<input type="date" id="ops-date" required aria-label="Service date" value="${state.opsDate}"></label></div>`;
}
function filteredGuests() {
  return ops.data.guests
    .filter(
      (g) =>
        (state.opsVenue === "all" || String(g.venue) === state.opsVenue) &&
        g.date === state.opsDate &&
        (state.opsFilter === "all" || g.status === state.opsFilter) &&
        `${g.name} ${previewVenue(g.venue)?.name || ""}`
          .toLowerCase()
          .includes(state.opsQuery.toLowerCase()),
    )
    .sort((a, b) => a.time.localeCompare(b.time));
}
function serviceTable(guests) {
  return `<div class="table-scroll"><table class="service-table"><thead><tr><th>Guest</th><th>Time & party</th><th>Restaurant</th><th>Table</th><th>Minimum</th><th>Status</th><th><span class="sr-only">Details</span></th></tr></thead><tbody>${guests.map((g) => `<tr><td><button class="guest-cell" data-action="guest" data-id="${g.id}"><span class="guest-avatar">${g.initials}</span><span><strong>${e(g.name)}</strong><small>${e(g.tag)}</small></span></button></td><td><strong>${localTime(`2026-01-01T${g.time}:00`)}</strong><small>${g.party} guests</small></td><td>${e(previewVenue(g.venue)?.name)}</td><td>${e(g.table)}</td><td>${money(g.minimum)}</td><td>${badge(g.status, "status-" + g.status)}</td><td><button class="icon-button" data-action="guest" data-id="${g.id}" aria-label="Open ${e(g.name)}">${icon("arrow")}</button></td></tr>`).join("")}</tbody></table></div>${!guests.length ? empty("Nothing on this view.", "Try a different date, restaurant, status, or guest name.", "ops-reset", "Reset service filters") : ""}`;
}
function renderOperations() {
  const section = route().split("/")[1] || "reservations";
  const guests = filteredGuests();
  const today = ops.data.guests.filter(
    (g) =>
      g.date === state.opsDate &&
      (state.opsVenue === "all" || String(g.venue) === state.opsVenue),
  );
  const open = ops.data.exceptions.filter((x) => x.status === "open");
  const titles = {
    reservations: [
      "EVERY DETAIL, CONSIDERED",
      "An exceptional evening starts here.",
      "Your service overview. Thoughtful hospitality, one guest at a time.",
    ],
    availability: [
      "MAKE ROOM FOR A GREAT EVENING",
      "The right table, at the right time.",
      "Manage table capacity and pause availability in this local preview.",
    ],
    guests: [
      "KNOW THE PERSON, NOT JUST THE BOOKING",
      "Make every welcome personal.",
      "Service notes and guest preferences, where your team needs them.",
    ],
    exceptions: [
      "A LITTLE ATTENTION GOES A LONG WAY",
      "The details that make the difference.",
      "Resolve service exceptions and leave a clear handoff.",
    ],
  };
  const title = titles[section] || titles.reservations;
  let content = "";
  if (section === "availability") {
    const rows = ops.data.inventory.filter(
      (s) =>
        (state.opsVenue === "all" || String(s.venue) === state.opsVenue) &&
        s.date === state.opsDate &&
        previewVenue(s.venue)
          .name.toLowerCase()
          .includes(state.opsQuery.toLowerCase()),
    );
    content = `${opsFilters()}<div class="capacity-grid">${rows.map((s) => `<article class="capacity-card"><div class="row-between"><p class="eyebrow">${e(previewVenue(s.venue).name)}</p>${badge(s.paused ? "Paused" : "Open", s.paused ? "warning" : "positive")}</div><h3>${timeLabel(s.hour)}</h3><div class="capacity-number">${s.paused ? 0 : Math.max(0, s.capacity - s.booked - s.held)}<span>tables available</span></div><div class="capacity-bar" aria-hidden="true"><span style="width:${(s.booked / s.capacity) * 100}%"></span><i style="width:${(s.held / s.capacity) * 100}%"></i></div><div class="capacity-legend"><span>${s.booked} booked</span><span>${s.held} held</span><span>${s.capacity} capacity</span></div>${button("Manage availability " + icon("edit"), "capacity", "secondary full", `data-id="${s.id}"`)}</article>`).join("")}</div>${rows.length ? "" : empty("No availability on this view.", "Try the sample service date or another restaurant.", "ops-reset", "Reset filters")}`;
  } else if (section === "guests") {
    content = `${opsFilters()}<div class="guest-grid">${guests.map((g) => `<article class="guest-profile-card"><span class="guest-avatar">${g.initials}</span><div><h3>${e(g.name)}</h3><p>${g.visits} visits · ${e(previewVenue(g.venue).name)}</p>${badge(g.tag)}</div><p class="guest-note">${e(g.notes)}</p>${button("View guest " + icon("arrow"), "guest", "text-button", `data-id="${g.id}"`)}</article>`).join("")}</div>${guests.length ? "" : empty("No guests found.", "Adjust the service filters to find a guest.", "ops-reset", "Reset filters")}`;
  } else if (section === "exceptions") {
    content = `<div class="exception-heading"><p>${open.length} open ${open.length === 1 ? "exception" : "exceptions"}</p><span class="fine-print">Sample service · ${displayDate(ops.data.date)}</span></div><div class="exception-list">${ops.data.exceptions
      .map((x) => {
        const g = ops.data.guests.find((g) => g.id === x.guest);
        return `<article class="exception-card ${x.status === "resolved" ? "resolved" : ""}"><span class="exception-icon">${icon(x.status === "resolved" ? "check" : "alert")}</span><div><div class="row-between"><h3>${e(x.title)}</h3>${badge(x.status === "resolved" ? "Resolved" : x.priority, x.status === "resolved" ? "positive" : x.priority === "High" ? "warning" : "")}</div><p>${e(g.name)} · ${e(previewVenue(g.venue).name)} · ${localTime(`2026-01-01T${g.time}:00`)}</p><p class="exception-detail">${e(x.detail)}</p>${x.status === "resolved" ? `<p class="resolution"><strong>Resolution:</strong> ${e(x.resolution)}</p>` : button("Resolve exception " + icon("arrow"), "resolve", "secondary small", `data-id="${x.id}"`)}</div></article>`;
      })
      .join("")}</div>${
      ops.data.audit.length
        ? `<section class="audit-log"><h2>Recent service activity</h2>${ops.data.audit
            .slice(0, 6)
            .map(
              (a) =>
                `<p>${icon("check")}<span>${e(a.action)}</span><time>${new Date(a.at).toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" })}</time></p>`,
            )
            .join("")}</section>`
        : ""
    }`;
  } else {
    content = `<div class="stat-grid"><article><span>Reservations</span><strong>${today.filter((g) => g.status !== "cancelled").length}</strong><small>Across your selected service</small></article><article><span>Expected covers</span><strong>${today.filter((g) => g.status !== "cancelled").reduce((n, g) => n + g.party, 0)}</strong><small>A welcome for every guest</small></article><article><span>Arrived & seated</span><strong>${today.filter((g) => ["arrived", "seated"].includes(g.status)).length}</strong><small>Currently with your team</small></article><article class="attention-stat"><span>Needs attention ${icon("alert")}</span><strong>${open.length}</strong><a href="#operations/exceptions">Review service exceptions ${icon("arrow")}</a></article></div><section class="service-panel"><div class="service-panel-title"><h2>Service reservations</h2><span>${displayDate(state.opsDate)}</span></div>${opsFilters()}<div class="status-filters">${[
      ["all", "All reservations"],
      ["confirmed", "Expected"],
      ["arrived", "Arrived"],
      ["seated", "Seated"],
      ["completed", "Completed"],
      ["cancelled", "Cancelled"],
    ]
      .map(
        ([key, label]) =>
          `<button class="chip ${state.opsFilter === key ? "active" : ""}" data-action="ops-status" data-value="${key}" aria-pressed="${state.opsFilter === key}">${label}</button>`,
      )
      .join(
        "",
      )}</div>${serviceTable(guests)}<div class="table-foot"><span>${guests.length} reservations shown</span><span>Times are local to the restaurant</span></div></section>`;
  }
  shell(
    `<div class="ops-layout"><aside class="ops-sidebar"><p class="eyebrow">THE EVENING, AT A GLANCE</p><nav aria-label="Operations navigation">${[
      ["reservations", "Reservations", "calendar"],
      ["availability", "Availability", "grid"],
      ["guests", "Guest book", "users"],
      ["exceptions", "Service exceptions", "alert"],
    ]
      .map(
        ([key, label, symbol]) =>
          `<a href="#operations/${key}" ${section === key ? 'aria-current="page"' : ""}>${icon(symbol)}<span>${label}</span>${key === "exceptions" ? `<b>${open.length}</b>` : ""}</a>`,
      )
      .join(
        "",
      )}</nav><div class="workspace-note">${icon("spark")}<strong>Hospitality is in the details.</strong><p>This workspace previews staff workflows. It is separate from connected member accounts.</p></div><a class="member-return" href="#discover">${icon("back")} Member experience</a></aside><main class="ops-main" id="main" tabindex="-1">${sectionHeading(...title)}${content}<p class="ops-footnote">LOCAL PREVIEW · Fictional guests and service notes. No staff API is connected.</p></main></div>`,
    true,
  );
}
function guestDialog(id) {
  const g = ops.data.guests.find((g) => g.id === id);
  if (!g) return;
  const next = {
    confirmed: ["arrived", "Mark arrived"],
    arrived: ["seated", "Seat guests"],
    seated: ["completed", "Complete visit"],
  }[g.status];
  openDialog(
    `<div class="modal-body"><p class="eyebrow">GUEST DETAILS · SAMPLE SERVICE</p><div class="guest-dialog-heading"><span class="guest-avatar">${g.initials}</span><div><h2 id="dialog-title">${e(g.name)}</h2><p>${g.visits} visits · ${e(g.tag)}</p></div></div>${badge(g.status, "status-" + g.status)}<div class="review-details"><p>${icon("calendar")}${e(previewVenue(g.venue).name)} · ${localTime(`2026-01-01T${g.time}:00`)}</p><p>${icon("users")}${g.party} guests · ${money(g.minimum)} minimum</p></div><form class="stack-form" id="guest-form" data-id="${id}"><label>Table assignment<input name="table" maxlength="30" value="${e(g.table)}"></label><label>Notes for the service team<textarea name="notes" maxlength="1000" rows="4">${e(g.notes)}</textarea></label><div class="modal-error"></div><button class="btn secondary full" type="submit">Save service notes ${icon("check")}</button></form>${next ? button(next[1] + " " + icon("arrow"), "guest-status", "primary full", `data-id="${id}" data-value="${next[0]}"`) : ""}<p class="fine-print">Changes are saved to the local operations preview only.</p></div>`,
    "guest",
  );
}
function capacityDialog(id) {
  const s = ops.data.inventory.find((s) => s.id === id);
  if (!s) return;
  openDialog(
    `<div class="modal-body"><p class="eyebrow">LOCAL AVAILABILITY PREVIEW</p><h2 id="dialog-title">Make room for the evening.</h2><p>${e(previewVenue(s.venue).name)} · ${timeLabel(s.hour)}</p><form id="capacity-form" data-id="${id}" class="stack-form"><label>Total table capacity<input name="capacity" type="number" min="${s.booked + s.held}" max="50" step="1" required value="${s.capacity}"></label><p class="fine-print">${s.booked} tables are booked and ${s.held} held. Capacity cannot be lower than ${s.booked + s.held}.</p><label class="checkbox-line"><input type="checkbox" name="paused" ${s.paused ? "checked" : ""}>Pause new reservations for this time</label><div class="modal-error"></div><button class="btn primary full" type="submit">Save availability ${icon("check")}</button></form></div>`,
    "capacity",
  );
}
function resolveDialog(id) {
  const x = ops.data.exceptions.find((x) => x.id === id);
  if (!x) return;
  openDialog(
    `<div class="modal-body"><p class="eyebrow">SERVICE FOLLOW-THROUGH</p><h2 id="dialog-title">${e(x.title)}</h2><p>${e(x.detail)}</p><form id="resolve-form" data-id="${id}" class="stack-form"><label>How was this resolved?<textarea name="resolution" rows="4" minlength="5" maxlength="1000" required placeholder="Leave a clear handoff for the next team member."></textarea></label><div class="modal-error"></div><button class="btn primary full" type="submit">Mark resolved ${icon("check")}</button></form></div>`,
    "resolve",
  );
}
function storageMessage() {
  return ops.persistent
    ? "Saved to this browser’s operations preview."
    : "Saved for this visit. Browser storage is unavailable.";
}

async function handleAction(event) {
  const target = event.target.closest("[data-action]");
  if (!target || target.disabled) return;
  const action = target.dataset.action;
  const id = Number(target.dataset.id);
  const value = target.dataset.value;
  switch (action) {
    case "discover":
      navigate("discover");
      break;
    case "account":
      navigate("membership");
      break;
    case "login":
      loginDialog();
      break;
    case "logout":
      if (!state.busy) await signOut();
      break;
    case "close":
      closeDialog();
      break;
    case "reload":
      await loadData();
      break;
    case "save": {
      const v = venueById(id);
      if (!v) return;
      state.saved.has(v.slug)
        ? state.saved.delete(v.slug)
        : state.saved.add(v.slug);
      const saved = state.saved.has(v.slug);
      const persisted = safeSave(storage, "dorsia-saved-v1", [...state.saved]);
      render();
      toast(
        saved
          ? `${v.name} added to your collection${persisted ? "." : " for this visit."}`
          : `${v.name} removed from your collection.`,
      );
      break;
    }
    case "cuisine":
      state.cuisine = value;
      render();
      break;
    case "reset-filters":
      state.query = "";
      state.cuisine = "All tables";
      state.city = String(state.cities[0]?.id || "");
      navigate("discover");
      render();
      break;
    case "availability-retry":
      await loadAvailability();
      break;
    case "next-date":
      state.date = addDays(state.date, 1);
      render();
      await loadAvailability();
      break;
    case "hold":
      await placeHold(id);
      break;
    case "retry-hold":
      if (!state.busy) await submitHold();
      break;
    case "retry-times":
      closeDialog();
      await loadAvailability();
      break;
    case "resume-hold":
      reviewDialog();
      break;
    case "confirm":
      await confirmReservation();
      break;
    case "view-bookings": {
      const destination =
        modalType === "cancelled" ? "reservations/past" : "reservations";
      closeDialog();
      navigate(destination);
      break;
    }
    case "check-bookings":
      closeDialog();
      state.hold = null;
      state.confirmOperation = null;
      state.confirmPlan = null;
      state.confirmPending = false;
      state.paymentMethod = "";
      persistCheckout();
      navigate("reservations");
      await loadData();
      break;
    case "refresh-wallet":
      try {
        state.wallet = await state.client.request("/wallet");
        reviewDialog();
      } catch (error) {
        if (await sessionError(error)) return;
        modalError(error);
      }
      break;
    case "wallet-more":
      await loadWalletHistory(true);
      break;
    case "wallet-retry":
      await loadWalletHistory();
      break;
    case "booking-tab":
      navigate(value === "past" ? "reservations/past" : "reservations");
      break;
    case "booking-detail":
      bookingDetail(id);
      break;
    case "cancel-review":
      await cancelReview(id);
      break;
    case "cancel-confirm":
      await cancelConfirm();
      break;
    case "ops-status":
      state.opsFilter = value;
      render();
      break;
    case "ops-reset":
      state.opsQuery = "";
      state.opsVenue = "all";
      state.opsFilter = "all";
      state.opsDate = ops.data.date;
      render();
      break;
    case "guest":
      guestDialog(id);
      break;
    case "guest-status":
      try {
        ops.transition(id, value);
        render();
        guestDialog(id);
        toast(storageMessage());
      } catch (error) {
        modalError(error);
      }
      break;
    case "capacity":
      capacityDialog(target.dataset.id);
      break;
    case "resolve":
      resolveDialog(id);
      break;
  }
}
async function submitForm(event) {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  event.preventDefault();
  const data = new FormData(form);
  if (form.id === "search-form") {
    state.date = String(data.get("date"));
    state.party = Number(data.get("party"));
    if (data.has("city")) state.city = String(data.get("city"));
    state.cuisine = "All tables";
    render();
    if (activePage() === "venue") await loadAvailability();
    else {
      document
        .querySelector(".collection")
        ?.scrollIntoView({ behavior: "smooth", block: "start" });
      announce(
        `Showing restaurants for ${displayDate(state.date)}, ${state.party} guests.`,
      );
    }
    return;
  }
  if (form.id === "login-form") {
    if (state.busy) return;
    state.busy = true;
    const b = form.querySelector("button");
    b.disabled = true;
    b.textContent = "Connecting…";
    try {
      const member = await api.login(
        String(data.get("email")),
        String(data.get("password")),
      );
      session.remember(api.token, member);
      state.busy = false;
      closeDialog();
      Object.assign(state, {
        mode: "live",
        client: api,
        member,
        hold: null,
        holdOperation: null,
        confirmOperation: null,
        confirmPlan: null,
        paymentMethod: "",
        confirmPending: false,
        cancelOperation: null,
        cancelPreview: null,
      });
      await loadData();
      toast(
        session.persistent
          ? "Your account is connected. Refreshing this tab keeps you signed in."
          : "Connected for this visit. Browser storage is unavailable, so refreshing will sign you out.",
      );
    } catch (error) {
      state.busy = false;
      modalError(error);
      b.disabled = false;
      b.innerHTML = "Sign in " + icon("arrow");
    }
    return;
  }
  try {
    if (form.id === "guest-form") {
      ops.updateGuest(Number(form.dataset.id), {
        notes: data.get("notes"),
        table: data.get("table"),
      });
      closeDialog();
      render();
      toast(storageMessage());
    }
    if (form.id === "capacity-form") {
      ops.updateCapacity(
        form.dataset.id,
        Number(data.get("capacity")),
        data.has("paused"),
      );
      closeDialog();
      render();
      toast(storageMessage());
    }
    if (form.id === "resolve-form") {
      ops.resolve(Number(form.dataset.id), String(data.get("resolution")));
      closeDialog();
      render();
      toast(storageMessage());
    }
  } catch (error) {
    modalError(error);
  }
}
app.addEventListener("click", handleAction);
dialog.addEventListener("click", handleAction);
app.addEventListener("submit", submitForm);
dialog.addEventListener("submit", submitForm);
dialog.addEventListener("change", (event) => {
  if (
    event.target.id === "payment-method" &&
    !state.confirmOperation &&
    !state.busy
  ) {
    state.paymentMethod = event.target.value;
    persistCheckout();
  }
});
dialog.addEventListener("cancel", (event) => {
  event.preventDefault();
  closeDialog();
});
app.addEventListener("change", (event) => {
  const el = event.target;
  if (el.closest("#search-form") && activePage() === "venue") {
    if (!document.querySelector("[name=date]").value) {
      render();
      return;
    }
    state.date = document.querySelector("[name=date]").value;
    state.party = Number(document.querySelector("[name=party]").value);
    if (state.date) {
      render();
      loadAvailability();
    }
    return;
  }
  if (el.id === "ops-venue") state.opsVenue = el.value;
  else if (el.id === "ops-date") {
    if (el.value) state.opsDate = el.value;
  } else return;
  render();
});
app.addEventListener("input", (event) => {
  const el = event.target;
  if (!["venue-query", "ops-query"].includes(el.id)) return;
  const id = el.id,
    position = el.selectionStart,
    value = el.value;
  if (id === "venue-query") state.query = value;
  else state.opsQuery = value;
  render();
  const replacement = document.getElementById(id);
  replacement?.focus();
  try {
    replacement?.setSelectionRange(position, position);
  } catch {}
});
window.addEventListener("hashchange", () => {
  availabilityEpoch++;
  state.slots = [];
  state.slotsError = null;
  state.slotsLoading = activePage() === "venue";
  render();
  window.scrollTo(0, 0);
  document.querySelector("#main")?.focus({ preventScroll: true });
  if (activePage() === "venue" && !state.loading) loadAvailability();
  if (
    route() === "membership/wallet" &&
    !state.loading &&
    !state.walletHistoryLoaded
  )
    loadWalletHistory();
});
setInterval(() => {
  if (
    modalType === "cancel" &&
    state.cancelPreview &&
    !state.cancelOperation &&
    performance.now() >= state.cancelPreviewUntil
  ) {
    const refresh = dialog.querySelector('[data-action="cancel-confirm"]');
    if (refresh) refresh.textContent = "Refresh refund before cancelling";
  }
  if (!state.hold) return;
  document
    .querySelectorAll(".hold-timer")
    .forEach(
      (el) => (el.textContent = state.confirmPending ? "" : countdown()),
    );
  if (!remainingSeconds(state.hold)) {
    const button = dialog.querySelector('[data-action="confirm"]');
    if (button && !state.confirmOperation) {
      button.disabled = true;
      button.textContent = "Hold expired — choose another time";
    }
    document.querySelector(".hold-status")?.classList.add("expired");
    if (!state.confirmPending) document.querySelector(".active-hold")?.remove();
  }
}, 1000);
loadData();

document.querySelector(".skip-link").addEventListener("click", (event) => {
  event.preventDefault();
  document.querySelector("#main")?.focus();
});
