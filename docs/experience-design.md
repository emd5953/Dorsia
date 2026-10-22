# Hospitality experience — design and integration

## Starting point

The checkout was a Laravel API-only rebuild. There were no member screens, Expo project, web dashboard, staff guard, membership resources, or staff endpoints. Existing capabilities support member authentication, city/venue discovery, availability, quoted holds, confirmation, cancellation, and dining wallets. The approved ticket 09 adds a responsive web surface in `public/experience/` with the additive cancellation-preview contract listed in ticket 09.

## Public references and confidence

- [Dorsia website](https://www.dorsia.com/), viewed October 22, 2026: warm white canvas, restrained black typography, widely tracked wordmark, spare navigation.
- [Dorsia Members Club App Store screenshots](https://apps.apple.com/us/app/dorsia-members-club/id1589267208), viewed October 22, 2026: image-led discovery, compact location/date/guest controls, venue detail with booking navigation, black mobile chrome, and purple review action. These are public promotional screenshots, not access to the authenticated app.
- [Dorsia New York directory](https://www.dorsia.com/restaurants/new-york): verified restaurant imagery and names used in this local design study.
- [Dorsia FAQ](https://www.dorsia.com/faq): membership and reservation minimum spend are separate concepts. The UI links to current membership information instead of duplicating prices or claiming a user's tier.

The staff workspace is an original proposal. No internal Dorsia dashboard was available or reproduced. Guest names, service notes, statuses, capacity counts, availability, minimums in demo mode, and operational transitions are fictional examples. Editorial descriptions are original design copy, not verified venue service promises. The demo's 8-hour/50% cancellation rule mirrors this repository's defaults; connected mode does not assume those configurable values.

## Visual system

| Element | Decision |
| --- | --- |
| Canvas | Warm ivory `#f7f6f2`, near-white panels `#fffefa` |
| Text | Near-black `#211e23`, secondary text `#6c6969` |
| Primary action | Muted purple `#5939a5`, hover `#452782` |
| Staff surfaces | Warm gray/taupe; amber exceptions; green completed/arrival indicators |
| Type | DM Sans with system fallback; Georgia italic for the discovery headline accent |
| Hierarchy | Small tracked section labels, large editorial headings, compact functional text |
| Spacing | 8px-derived spacing with 20–36px panel insets and generous section separation |
| Shape | 7–12px functional corners; small pills for filters and statuses |
| Photography | Local optimized JPEGs; fixed-aspect cards; subtle dark hero overlay |
| Mobile | Single-column cards, persistent member navigation, native date controls, bottom-sheet dialogs |
| Staff mobile | Navigation strip and reservation cards that retain guest, time, table and status |

Keyboard focus is visible. Dialogs use native modal behavior, heading focus, Escape dismissal and focus restoration. Inputs have labels, icon buttons have accessible names, loading/status/error regions announce updates, and reduced-motion preference disables animation. This is a practical accessibility pass, not a certified WCAG audit.

## Journeys and data

| Surface | Real integration | Preview behavior |
| --- | --- | --- |
| Discovery | `GET /api/cities`, `/api/venues` | Six New York venues; other sample cities exercise the empty state |
| Venue | `GET /api/venues/{id}/availability` | Local dates and party bands; sold-out slots omitted |
| Review | `POST /api/holds` | Five-minute local hold, integer-cent quote |
| Confirmation | `POST /api/reservations` | Sample card remainder; no network charge |
| Bookings | `GET /api/reservations` includes posted refund history; cancellation-preview GET, guarded cancellation POST | Persistent demo bookings and stored dining-credit receipts |
| Wallet | `GET /api/wallet` and paginated `GET /api/wallet/transactions` | Persistent balances and activity from saved booking/refund receipts |
| Membership | Member profile plus wallet; external FAQ | Explicit preview identity; no fabricated tier/renewal |
| Operations | None; staff API is deferred | Local guest notes, service states, capacity edits, pause, exception resolution, audit history |

The API adapter uses same-origin `/api`, JSON envelopes and bearer auth. The bearer token and member ID are saved in tab-scoped sessionStorage, so refreshing preserves sign-in. GET /me revalidates identity before loading connected data; logout or a 401 clears saved credentials and checkout. Passwords are never saved. Closing the tab normally ends this browser session; no cross-device session recovery is claimed. Failed live requests never switch to demo data. POST operation objects freeze the exact serialized body and UUID; retries reuse both. An uncertain confirmation remains reachable after the local timer expires. Server responses remain authoritative.

Connected confirmation supports both wallet-covered and test-card-funded totals using the existing fake gateway, explicitly approved by the user. When a remainder exists, a labeled test-card selector offers approved, declined, insufficient-funds and timeout scenarios. No PAN, expiry, CVC or real card data is collected. The confirmation button shows the test-card amount; the connected banner and confirmation identify test payments. Membership tier and staff permissions remain unavailable, so the UI states those limits. Cancellation preview now supplies the backend’s exact refund and current policy deadline.

The selected test token is frozen with the hold/body/idempotency key. Unknown outcomes lock the selection and reuse that operation. Declines release the hold and offer a fresh time/payment choice; timeouts direct the member to check reservations. A definitive wallet-change/card-required response requires a refreshed review and a new request key. Successful detail views show the backend's actual wallet/card allocation. Cancellation returns the backend-calculated amount as dining credit, including eligible test-card spend. The review fetches the exact credit returned and amount forfeited, shows early/late classification and the venue-local deadline, and explains that no card refund is issued. A shared backend calculation keeps preview and ledger posting aligned. Expected refund fields are rechecked under the cancellation lock; any change triggers a fresh review before a new cancellation request. Server timestamps drive preview expiry independently of the device clock. Uncertain retries preserve the original reviewed body and key.

Cancelled cards and details display the posted credit returned and forfeiture, supplied by reservation list/detail reads. History uses ledger results and stored cancellation status, so later policy changes do not rewrite it. The selected history tab lives at `#reservations/past` and remains visible after refresh. Demo records persist receipts and recover older saved operation receipts; absent data is labeled unavailable.

Membership links to a dedicated wallet history at `#membership/wallet`. Each transaction shows its reservation/venue, local date, signed payment or refund total and signed wallet change. Booking rows break out dining credit, Fun Coupons and test-card spend; cancellation rows show posted credit and forfeiture. This makes a card-only payment’s $0 wallet movement explicit. The backend reads member-owned ledger records in 20-entry pages, independent of current cancellation policy. Full refresh keeps the wallet route and re-fetches the same records after identity validation. Sign-out/account changes invalidate pending responses. Loading, empty, retry and older-entry controls belong to the history panel. Sample history remains labeled separately; there is no invented running balance.

Request keys and a known hold reference survive reload in the same tab. The app re-fetches the hold, quote, status and original deadline; it never renews a hold on refresh. Pending hold writes can be explicitly retried with their original body/key. Completed confirmations are reconciled from the reservations list; active pending confirmations retain their exact token/body/key. Reload itself performs reads only. Expired, released or missing holds are discarded with an explanation. An outage preserves saved recovery data and shows a retry state. Recovering a hold from another device or after browser storage is cleared still needs a member-scoped discovery endpoint. Demo records and saved venues persist locally; operations has separate storage and never uses the member token. Local staff availability is illustrative and intentionally separate from customer demo inventory.

## Architecture

Dependency-free ES modules and HTML/CSS. The Laravel web server can serve `/experience/index.html` directly from its existing public directory. Hash navigation needs no new Laravel route. A loopback-only Node preview server lives in `tests/Frontend/serve.mjs`; it optionally proxies to a validated local API origin. No Composer, root package, Shared or migration changes. The cancellation and wallet-history follow-ups add narrowly scoped Reservations/Wallet actions and a backward-compatible public API contract; ticket 09 lists the exact backend files.

## Image provenance

The following public directory assets were resized to at most 1600×1200 and encoded as JPEG for this local study. Rights remain with the original owners; distribution rights were not assessed.

| Asset | Source |
| --- | --- |
| Carbone | [Public photograph](https://res.cloudinary.com/dnvxlpyww/image/upload/v1694792473/5a83a9a2-ff8f-43ac-8d41-07128039ec57.jpg) |
| The Grill | [Public photograph](https://res.cloudinary.com/dnvxlpyww/image/upload/v1694792897/276998d3-6c95-4a61-b232-deb9badccfba.jpg) |
| Torrisi | [Public photograph](https://res.cloudinary.com/dnvxlpyww/image/upload/v1730324800/a789baa8-6b2a-4b48-ad0b-54f611f3dce1.jpg) |
| COTE | [Public photograph](https://res.cloudinary.com/dnvxlpyww/image/upload/v1694792459/8629e187-c7a5-4082-a2be-322322a47a71.jpg) |
| 53 | [Public photograph](https://res.cloudinary.com/dnvxlpyww/image/upload/v1695049339/1e872d3c-b8f0-4755-8c1f-aa3e7c268db2.jpg) |
| Chinese Tuxedo | [Public photograph](https://res.cloudinary.com/dnvxlpyww/image/upload/v1719510649/68732902-871a-4a4a-bafa-e8298e29e577.jpg) |

DM Sans loads from Google Fonts with local system fallbacks. Unknown connected venues receive a neutral named image placeholder rather than another restaurant's photograph.
