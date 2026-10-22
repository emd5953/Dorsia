# Frontend preview and verification

No package installation or build step is needed. Use Node 22+ (tested with bundled Node 24.19.0):

```sh
node tests/Frontend/serve.mjs
```

Open http://127.0.0.1:4173/experience/ . Operations is `#operations`. The server binds only to loopback. If `node` is not on PATH, this host's bundled runtime is `/Users/andrewnguyen/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node`.

For the real local API after the Docker stack is running:

```sh
DORSIA_API_ORIGIN=http://127.0.0.1:8900 node tests/Frontend/serve.mjs
```

On this Mac Docker Desktop installs its CLI in `~/.docker/bin`. New login shells load that path automatically. In an already-running terminal use `export PATH="$HOME/.docker/bin:$PATH"`, then:

```sh
bin/worktree-env 09
make up
```

The initial installation used `make setup` to build, install Composer dependencies in Docker, and seed the new isolated database. Do not rerun `make setup` against data you want to retain: it includes `migrate:fresh`.

Use the existing dev account via Sign in. The API origin must be localhost or 127.0.0.1 with an explicit port. The server does not log credentials. Alternatively, serve `/experience/index.html` from the existing Laravel public directory and requests remain same-origin.

Automated checks:

```sh
node --test tests/Frontend/core.test.mjs
DORSIA_TEST_ORIGIN=http://127.0.0.1:4173 node --test tests/Frontend/live.test.mjs
node --check public/experience/app.mjs
node --check public/experience/core.mjs
node --check public/experience/operations.mjs
node --check tests/Frontend/serve.mjs
make ci
make arch
```

The Node suite tests transport contracts, error/uncertain-result classification, frozen idempotency bodies, payment allocation, venue-local dates through DST, preview booking/cancel replay, expiry, sold-out/party constraints, storage fallback, unique IDs, staff transitions, capacity constraints, and exception resolution. It does not replace the required real-Postgres Laravel suite.

The opt-in live test requires the seeded `demo@dorsia.test` account and the connected preview server. It creates and cancels one future wallet-funded booking using the UI's ApiClient. It verifies exact hold/confirmation/cancellation replay bytes, rejected key/body mismatch, wallet debit/refund, reservation persistence, and restored availability. It only accepts a localhost origin and skips when `DORSIA_TEST_ORIGIN` is omitted. It leaves a cancelled reservation in the development database; it never uses a real card.

Browser checklist (CUA, completed October 22, 2026):

- Desktop and 390px mobile: venue → times → hold → disclosure → confirm → bookings.
- Mobile: booking detail → cancellation disclosure → returned dining credit.
- Save Carbone; verify Saved collection. Search missing restaurant; reset filters.
- Change party to 8; verify empty availability. Change back to 2; available times recover.
- Sign in with the repository's demo account while API is unavailable; error preserves the form and demo context.
- Staff: guest notes/table assignment saved and retained; expected → arrived → seated; exception resolved with written handoff; open count updates.
- Staff: capacity raised from 8 to 10, availability paused, available count becomes 0.
- Widths 320, 390, 768, 1280: main page overflow and unnamed button inspection. Dialog Escape and heading focus checked.
- Original screenshots saved in `screenshots/` contain fictional preview data. Files prefixed `live-` show the real local backend with its seeded development account.

Connected browser verification (October 22, 2026):

- Desktop 1280×900: Carbone, October 26 at 7 PM, two guests, $500 wallet-funded reservation D3; cancellation returned $500 and forfeited $0.
- Mobile 390×844: Carbone, October 27 at 8 PM, two guests, $500 wallet-funded reservation D4; API Refresh retained it; cancellation returned $500 and forfeited $0.
- Both appear as Cancelled in history. The wallet is back to $500; read-only API verification confirms both slots have all six tables available. Evidence: `live-validation.json` and `screenshots/live-*.png`.
- Connected history at mobile width: content width and scroll width both 375px (390px viewport including scrollbar).
- `make ci`: 993 passed / 4,813 assertions, Pint and PHPStan clean. `make arch`: 106 passed / 900 assertions. Frontend unit plus opt-in live checks: 19 passed / 0 skipped.

The developer preview server and browser checks do not establish actual native iOS/Android behavior. Connected checkout supports the existing fake gateway; real card collection and staff APIs remain outside this ticket.

## Test-card checkout

Sign in and choose a table whose minimum exceeds the wallet balance. The review shows the remaining test-card amount and asks for a test-card choice. Approved, declined, insufficient-funds and timeout outcomes use the existing C§11 tokens. No real card details are accepted or charged. Wallet-covered bookings need no card selection. Cancelling a successful test-card booking returns dining credit, so subsequent bookings may need a higher minimum to exercise the card path.

Create fresh, isolated local accounts for repeatable checks (existing members and bookings are preserved):

```sh
make -f Makefile -f tests/Frontend/payment.mk payment-fixtures > tests/Frontend/payment-fixtures.json
DORSIA_TEST_ORIGIN=http://127.0.0.1:4173 node --test tests/Frontend/core.test.mjs tests/Frontend/card-live.test.mjs
DORSIA_TEST_ORIGIN=http://127.0.0.1:4173 node --test tests/Frontend/live.test.mjs
make -f Makefile -f tests/Frontend/payment.mk payment-evidence
```

The generated JSON identifies desktop ($500 credit), mobile ($0) and integration ($100) accounts. Their local development password is `password`. PHP fixture/inspection commands run through Make in Docker and refuse non-local/non-fake configurations. Generate fresh fixtures before rerunning the card integration test; its successful cancellation increases that account's credit. The integration test creates one cancelled reservation and three failed attempts, and simulates a lost success response to prove that retrying creates only one booking.

The default fake gateway has automatic callbacks disabled. To simulate the delayed authorization callback for a known timeout-test hold, run:

```sh
make -f Makefile -f tests/Frontend/payment.mk payment-webhook HOLD_ID=<timeout-test-hold-id>
make -f Makefile -f tests/Frontend/payment.mk payment-evidence
```

The signed local callback exercises the existing orphan-authorization refund path. It is test support, not a member endpoint. The queue worker must be running. Inspect evidence again after it processes the refund. Gateway inspection reports authorization/capture/refund state without payment-table reads.

Card browser verification (October 22, 2026):

- Desktop 1280×900: missing choice rejected before submission; declined card released the hold; a fresh approved attempt confirmed D6 with $500 credit + $250 test card. Refresh preserved the allocation. Cancellation returned $750 credit.
- Mobile 390×844: insufficient funds and timeout created no booking; a fresh approved attempt confirmed D7 with $0 credit + $750 test card. Refresh preserved the allocation. Cancellation returned $750 credit. No horizontal overflow.
- Gateway inspection found one captured charge for each successful browser hold. Both timeout-test authorizations were refunded after delayed callbacks. All tested table capacities were restored.
- Evidence: `card-integration-validation.json`, `card-browser-validation.json`, `card-gateway-validation.json`, and `screenshots/card-*.png`.
- Validation: 22 unit checks + 1 card integration + 1 wallet integration passed. CI and architecture checks remain green; see the report for the final run counts.

## Refresh recovery

The member token, member ID, known hold reference and immutable pending operations are stored in **sessionStorage for the current tab**. Passwords are never stored. Refresh verifies `/api/me`, then loads current wallet/bookings and re-fetches the known hold; it does not extend the deadline or automatically send a booking/payment write. Closing the tab normally ends the saved session. Sign-out and rejected credentials clear it. This does not discover holds from another device or cleared browser storage because no such endpoint exists in the current contract.

The API remains authoritative: expired/released/missing holds clear with a notice, converted holds reconcile with bookings, and temporary failures preserve recovery state for retry. Unknown hold writes keep their exact body/key behind an explicit retry. Submitted confirmations retain their test-card token/body/key; an already-completed booking is shown without submitting it again. Unsubmitted card selection persists, while the cancellation acknowledgment must be checked again after refresh. Demo/service data remains separate.

```sh
node --test tests/Frontend/core.test.mjs tests/Frontend/session.test.mjs
DORSIA_TEST_ORIGIN=http://127.0.0.1:4173 node --test tests/Frontend/session-live.test.mjs
```

The live test creates new client/session instances around real holds and confirmation, simulates refresh before responses are applied, checks identical hold/deadline/quote and one booking, cancels it, verifies wallet/inventory restoration, and checks sign-out plus a rejected token. It writes `session-live-validation.json` without credentials. Use the existing seeded demo account; the test requires enough credit for one MW Restaurant guest.

Refresh browser validation (October 22, 2026): desktop 1280×900 and mobile 390×844 both kept the connected account and restored an active hold with the original countdown. Desktop retained its approved test-card selection and booked D10 with $500 credit + $250 card; mobile let hold 17 expire naturally, refreshed to an ended-hold notice, then restored a fresh hold and booked D13 with $750 credit. Both bookings survived refresh and were cancelled for $750 credit; sign-out plus refresh stayed signed out. Backend assertions confirm one booking per successful hold, zero for the expired hold, and restored slot capacities 6/6. Evidence is in `session-browser-validation.json` and `screenshots/session-*.png`.

Current refresh follow-up gates: 33 unit + 2 live checks = **35 passed**; `make ci` **993 passed / 4,812 assertions** (49.93s), Pint 371 files and PHPStan clean; `make arch` **106 passed / 900 assertions** (2.85s). No browser errors or mobile horizontal overflow. The original demo account now has $750 credit because the desktop test-card portion also returns as dining credit under the contract. The browser is left signed in as that demo member, with no active validation bookings, and the temporary viewport override is reset.


## Exact cancellation refund preview

Open a confirmed reservation and choose **Change of plans? Cancel reservation**. The app fetches the member-owned backend preview and displays exact dining credit, forfeiture, early/late classification, and the venue-local full-refund deadline before enabling cancellation. Refunds go to dining credit, including eligible test-card spend. If timing or policy changes the refund, the backend rejects the stale expectation without cancelling, and the UI requires fresh review. Network retries preserve the same reviewed body and key. Older clients can still submit the existing empty cancellation body.

Repeatable local verification (PHP runs only through Make/Docker):

```sh
make -f Makefile -f tests/Frontend/cancellation.mk cancellation-reload
make -f Makefile -f tests/Frontend/cancellation.mk cancellation-fixtures > tests/Frontend/cancellation-fixtures.json
node --test tests/Frontend/core.test.mjs tests/Frontend/session.test.mjs tests/Frontend/cancellation.test.mjs
DORSIA_TEST_ORIGIN=http://127.0.0.1:4173 node --test tests/Frontend/cancellation-live.test.mjs
# Complete both early and late cancellations in the browser for desktop and mobile accounts, then:
DORSIA_TEST_ORIGIN=http://127.0.0.1:4173 node tests/Frontend/cancellation-browser-check.mjs
make test ARGS='tests/Modules/Reservations/Feature/CancellationPreviewTest.php tests/Modules/Reservations/Feature/CancelReservationTest.php tests/Arch/Contracts/ReservationsContractTest.php tests/Arch/Contracts/WalletContractTest.php'
make ci
make arch
```

The fixture command creates isolated desktop, mobile and integration accounts, each with one early and one late wallet-funded reservation. Generated JSON contains the local emails; their development password is `password`. Each starts with $2,000 credit before $600 early and $500 late bookings. Generate fresh fixtures before repeating the integration or browser cancellation journeys; they consume those confirmed reservations. The helper refuses non-local/non-fake configurations and preserves existing members. `cancellation-reload` reloads Octane after backend edits without resetting data.

To inspect or cancel unfinished fixture bookings after an interrupted run:

```sh
make -f Makefile -f tests/Frontend/cancellation.mk cancellation-fixture-cleanup
make -f Makefile -f tests/Frontend/cancellation.mk cancellation-fixture-cleanup FIXTURE_RUN=<run-from-fixtures-json>
```

The first command only lists fixture reservations. Supplying a run cancels its confirmed bookings using the public action; it does not delete accounts or ledger history.

Completed October 22, 2026, fixture run `mtq65go6jg`:

- Desktop 1280×900 D16 early and D17 late; mobile 390×844 D18 early and D19 late: preview → explicit cancellation → result matched exactly. Early $600 returned/$0 forfeited; late $250 returned/$250 forfeited on a $500 minimum.
- Both wallets ended at $1,750, and all four slots returned to 1/1 available. One reservation per hold, correct cancelled_early/cancelled_late statuses. Evidence: `cancellation-browser-validation.json` and eight `screenshots/cancellation-*.png` review/result images.
- Mobile document client/scroll widths 390/390 and dialog widths 388/388. No browser warnings/errors. Viewport reset; browser restored to the original demo member after a successful signed-in reload.
- Live D20/D21 confirmed preview = posting, read-only preview, wrong expected refund rejection, exact replay, wallet and inventory. Evidence: `cancellation-live-validation.json`.
- Current gates: **39 unit + 1 live checks**, focused backend **49 / 441 assertions**, `make ci` **1,010 / 4,956 assertions** (Pint 382, PHPStan 268), `make arch` **108 / 911 assertions**. Two targeted cutoff/stale-guard mutations were killed and restored; details and exact commands are in the report.


## Cancelled refund history

**Past & cancelled** now shows the posted dining credit returned and amount forfeited on each card and in its details. The selected view uses `#reservations/past`, so full refresh retains it. Amounts come from existing ledger postings through the reservation API, independent of current policy; no browser receipt cache or backfill is needed for live bookings. The cancellation result links directly back to history. Demo receipts persist locally; old receipts are recovered from stored operations when available, otherwise details are labeled unavailable.

```sh
node --test tests/Frontend/core.test.mjs tests/Frontend/session.test.mjs tests/Frontend/cancellation.test.mjs
DORSIA_TEST_ORIGIN=http://127.0.0.1:4173 node --test tests/Frontend/history-live.test.mjs
make test ARGS='tests/Modules/Reservations/Feature/CancellationHistoryTest.php tests/Modules/Reservations/Feature/CancellationPreviewTest.php tests/Arch/Contracts/ReservationsContractTest.php tests/Arch/Contracts/WalletContractTest.php'
make ci
make arch
```

The history live test reads the already-cancelled records identified by `cancellation-fixtures.json`; it can be rerun without new fixtures or wallet changes. If generating fresh fixtures, complete their cancellations first. It signs in with fresh API clients, checks list/detail equality and exact early/late amounts for all three accounts, and checks unchanged wallets and restored capacity. `history-live-validation.json` records six bookings; `history-browser-validation.json` records the four CUA checks.

Browser verification on October 22, 2026: desktop1280×900 D16/D17 and mobile390×844 D18/D19 both retained the history tab and exact amounts after full refresh. Early$600/$0 and late$250/$250 appear in both cards and reopened details. Screenshots `history-*.png` show before/after refresh and each detail. Page widths matched scroll widths (1280 desktop,375 mobile content); mobile dialog388/388. Browser logs empty. Original demo member restored and history left open; viewport override reset. History reads made no booking/payment/wallet writes.

History follow-up frontend checks: **42 unit +1 live =43 passed**. Focused backend **40 tests /403 assertions**. Final `make ci`: **1,019 tests /5,071 assertions**, Pint384/PHPStan269 clean. `make arch`: **109 /914 assertions**. Commands and full evidence are in the implementation report.


## Wallet transaction history

Open **Membership → View transactions**. The `#membership/wallet` route remains selected after refresh, and authenticated history reloads from the backend. Rows show posted booking payments, cancellation refunds and credit additions, reservation references, local dates, wallet/card allocation and the signed wallet change. Refunds include forfeiture. A card-only booking has a payment total but zero wallet debit. Live records are not cached as browser receipts or recomputed from current cancellation policy.

```sh
make -f Makefile -f tests/Frontend/cancellation.mk cancellation-reload
make test ARGS='tests/Modules/Wallet/Feature/WalletTransactionsTest.php tests/Arch/Contracts/WalletContractTest.php'
node --test tests/Frontend/core.test.mjs tests/Frontend/session.test.mjs tests/Frontend/cancellation.test.mjs tests/Frontend/wallet-history.test.mjs
DORSIA_TEST_ORIGIN=http://127.0.0.1:4173 node --test tests/Frontend/wallet-history-live.test.mjs
make ci
make arch
```

The live check reuses completed cancellation/payment fixtures and the seeded demo account; no new fixtures are needed for the current database. It signs in afresh, reads every page, compares payment splits and posted refunds with the reservation API, reloads through new clients and confirms unchanged balances. It does not book or cancel. `wallet-history-live-validation.json` records five accounts without tokens/passwords. The backend suite checks authentication/ownership, two-query pages, cursor validation, insertion between pages, read-only behavior, exact microseconds, coupon/card allocations, zero-refund paths and independence from later policy changes. The sample-history suite checks persisted receipts, 20+5 pagination, empty data and duplicate-free merging. Frontend state review checked loading, initial-page retry and older-page retry paths; these failure states were not induced in the live browser.

CUA browser validation on October22,2026:

- Desktop1280×900: D16/D17 payments −$600/−$500 and refunds +$600/+$250 ($250 late forfeiture) exactly match before/after full refresh. Five records including the original credit addition; wallet $1,750.
- Mobile390×844: D18/D19 show the same respective amounts before/after full refresh. The complete history text matches, and the wallet remains $1,750.
- Mobile card account: D7 shows −$750 payment, $750 test card, Wallet$0 and +$750 returned credit after refresh. D13 shows the subsequent wallet-funded payment and refund. The in-page Refresh button also preserves exact history.
- Switching among these accounts clears the previous member’s records. Document client/scroll widths match (1265 desktop,375 mobile), and browser warning/error logs are empty. Screenshots `wallet-*.png` and `wallet-history-browser-validation.json` record the checks. The original demo member is restored on the wallet route; viewport override reset.

Latest gates: **47 unit +1 live =48 frontend checks**, focused backend **26 tests /301 assertions**, `make ci` **1,032 /5,239 assertions** (Pint390/PHPStan270 clean), `make arch` **110 /937 assertions**. A cancellation path with no ledger posting has no synthetic wallet row; its zero refund remains visible in reservation history. The screen is a posted-transaction history, not a running-balance or provider-settlement statement. Test-card behavior remains explicitly labeled.
