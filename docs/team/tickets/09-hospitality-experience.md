# 09 — Hospitality experience

User approved this isolated frontend scope on 2026-10-22. Existing backend contracts remain binding.

## Files allowed
- `public/experience/**`
- `tests/Frontend/**`
- This ticket, `docs/experience-design.md`, `docs/team/experience-report.md`
- Root `HANDOFF.md` (uncommitted)
- `.env` (ignored local env 09 configuration, authorized by the Docker setup follow-up)

## Cancellation-preview follow-up scope (2026-10-22)
The user's request for an exact backend-policy cancellation preview requires an additive API and a shared backend calculation. Extend this ticket to the following specific files, keeping existing two-argument cancellation callers and `{}` HTTP bodies compatible:
- `docs/team/contracts.md`
- `app/Modules/Wallet/Support/LateCancelSplit.php`
- `app/Modules/Wallet/Actions/PreviewCancellationRefund.php`
- `app/Modules/Wallet/Actions/PostCancellationRefund.php`
- `app/Modules/Reservations/Actions/PreviewCancellation.php`
- `app/Modules/Reservations/Actions/CancelReservation.php`
- `app/Modules/Reservations/Support/CancellationPolicy.php`
- `app/Modules/Reservations/Data/CancellationPreview.php`
- `app/Modules/Reservations/Data/CancellationExpectation.php`
- `app/Modules/Reservations/Exceptions/CancellationPreviewChangedException.php`
- `app/Modules/Reservations/Http/Requests/CancelReservationRequest.php`
- `app/Modules/Reservations/Http/Resources/CancellationPreviewResource.php`
- `app/Modules/Reservations/Http/Controllers/ReservationController.php`
- `app/Modules/Reservations/routes/api.php`
- `tests/Modules/Reservations/Feature/CancellationPreviewTest.php`
- `tests/Arch/Contracts/ReservationsContractTest.php`
- `tests/Arch/Contracts/WalletContractTest.php`
No Shared, schema, payment-provider or other module application changes.

## Cancellation-history follow-up scope (2026-10-22)
The requested durable refund history adds ledger-backed read data to existing reservation responses. In addition to the prior allowlist:
- `app/Modules/Wallet/Actions/GetCancellationCredits.php`
- `app/Modules/Reservations/Actions/GetReservation.php`
- `app/Modules/Reservations/Actions/ListReservations.php`
- `app/Modules/Reservations/Data/ReservationData.php`
- `app/Modules/Reservations/Models/Reservation.php`
- `app/Modules/Reservations/Http/Resources/ReservationResource.php`
- `tests/Modules/Reservations/Feature/CancellationHistoryTest.php`
- `tests/Modules/Reservations/Feature/ConfirmReservationTest.php` (existing exact response assertion for additive `refund: null`)
No schema changes. Wallet exposes posted member credits through a public batch read action; Reservations never reads Wallet tables directly. A cancelled reservation with no member-credit posting has zero returned credit, including the existing no-ledger zero-refund policy case.

## Wallet transaction-history follow-up scope (2026-10-22)
The requested wallet history requires a member-scoped posted-transaction read endpoint. Additional allowed files:
- `app/Modules/Wallet/Actions/ListWalletTransactions.php`
- `app/Modules/Wallet/Data/WalletTransaction.php`
- `app/Modules/Wallet/Data/WalletTransactionPage.php`
- `app/Modules/Wallet/Http/Requests/WalletTransactionsRequest.php`
- `app/Modules/Wallet/Http/Resources/WalletTransactionPageResource.php`
- `app/Modules/Wallet/Http/Controllers/WalletController.php`
- `app/Modules/Wallet/routes/api.php`
- `tests/Modules/Wallet/Feature/WalletTransactionsTest.php`
Previously scoped contract/Wallet reflection tests, frontend/tests and report paths remain allowed. No schema, Shared, provider or other module changes. Show posted booking/refund/credit-addition data, distinguish total payment from wallet change, and paginate older records.

## Work
Build a responsive member web experience informed by Dorsia's public website and App Store screenshots. Use the C§8 customer API, including the scoped cancellation addition above. Provide an explicitly labeled local operations preview because staff auth and APIs are not implemented. This ticket does not implement a native Expo application, new membership entitlements, staff endpoints, real payment collection, or deployment.

On 2026-10-22 the user explicitly approved test-card checkout with the existing fake gateway. Extend the frontend to collect a test-card choice, submit its documented token for the card remainder, preserve retry identity, and handle declines/timeouts. No card number, CVC, real credentials, or new payment-provider contract is introduced. Local payment fixtures and verification scripts belong in `tests/Frontend/**` and run PHP through Docker/Make.

On 2026-10-22 the user requested refresh-persistent member sign-in and active hold restoration. Implement tab-scoped sessionStorage, validate identity and known holds with existing APIs, preserve pending operation identity, clear on logout/401, and verify desktop/mobile without backend changes.

## Acceptance
- [x] Authenticated wallet history is member-owned, read-only, paginated and based on posted ledger entries, including wallet/card allocation and exact refund amounts.
- [x] Wallet activity survives refresh and account switching without stale data; loading, empty, error/retry and older-entry states work.
- [x] Booking payments and early/late refunds are verified on desktop/mobile before and after refresh; automated checks, CI and arch pass.
- [x] Cancelled list/detail responses expose the posted refund and forfeiture, unaffected by later policy changes, with member ownership preserved.
- [x] History cards and detail show exact amounts immediately and after reload, including zero refunds; sample history persists actual cancellation results.
- [x] Desktop/mobile early and late history survives full refresh; automated checks, CI and arch pass.
- [x] Authenticated, member-owned refund preview uses the same backend timing and rounding policy as cancellation, without writes.
- [x] A changed preview cannot silently cancel for a different amount; unchanged guarded requests and legacy cancellations retain idempotency.
- [x] Early/late amounts, policy overrides, microsecond boundaries, ownership and stale previews have automated coverage.
- [x] Desktop and mobile each validate early and late preview → cancellation → wallet/inventory result; CI/arch pass.

- [x] Refresh preserves the connected session in the current tab; server rejects expired credentials and sign-out clears saved session/checkout state.
- [x] Refresh restores a known active hold with its server quote/deadline, and safely reconciles expired, converted, missing, or uncertain requests without duplicate writes.
- [x] Validate session and hold recovery against the real API on desktop and mobile, plus automated recovery checks and green CI/architecture gates.
- [x] Responsive discovery, venue detail, availability filtering, saved venues, and empty states.
- [x] Hold → review → confirmation → booking management, with integer money and venue-local times.
- [x] Real API adapter supports login, catalogue, availability, hold, confirmation, cancellation, and wallet. Stable idempotency keys on retries; no simulated success after API errors.
- [x] Separate demo mode persists sample interactions and clearly identifies sample data and test payments.
- [x] Membership information distinguishes subscription from dining spend, with no invented live tier.
- [x] Staff preview supports reservation search/status filters, guest detail/notes, arrival/seating transitions, availability management, and exception resolution.
- [x] Keyboard-accessible dialogs, visible focus, status announcements, reduced motion, and mobile layouts.
- [x] Validate key journeys at desktop and mobile sizes, and automated contract/state tests.
- [x] Run `make ci` and `make arch` successfully against the Docker/Postgres stack.
- [x] Validate real backend booking and cancellation at desktop and mobile widths, including wallet and inventory restoration.
- [x] Record design sources, assumptions, commands, remaining limitations, and continuation steps.
- [x] Test-card checkout for wallet remainder and card-only totals, with clear test labels and immutable retries.
- [x] Decline/insufficient-funds/timeout recovery verified without false bookings or wallet debits.
- [x] Card booking validated at desktop and mobile widths against the real local API; CI/arch green.

## Environment
Existing checkout; no extra worktree. Docker Desktop 4.96.2 installed on this Apple Silicon host at the user's request. Env 09 uses app 8900, Reverb 8980, Postgres 6332 and Redis 7279. Initial `make setup` passed. The bundled Node preview on port 4173 proxies the same-origin API to port 8900.

## Validation result
Latest wallet-history follow-up: `make ci` passed **1,032 tests /5,239 assertions**, Pint390 and PHPStan270; `make arch` passed **110 tests /937 assertions**. Frontend **47 unit +1 wallet-history live integration =48 passed**; focused backend **26 tests /301 assertions**. Desktop1280×900 D16/D17 and mobile390×844 D18/D19 retained identical transaction text through full refresh (−$600/−$500 payments, +$600/+$250 refunds, $250 late forfeiture). Mobile D7 retained its $750 test-card payment with $0 wallet change and +$750 cancellation credit. Account switching and the Refresh button worked; no horizontal overflow or browser warning/error logs. Existing fixtures were read without booking/cancellation/wallet writes. The report maps each criterion to its proving command and describes the frontend state review.

Previous cancellation-history follow-up: `make ci` passed **1,019 tests /5,071 assertions**, Pint384 and PHPStan269; `make arch` passed **109 /914 assertions**. Frontend **42 unit +1 history live integration =43 passed**. Focused backend **40 /403 assertions**. Existing D16/D17 desktop1280×900 and D18/D19 mobile390×844 retained exact early$600/$0 and late$250/$250 history after full refresh, with matching details, preserved selected tab and no overflow/browser errors. No new development bookings/cancellations or wallet changes. See report and history validation JSON/screenshots.

Previous cancellation-preview follow-up: `make ci` passed with Pint 382 files, PHPStan 268 files, **1,010 tests / 4,956 assertions**; `make arch` passed **108 tests / 911 assertions**. Frontend **39 unit + 1 live integration** passed. Focused backend **49 tests / 441 assertions** passed; two targeted mutations failed as expected and were restored. Desktop 1280×900 D16/D17 and mobile 390×844 D18/D19 each verified early ($600 credit, $0 forfeited) and late ($250 credit, $250 forfeited on $500) previews against actual cancellations. API checks confirmed exact wallet balances and restored capacity; screenshots show both review and result. Every new criterion is mapped to its proving command in the report.

Earlier refresh follow-up evidence:
`make ci` passed (Pint, PHPStan, 993 tests); `make arch` passed (106 tests / 900 assertions). Latest refresh follow-up: 33 unit + 2 live integration tests passed; earlier card integration remains documented in the report. Browser validation at 1280×900 and 390×844 covers wallet-only, wallet plus test card, card-only, declined/insufficient/timeout recovery, persisted allocations, capture and cancellation credit. Fixed cancellation status labels, added explicit test-card checkout, and implemented tab-scoped sign-in/known-hold refresh recovery without backend source changes. Desktop D10 and mobile D13 proved restore → book → refresh → cancel; mobile hold 17 expired naturally and was not revived. Sign-out stayed effective after refresh on both screen sizes. See `docs/team/experience-report.md` for commands and evidence. Validation covers responsive web and the fake gateway, not native iOS/Android or real card collection.
