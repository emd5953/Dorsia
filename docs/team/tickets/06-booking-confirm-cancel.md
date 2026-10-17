# 06 — Booking: quote, confirm and cancel

| | |
| --- | --- |
| Owner | Booking |
| Depends on | 02, 03, 04, 05 merged into `build/v1` |
| Parallel with | 07 |
| Blocks | 08 |
| Branch | `feat/booking-confirm-cancel` off `build/v1` (after the four merges) |
| Worktree env | `bin/worktree-env 06` → `dorsia-06`, ports 8600 / 8680 / 6032 / 6979 |
| Build plan | §3 (`reservations`), §4 steps 4–6 and the "release hold" edge, §5 cancel 8+ hours / inside 8 hours, spend order, §6 `reservation.confirmed`, §9 step 3 (confirm half) |
| Contracts | C§3 Reservations table, C§5.6, C§6.2, C§6.3, C§7 `reservation.confirmed`, C§8 reservation endpoints and hold `quote`, C§9 Reservations codes, C§12 `reservations.*`, D7, D8, D14, D15 |

## Files you may touch

- `app/Modules/Reservations/**`
- `app/Modules/Inventory/**` — bug fixes, plus the gate-at-0 overdue check (C§6.1 step 3, D19) if ticket 02 did not ship it; no signature changes
- `tests/Modules/Reservations/**`, `tests/Modules/Inventory/**` (fixes), `tests/Arch/Contracts/ReservationsContractTest.php`
- `tests/Modules/Reservations/Feature/EndToEndTest.php` (new)

Need a change in Pricing, Wallet or Payments? Raise it with the architect; do not edit those modules.

## Non-goals

Staff cancel override, compensation, overage settlement, no-show handling, reservation modification, `completed`/`no_show` statuses, card refunds, push notifications, any broadcasting (07 publishes the outbox).

## Work

1. Migration (prefix `2026_10_06_`): `reservations` per C§3.
2. Model + factory (factory goes through `ConfirmReservation` or builds consistent cross-module fixtures via public actions; never fakes a ledger).
3. `PlaceHold` now passes the `$inTransaction` callback that calls `Pricing\QuoteHold` with a `QuoteHoldInput` built from `HoldData`; `HoldView.quote` is always set; `HoldResource` renders `quote`.
4. `ConfirmReservation` exactly per C§6.2; `CancelReservation` per C§6.3; `GetReservation`, `ListReservations`; DTOs, enum, exceptions per C§5.6.
5. Endpoints: `POST /api/reservations`, `GET /api/reservations`, `GET /api/reservations/{reservation}`, `POST /api/reservations/{reservation}/cancel` per C§8 (`auth:sanctum`; `idempotent` on both POSTs).
6. `config.php`: `reservations.cancel_window_hours`.

## Acceptance criteria

- [ ] `POST /api/holds` returns a `quote` whose `amount_cents` equals the `price_quotes` row; the hold and the quote are created in one transaction (a forced failure inside `QuoteHold` leaves no hold, `held_count` unchanged and the gate unit released).
- [ ] Confirm, card only (`tok_ok`, empty wallet): 201 `Reservation`; payment `authorized` then `captured` by the job; ledger booking transaction card clearing +amount / venue_payable −amount; `held_count −1`, `booked_count +1`; outbox has `slot.booked` and `reservation.confirmed` with C§7 payloads.
- [ ] Confirm, wallet only (credit ≥ amount, `card_token` null): no payment row, credit reduced by amount, `wallet.updated` row written.
- [ ] Confirm, wallet + card: credit 20000 on a 75000 quote → `payment.credit_cents = 20000`, `card_cents = 55000`, gateway authorized exactly 55000.
- [ ] Card needed but `card_token` null → 422 `card_required` with `details.card_cents`; hold still active.
- [ ] Decline → 402 `payment_failed`; hold `released`, `held_count` restored, gate +1, `slot.released` with `reason: "payment_failed"`; no reservation, no ledger rows.
- [ ] Timeout → 504 `payment_timeout`; hold released with `reason: "payment_timeout"`.
- [ ] Hold expired before confirm (at `expires_at` exactly) → 409 `hold_expired`; no payment authorized when detected in step 2; if expiry is detected inside the transaction, the authorization is voided and the hold expired. (Test seam: mock `Payments\Actions\AuthorizePayment` with a partial mock that calls the real `handle()` and then `$this->travelTo($hold->expiresAt)` before returning; action classes are non-final for this reason.)
- [ ] Confirm someone else's hold → 404 `not_found`.
- [ ] Wallet balance drained between plan and post → 409 `wallet_balance_changed`, authorization voided, hold still active.
- [ ] Confirm replay (same key/body) → identical 201, `Idempotent-Replayed: true`, no new reservation, payment, ledger or outbox rows; different body → 422 `idempotency_mismatch`.
- [ ] Second confirm of a converted hold with a different key → 409 `hold_not_active`.
- [ ] Cancel at `starts_at − 8h` exactly → early: status `cancelled_early`, refund `credit_cents = min_spend`, `forfeited_cents = 0`; member credit +min_spend; `booked_count −1`; `slot.released` with `reason: "cancelled"`; gate +1.
- [ ] Cancel at `starts_at − 8h + 1s` → late: `cancelled_late`, member credit +50 %, platform +25 %, venue_payable debited 75 %; response `forfeited_cents = min_spend − credit`.
- [ ] Cancel a card-paid reservation → refund still goes to wallet credit; payment stays `captured`.
- [ ] Cancel at or after `starts_at` → 409 `reservation_started`; cancel an already-cancelled reservation with a new key → 409 `reservation_not_cancellable`; cancel replay with the same key → identical 200.
- [ ] `reservations.cancel_window_hours = 4` moves the boundary without code changes.
- [ ] `GET /api/reservations` lists only the caller's reservations, newest `starts_at` first.
- [ ] Drop-in: slot full with the gate at 0 and one hold overdue but unswept → a new member's `POST /api/holds` succeeds without running the sweeper; a second `sold_out` attempt within 5 s on a truly full slot issues no overdue query (guard key present).
- [ ] `HoldView::$quote` typed `?QuoteData` (C§5.6) and always non-null after this ticket.
- [ ] End-to-end test (one Pest test, real HTTP through the kernel): search availability → hold → confirm with wallet + card → cancel early → wallet shows the refund → hold again → confirm with wallet only → cancel late → final balances match §5 (member credit, venue_payable, platform, card clearing) and `wallet:reconcile` exits 0.
- [ ] Gates: `make ci`, `make arch`, `ReservationsContractTest`.

## Required tests

- Feature: one per acceptance bullet; `EndToEndTest`.
- Concurrency (≥ 20 processes):
  - **Drop in miniature:** 30 members race one slot with `capacity = 5` through hold → confirm (`tok_ok`), each retrying both calls once with the same keys → exactly 5 reservations, 5 captured payments, 0 unattached authorized payments, `booked_count = 5`, `held_count = 0`, ledger global sum 0, every loser got 409 `sold_out`, zero 5xx.
  - 20 parallel confirms of the **same hold** with different keys → exactly one reservation, one payment row, one booking ledger transaction; others 409 (`hold_not_active` / `payment_in_progress`); no captured payment without a reservation.
  - 20 parallel cancels of one reservation with different keys → exactly one refund transaction; one 200, others 409 `reservation_not_cancellable`.
  - Confirm racing the expiry sweeper at `expires_at − 1s` (20 iterations) → each hold ends either converted with a reservation or expired with no reservation and no captured payment; slot counts consistent.
- `tests/Arch/Contracts/ReservationsContractTest.php`.

## Eval agent will check

- `ConfirmReservation` matches C§6.2 step by step: no gateway call inside a transaction, void on every post-authorize failure, hold release on decline/timeout only.
- Each §5 cancellation rule with the 8-hour boundary to the second, and the config-driven split.
- Reservations reaches other modules only through public actions (arch + `grep` for `DB::table('slots'|'holds'|'ledger_|'payments'|'price_quotes')`).
- Ledger state after every scenario by running `wallet:reconcile`.
- Concurrency suite run 3 times; any flake fails the round.
