# Hospitality experience — implementation report

## Architect questions and proposed answers

**Wallet history decision (2026-10-22):** Add `GET /api/wallet/transactions` with a fixed 20-entry page and exclusive `before_id` cursor, newest posted transaction ID first. Return member-scoped booking payments, cancellation postings and credit additions from immutable ledger entries. Distinguish total payment from wallet change and card allocation; no current-policy recomputation or running-balance claim. Cancellation paths with no ledger posting create no synthetic transaction. No schema/backfill needed. Scope is listed in ticket 09.

**History decision (2026-10-22):** Read actual posted member credit through a new Wallet public batch action and add nullable `refund` to reservation JSON. For confirmed reservations it is null. Cancelled reservations return posted credit (zero when no credit moved under C§5.3), original minimum minus that credit, currency and the stored early/late classification. Never recompute historical refunds from current config. No schema/backfill or client-only live receipt cache is needed; old cancelled bookings are supported from their ledger records. Ticket 09 lists the exact added files.

1. **Cancellation preview decision for the requested follow-up:** Add an authenticated, member-scoped read endpoint using the same backend calculation. Preserve existing cancellation clients; the member experience sends optional expected refund fields that are checked again under the reservation lock. A changed amount/classification returns 409 without cancellation and requires another review. This conservatively preserves the current policy, rounding, ledger and idempotency semantics; the preview is not a price lock. Exact added files are listed in ticket 09.
2. **Recover holds outside this tab?** The API can fetch a known hold but cannot discover every active hold for a member. The requested refresh recovery now saves a tab-scoped reference and revalidates it through the existing API. Cross-device or cleared-storage recovery still needs a future member-scoped discovery endpoint. Authentication uses the existing bearer contract; secure HttpOnly cookie sessions and server-side logout/revocation would need a separate backend contract. No endpoint was invented.
3. **When to connect staff, membership and real card collection?** Keep these dependent on their planned contracts, staff guard/audit model, and secure provider tokenization. The user approved the existing fake gateway for this card checkout update. A future real provider also needs a contract for payment authentication and the maximum authorized amount when wallet balances change.

The cancellation follow-up adds the scoped Reservations/Wallet public API contract listed in ticket 09. Existing cancellation callers remain compatible; no Shared, schema, or payment-provider changes were made.

## Result

Implemented ticket 09 under `public/experience/**`, `tests/Frontend/**`, its design/report documents, and the explicitly listed cancellation and wallet-history backend files. Added:

- Image-led discovery with city/date/party controls, cuisine/search filters, saved venues, and recovery from empty results.
- Venue details and live availability adapter; exact quote review, timed holds, wallet allocation, confirmation, booking details, and cancellation.
- Member profile, wallet and membership explanation without invented account tier or renewal data.
- An explicitly labeled local operations workspace: chronological reservations, filters, guest notes/table assignment, arrival/seating/completion transitions, availability capacity/pause, exception resolution and local activity history.
- Responsive layouts, visible focus, native modal dialogs, status/error messages, reduced motion, local optimized imagery and image placeholders.
- Dependency-free ES modules, Node preview server, 47 frontend unit checks and six opt-in live integration checks (wallet, card, refresh recovery, exact cancellation, refund history, wallet transaction history).
- User-approved test-card checkout for the remainder after coupons and credit, plus card-only bookings. Clearly labeled test-card choices use the existing approved/declined/insufficient-funds/timeout tokens; no real card data is collected.

The Docker follow-up is complete. Installed the signed official Apple Silicon Docker Desktop 4.96.2 bundle (Engine 29.9.2, Compose 5.6.1), configured `dorsia-09`, and ran `make setup` on the new isolated database. The preview on port 4173 now proxies `/api` to Laravel on port 8900. That initial Docker connection required no backend source changes; the later cancellation preview adds the scoped endpoint described below.

Live validation exposed one frontend defect: `cancelled_early` / `cancelled_late` were shown as “Past visit” because the UI recognized only the preview's old `cancelled` value. Both cards and detail now show “Cancelled”; new demo records use the contract statuses and old persisted preview records remain readable. The first live test failed on that incorrect status assumption, and the corrected test and new status regression check pass. Browser history confirms the corrected result.

The design follows publicly observed Dorsia patterns; the staff design is an original proposal. See [design sources and assumptions](../experience-design.md).

Card checkout preserves the selected token, serialized body and idempotency key on uncertain retries. The selector is locked while a result is pending. Definitive declines clear the released hold and offer a fresh time/payment choice; timeouts direct members to check bookings. Wallet changes require refreshed review. Connected confirmation and reservation details report the backend's actual allocation. Test fixtures use new isolated accounts and public wallet actions, leaving the original demo member unchanged.

Refresh recovery uses `session.mjs` to save only the bearer/member identity, hold reference, selected test method and immutable pending operations in sessionStorage. GET `/me` verifies the account before connected resources load. GET `/holds/{id}` supplies the current status, quote and original deadline. Refresh performs reads only: an interrupted hold can be retried explicitly; a completed confirmation is recovered from reservations without resubmitting payment. Sign-out and authentication rejection clear credentials/checkout. Temporary outages keep recovery data for retry, and unavailable storage falls back to the current page with an explanatory message.

## Exact cancellation preview follow-up

The member experience now fetches `GET /api/reservations/{id}/cancellation-preview` before enabling cancellation. It displays the exact dining credit returned, amount forfeited, early/late classification and venue-local full-refund deadline. Preview and posting share the Wallet calculation, including configured basis points and floor rounding. The read endpoint verifies member ownership and cancellability, uses microsecond timestamps, returns `private, no-store`, and makes no ledger/inventory/provider changes.

Cancellation sends an optional `expected_refund` alongside the existing idempotency key. The backend recomputes under the reservation lock and returns `409 cancellation_preview_changed` if the reviewed amounts or early/late classification changed, before any mutation. The frontend then loads a fresh preview and requires another click; it never automatically accepts the new refund. A monotonic timer derived from server timestamps refreshes previews at the policy boundary even with a skewed device clock. Uncertain retries retain the exact reviewed body/key, and successful replays still return their original response after the reservation starts. Legacy `{}` cancellations retain their prior behavior.

CUA exercised four actual cancellations against Laravel/Postgres: desktop 1280×900 D16 early ($600 → $600 credit, $0 forfeited) and D17 late ($500 → $250 credit, $250 forfeited); mobile 390×844 D18/D19 produced the same respective results. Preview and success dialogs agreed in every case. Mobile document widths were 390/390 and dialog widths 388/388; no horizontal overflow or browser warning/error logs. Screenshots were visually inspected. Read-only API assertions confirmed cancelled_early/cancelled_late, one reservation per hold, each slot restored to 1/1, and each isolated member wallet exactly $1,750 from $2,000 initial credit less the two bookings plus their refunds. Original demo funds were unchanged by this follow-up.

Fixture run `mtq65go6jg` supplies the three isolated desktop/mobile/integration accounts. D20/D21 were cancelled by the live integration. A first fixture attempt collided on slot uniqueness; fixture times now retain microseconds, and its partial D14/D15 were cancelled through the local cleanup helper. No active validation bookings remain.

## Cancellation history follow-up

Cancelled reservation cards and details now show **Dining credit returned** and **Amount forfeited** from the actual posted cancellation. The nullable `refund` field is returned by reservation GET list/detail and within new cancellation responses. Wallet's public `GetCancellationCredits` batches member-scoped ledger reads, and Reservations hydrates its DTO without accessing another module's tables. Current refund policy is never used to recalculate history. Zero member-credit postings correctly show $0 returned, including the existing full-forfeiture path that posts no ledger transaction. Confirmed reservations have `refund: null`. Existing cancelled reservations work without a backfill or schema change.

`#reservations/past` preserves the selected history tab across a full refresh. The cancellation success action now returns to history, and the original response's refund is immediately attached to the card even when replaying an older response. Demo cancellation stores its receipt on the reservation; older demo records recover their original receipts from the saved operation log. Records without a receipt show an unavailable message rather than a current-policy estimate.

Desktop 1280×900 D16/D17 and mobile 390×844 D18/D19 showed early $600 credit/$0 forfeited and late $250 credit/$250 forfeited on a $500 minimum, before and after full page reload. Both detail dialogs were reopened after reload and matched the cards. The history selection and signed-in account remained intact. Document client/scroll widths were 1280/1280 desktop and 375/375 mobile (390px viewport with scrollbar); mobile dialog widths were 388/388. No warning/error logs, and all selected screenshots were visually inspected. The original demo member's older history also displayed posted refunds after reload. Validation only read existing cancelled records; no new bookings, cancellations or wallet changes were made in the local development database.

History-specific proving commands:

| Criterion | Command/evidence | Result |
| --- | --- | --- |
| Posted refund, zero/full/fractional values, policy/time drift, no writes, ownership and batched reads | `make test ARGS='tests/Modules/Reservations/Feature/CancellationHistoryTest.php tests/Modules/Reservations/Feature/CancellationPreviewTest.php tests/Arch/Contracts/ReservationsContractTest.php tests/Arch/Contracts/WalletContractTest.php'` | **40 passed / 403 assertions**; eight new history cases. Listing three cancelled bookings uses two queries total |
| Demo persistence, old receipts, missing/zero/invalid data, existing session/checkout checks | `node --test tests/Frontend/core.test.mjs tests/Frontend/session.test.mjs tests/Frontend/cancellation.test.mjs` | **42 passed** |
| History through fresh API clients; GET list equals detail, exact posted amounts, wallet/inventory unchanged | `DORSIA_TEST_ORIGIN=http://127.0.0.1:4173 node --test tests/Frontend/history-live.test.mjs` | **1 passed, 0 skipped**; all six early/late records D16–D21; `history-live-validation.json` |
| Desktop/mobile history and details after reload | CUA `preview.reload()` on `#reservations/past`, then reopen both details; `history-browser-validation.json`, `screenshots/history-*.png` | **Pass**, four browser records, correct amounts and selected tab retained |
| Latest full CI | `PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH" make ci` | **Exit0: Pint384 files, PHPStan269 files, 1,019 tests /5,071 assertions,62.51s**; `/tmp/dorsia-09-history-ci.log` |
| Latest architecture checks | `PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH" make arch` | **Exit0:109 tests /914 assertions,3.89s**; `/tmp/dorsia-09-history-arch.log` |
| Frontend static checks | `node --check` on all 15 ES modules; Prettier 3.6.2 `--check 'public/experience/*.mjs' public/experience/style.css 'tests/Frontend/*.mjs'` | **Pass** |

The first focused run caught a string passed to the Currency-enum-only zero helper; the fallback now uses `Money::of(0, currency)` and zero cases pass. Full CI also caught the existing exact confirmation-response assertion, which now explicitly expects the additive `refund: null`. No deferred eval defect was changed by this follow-up; earlier mutation evidence remains historical.

## Acceptance evidence

All commands ran from `/Users/andrewnguyen/development/Dorsia`. `node` below means the bundled executable `/Users/andrewnguyen/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node`.

| Ticket criterion | Evidence | Result |
| --- | --- | --- |
| Discovery, detail, filters, saved venues, empty states | CUA: `preview.goto('http://127.0.0.1:4173/experience/#discover')`; save Carbone → Saved shows 1 venue; search a missing name → 0 results → Reset restores catalogue; party 8 → empty times → party 2 recovers | Pass |
| Hold → review → confirmation → management; money/local time | CUA real backend: desktop 1280×900 Carbone Oct 26, 7 PM, two guests, D3; mobile 390×844 Carbone Oct 27, 8 PM, two guests, D4. Each used $500 credit, no card, then returned $500 on cancellation. API refresh retained mobile booking. Read-only API checks confirm both cancelled and both slots restored to 6/6; wallet restored to $500. See `tests/Frontend/live-validation.json` | Pass against Laravel/Postgres |
| C§8 API adapter, stable retries, no fake success | `DORSIA_TEST_ORIGIN=http://127.0.0.1:4173 node --test tests/Frontend/live.test.mjs`: MW Restaurant D2, $180 quote/debit/refund, exact hold/confirm/cancel replay bytes, rejected changed-body key reuse, one reservation per hold, restored capacity and wallet. Unit suite also tests auth, frozen bodies/UUIDs, network/domain/pending errors, malformed responses and proxy 502 | Pass against real API and contract tests |
| Explicit persistent demo mode | CUA reload retains booking/service changes; Node suite confirms persisted bookings, cancellation credit, operation replay and unavailable-storage fallback | Pass |
| Membership distinguishes access and dining spend | Browser inspection of `#membership`; no tier/renewal claim; external FAQ link and wallet source explained | Pass |
| Operations workflows | CUA: Alex Morgan table 14 + notes saved; confirmed → arrived → seated; dietary exception resolved with text and count 3 → 2; Carbone 6 PM capacity 8 → 10 and pause → 0 available. Node suite rejects invalid transitions/capacity/missing resolution | Pass in local preview |
| Keyboard, status, loading/error states, responsiveness | Native dialog heading focus/Escape verified. Sign-in to missing API shows retryable form error without false connection. 21 layout checks across 320×844, 768×1024 and 1280×800: no unnamed visible buttons; one 4px overflow at 320px discovery was fixed and rechecked at 305px content = 305px viewport content width. 390×844 booking/cancellation and staff flow verified | Pass for checked states; not a full accessibility certification |
| Test-card checkout / exact retry | `DORSIA_TEST_ORIGIN=http://127.0.0.1:4173 node --test tests/Frontend/core.test.mjs tests/Frontend/card-live.test.mjs`: **23 passed, 0 failed, 0 skipped** (22 unit + 1 live). Live D5: $100 wallet + $80 card; a deliberately lost response after commit retried to identical response bytes, one booking and one capture. Missing-card rejection preserves the hold; approved retry with a new body uses a new key | Pass |
| Decline / insufficient funds / timeout | Same test: all three return the correct error, preserve wallet, restore inventory and create no reservation. Browser desktop verifies missing choice and decline recovery; mobile verifies insufficient funds and timeout → empty booking list → fresh successful hold. `make -f Makefile -f tests/Frontend/payment.mk payment-webhook HOLD_ID=7` and `HOLD_ID=12` deliver delayed signed callbacks; `payment-evidence` verifies both timeout authorizations refunded | Pass |
| Desktop/mobile card journeys | CUA 1280×900 desktop D6: Carbone Oct 23, 7 PM, $500 credit + $250 test card. CUA 390×844 mobile D7: Oct 23, 8 PM, $0 credit + $750 test card. API Refresh retains allocation. Both cancel for $750 dining credit; tables restored to 6/6. Read-only API/provider assertions saved in `card-browser-validation.json`: exactly one captured charge per successful hold, matching its card remainder | Pass against Laravel/Postgres/FakeGateway |
| Desktop/mobile refresh recovery | CUA 1280×900 desktop: signed-in reload, hold timer 5:00 → 4:57 with approved card choice retained; D10/hold 16 confirmed, reload showed one booking, then cancelled for $750 credit. CUA 390×844 mobile: same account and hold restored; waited until hold 17's real 14:48:16 UTC expiry, refreshed to an ended-hold notice with sign-in intact, then fresh hold 20 survived reload and became D13, refreshed and cancelled for $750 credit. Sign-out + full reload stayed in preview on both sizes. `session-browser-validation.json` confirms one booking per successful hold, no booking for expired hold, cancelled statuses and slots 6/6 | Pass |
| Mobile layout and browser logs | CUA read-only DOM check: document client/scroll widths 390/390; dialog client/scroll widths 373/373. Browser warning/error log empty. Screenshots `session-mobile-restored.png`, `session-mobile-expired.png`, `session-mobile-booked.png`, `session-mobile-cancelled.png`; viewport override reset after checks | Pass |
| Refresh session and recovery unit tests | `node --test tests/Frontend/core.test.mjs tests/Frontend/session.test.mjs`: **33 passed**. Covers new-instance persistence, logout, 401, mismatched member, corrupted storage, blocked storage, active/expired/released/converted/missing holds, read outages, and exact immutable pending hold/confirmation operations | Pass |
| Real API refresh recovery / wallet regression | `DORSIA_TEST_ORIGIN=http://127.0.0.1:4173 node --test --test-concurrency=1 tests/Frontend/live.test.mjs tests/Frontend/session-live.test.mjs`: **2 passed, 0 skipped**. Reload simulation after hold response loss returns the same hold/quote/deadline; completed confirmation recovers one booking with no recovery POST; cancelled bookings restore wallet and inventory. Invalid token clears saved state. Evidence `session-live-validation.json` (latest D12); wallet regression D11 | Pass |
| Exact preview, policy and legacy behavior | `make test ARGS='tests/Modules/Reservations/Feature/CancellationPreviewTest.php tests/Modules/Reservations/Feature/CancelReservationTest.php tests/Arch/Contracts/ReservationsContractTest.php tests/Arch/Contracts/WalletContractTest.php'`: **49 passed / 441 assertions**. Fifteen new preview tests cover read-only results, overrides/rounding, full/zero late refund, cutoff −1/0/+1 microsecond, ownership/auth, started/cancelled reservations, malformed expectations and unchanged legacy behavior | Pass |
| Changed preview and retry safety | The same backend suite rejects stale amounts/timing without writes, rejects changed-body key reuse, accepts fresh reviewed requests, and replays an original success after start. `DORSIA_TEST_ORIGIN=http://127.0.0.1:4173 node --test tests/Frontend/cancellation-live.test.mjs`: **1 passed, 0 skipped**, real early/late preview equals posting, wrong expectation rejected, replay identical, wallet and inventory exact. Evidence `cancellation-live-validation.json` | Pass |
| Early/late desktop and mobile | CUA actual preview → cancel journeys D16–D19 described above; `DORSIA_TEST_ORIGIN=http://127.0.0.1:4173 node tests/Frontend/cancellation-browser-check.mjs` checks final API state for both accounts and writes `cancellation-browser-validation.json` | Pass |
| Automated and syntax checks | `node --test tests/Frontend/core.test.mjs tests/Frontend/session.test.mjs tests/Frontend/cancellation.test.mjs tests/Frontend/wallet-history.test.mjs`: **47 passed**. Together with the latest wallet-history live integration: **48 checks passed**. `node --check` and Prettier 3.6.2 cover frontend/test ES modules. Earlier cancellation/card/session/wallet integration runs are retained above as historical evidence, not counted as new runs | Pass |
| Repository CI | `PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH" make ci`: exit 0; Pint **390 files**, PHPStan **270 files**, **1,032 tests / 5,239 assertions**, full suite51.87s, real Postgres test database. Log `/tmp/dorsia-09-wallet-ci.log` | Pass |
| Architecture suite | `PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH" make arch`: exit 0; **110 tests / 937 assertions**,3.87s. Log `/tmp/dorsia-09-wallet-arch.log` | Pass |
| Scope and final report | `git status --short`, `git diff --check`; all changes in approved paths plus required uncommitted HANDOFF | Pass |

Two targeted mutation checks proved the new tests bite. Changing `greaterThan` to `greaterThanOrEqualTo` at the cutoff made `make test ARGS='tests/Modules/Reservations/Feature/CancellationPreviewTest.php --filter="previews exactly what cancellation posts at microsecond boundaries"'` fail at the exact boundary. Disabling the expected-refund guard made `make test ARGS='tests/Modules/Reservations/Feature/CancellationPreviewTest.php --filter="requires re-review when the deadline passes"'` fail with HTTP 200 instead of 409. Both mutations were restored; the preview suite then passed **15 tests / 131 assertions** and full CI passed. Evidence: `cancellation-mutation-validation.json`, `/tmp/dorsia-09-cancellation-mutant-cutoff.log`, `/tmp/dorsia-09-cancellation-mutant-stale_guard.log`. This does not claim completion of the older deferred eval items.

## Wallet transaction history follow-up

Added **Membership → View transactions**, backed by authenticated `GET /api/wallet/transactions`. Wallet returns 20 member-owned records per page with an exclusive `before_id` cursor and newest transaction ID first. The action reads posted ledger entries for booking payments, early/late cancellation postings and credit additions in two queries per nonempty page; it makes no writes or cross-module table reads. Response data includes reservation/venue references, payment allocation, signed wallet change, exact refund/forfeiture and UTC microsecond timestamps. Historical credit additions without member metadata remain discoverable through their member reference. No schema/backfill is required.

The wallet page separates payment total from wallet movement, so card-only spend shows a $0 wallet change. It shows credit/coupon/test-card splits and posted refund forfeiture, with local timestamps. `#membership/wallet` keeps the selected page through refresh; identity is revalidated and history is re-fetched from the backend. Account changes invalidate pending requests and clear previous rows. The panel provides loading, empty, retry and Load older transactions states. Sample history derives from persisted original payments/refunds without estimating missing receipts or an opening balance.

CUA checked desktop1280×900 D16/D17 and mobile390×844 D18/D19: payments −$600/−$500, early refund +$600/$0 forfeited, late refund +$250/$250 forfeited. The entire history region was identical before/after full refresh, with five rows and a $1,750 wallet. Mobile D7 additionally showed a $750 test-card payment with Wallet$0 and a +$750 refund after refresh; D13 showed its later wallet payment/refund. The in-page Refresh button also retained exact data. Account switches removed previous member entries. Client/scroll widths matched (1265/1265 desktop,375/375 mobile); no browser warning/error logs. Screenshots were visually inspected. Validation reused existing posted records without development booking/cancellation/wallet mutations.

| Wallet-history criterion | Proving command or evidence | Result |
| --- | --- | --- |
| Authenticated, member-owned, read-only posted data and pagination | `make test ARGS='tests/Modules/Wallet/Feature/WalletTransactionsTest.php tests/Arch/Contracts/WalletContractTest.php'`: 26 tests /301 assertions. Covers wallet/coupon/card splits, early/late and zero refunds, policy drift, UTC microseconds, auth/ownership on every page, 20+3 pagination with a new insertion, cursor validation and two-query reads | Pass |
| Refresh persistence and exact totals | `DORSIA_TEST_ORIGIN=http://127.0.0.1:4173 node --test tests/Frontend/wallet-history-live.test.mjs`: 1 passed. Five accounts, fresh clients, exact payment/refund reconciliation and unchanged balances; `wallet-history-live-validation.json` | Pass |
| Sample persistence, empty/older data and deduplication | `node --test tests/Frontend/core.test.mjs tests/Frontend/session.test.mjs tests/Frontend/cancellation.test.mjs tests/Frontend/wallet-history.test.mjs`: 47 passed. Includes fresh sample clients, immutable cancellation replay, 20+5 pages, empty history, missing receipts and duplicate-free merges. Initial signed-zero mismatch fixed in sample wallet movement/test expectations | Pass |
| Responsive display, selected route, refresh and account switching | CUA desktop/mobile observations in `wallet-history-browser-validation.json` and `screenshots/wallet-*.png`. Loading/error/retry branches additionally reviewed in `loadWalletHistory`/`walletHistoryPage`; outage and older-page retry were not induced in the live browser | Pass for recorded coverage |
| Full repository gates | `PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH" make ci`: Pint390, PHPStan270, 1,032 tests /5,239 assertions,51.87s; `make arch`:110 /937 assertions,3.87s | Pass |

Logs: `/tmp/dorsia-09-wallet-focused.log`, `/tmp/dorsia-09-wallet-node.log`, `/tmp/dorsia-09-wallet-live.log`, `/tmp/dorsia-09-wallet-ci.log`, `/tmp/dorsia-09-wallet-arch.log`. This additive feature does not claim fixes for deferred eval defects; earlier cancellation mutation proofs remain below. All17 ES modules passed syntax; frontend ES modules/CSS passed Prettier3.6.2 after formatting the signed-zero fix; git diff --check and the exact ticket09 allowlist audit passed (140 files, zero outside scope). No required implementation work remains.

A cancellation with no ledger posting has no synthetic wallet-history row; its zero refund is still available in reservation history. History does not claim a running balance or card-provider settlement record. No new architect question blocks this request; the additive endpoint/DTO decisions are recorded at the top and in C§5.3/C§8.

## Review artifacts

- [Desktop wallet transactions](../../tests/Frontend/screenshots/wallet-desktop-transactions.png)
- [Mobile wallet after refresh](../../tests/Frontend/screenshots/wallet-mobile-after-refresh.png)
- [Mobile payment and refund rows](../../tests/Frontend/screenshots/wallet-mobile-transactions.png)
- [Mobile card-only payment](../../tests/Frontend/screenshots/wallet-mobile-card.png)
- [Wallet browser validation](../../tests/Frontend/wallet-history-browser-validation.json)
- [Wallet API validation](../../tests/Frontend/wallet-history-live-validation.json)

- [Desktop history after refresh](../../tests/Frontend/screenshots/history-desktop-after-refresh.png)
- [Mobile early history card](../../tests/Frontend/screenshots/history-mobile-early-card.png)
- [Mobile late history card](../../tests/Frontend/screenshots/history-mobile-late-card.png)
- [Mobile late refund details](../../tests/Frontend/screenshots/history-mobile-late-detail.png)
- [History browser validation](../../tests/Frontend/history-browser-validation.json)
- [History API evidence](../../tests/Frontend/history-live-validation.json)

- [Desktop early refund preview](../../tests/Frontend/screenshots/cancellation-desktop-early-preview.png)
- [Desktop late refund preview](../../tests/Frontend/screenshots/cancellation-desktop-late-preview.png)
- [Mobile early refund preview](../../tests/Frontend/screenshots/cancellation-mobile-early-preview.png)
- [Mobile late refund preview](../../tests/Frontend/screenshots/cancellation-mobile-late-preview.png)
- [Mobile late cancellation result](../../tests/Frontend/screenshots/cancellation-mobile-late-result.png)
- [Cancellation browser API evidence](../../tests/Frontend/cancellation-browser-validation.json)
- [Cancellation live integration evidence](../../tests/Frontend/cancellation-live-validation.json)
- [Targeted mutation evidence](../../tests/Frontend/cancellation-mutation-validation.json)

- [Desktop member discovery](../../tests/Frontend/screenshots/member-desktop.png)
- [Mobile member discovery](../../tests/Frontend/screenshots/member-mobile.png)
- [Mobile quote/confirmation review](../../tests/Frontend/screenshots/booking-mobile.png)
- [Desktop operations workspace](../../tests/Frontend/screenshots/operations-desktop.png)
- [Connected desktop confirmation](../../tests/Frontend/screenshots/live-desktop-confirmed.png)
- [Connected desktop cancellation](../../tests/Frontend/screenshots/live-desktop-cancelled.png)
- [Connected mobile review](../../tests/Frontend/screenshots/live-mobile-review.png)
- [Connected mobile confirmation](../../tests/Frontend/screenshots/live-mobile-confirmed.png)
- [Connected mobile cancellation](../../tests/Frontend/screenshots/live-mobile-cancelled.png)
- [Final API state for both browser bookings](../../tests/Frontend/live-validation.json)
- [Desktop test-card review](../../tests/Frontend/screenshots/card-desktop-review.png)
- [Desktop split-payment result](../../tests/Frontend/screenshots/card-desktop-payment.png)
- [Mobile test-card review](../../tests/Frontend/screenshots/card-mobile-review.png)
- [Mobile card-only result](../../tests/Frontend/screenshots/card-mobile-payment.png)
- [Mobile insufficient funds](../../tests/Frontend/screenshots/card-mobile-insufficient.png)
- [Card integration evidence](../../tests/Frontend/card-integration-validation.json)
- [Card browser and capture evidence](../../tests/Frontend/card-browser-validation.json)
- [Restored desktop hold](../../tests/Frontend/screenshots/session-desktop-restored.png)
- [Restored mobile hold](../../tests/Frontend/screenshots/session-mobile-restored.png)
- [Mobile expired-hold recovery](../../tests/Frontend/screenshots/session-mobile-expired.png)
- [Mobile booking after refresh](../../tests/Frontend/screenshots/session-mobile-booked.png)
- [Browser refresh evidence and final API state](../../tests/Frontend/session-browser-validation.json)
- [Real API refresh recovery evidence](../../tests/Frontend/session-live-validation.json)
- [Run instructions and browser checklist](../../tests/Frontend/README.md)

Connected preview: `DORSIA_API_ORIGIN=http://127.0.0.1:8900 node tests/Frontend/serve.mjs`, then `/experience/` on localhost port 4173. The task leaves the stack and connected preview running for review, signed in as the original demo member on `#membership/wallet` with the viewport override reset. On this host, use the bundled Node path above; Docker added `~/.docker/bin` to the login shell PATH. `make up` restarts the existing stack without reseeding. The local seeded login is `demo@dorsia.test` / `password`; it is unrelated to the public Dorsia service.

## Remaining limitations

Current CI and architecture gates are green. No host PHP, host Composer, SQLite substitute, or older CI result was used as current validation. Validation reservations remain cancelled in the development database; tested table capacities are restored. The original demo wallet now has $750: the desktop refresh test used $500 wallet + $250 test card and its cancellation returned the full $750 as credit. The isolated card-test accounts now contain cancellation credit ($750 desktop/mobile, $180 integration), so repeatable card tests generate fresh accounts via the documented fixture command.

Card checkout uses the existing fake gateway only; no real card is charged and secure real-card collection is not implemented. Automatic fake callbacks are disabled by the current environment, so timeout reconciliation was validated using explicit delayed callbacks through the existing signed webhook endpoint. Staff APIs/auth and membership entitlements remain deferred. Responsive browser validation is not native iOS/Android device validation. Refreshing the same tab preserves sign-in and restores a known active hold. Closing the tab normally ends this saved session. Recovery cannot discover unknown holds after storage is cleared or on another device. Sign-out clears this tab; the existing API has no token-revocation endpoint. If browser storage is unavailable, the UI explains that sign-in lasts only for the current page. Staff preview inventory and customer demo inventory are separate illustrative stores. Live browser checks cover both early and late cancellation and refund history after refresh on desktop and mobile; backend tests also cover configured overrides, rounding and exact microsecond boundaries. The preview is a current calculation, not a price lock; a changed refund requires fresh review. Legacy API clients that omit expected_refund preserve their original unguarded behavior.

Delivery branch: `member-experience`, based on `main`. On October 22, 2026, the user explicitly requested committing and pushing the completed changes. `HANDOFF.md` remains local and uncommitted; the final delivery status is recorded there.
