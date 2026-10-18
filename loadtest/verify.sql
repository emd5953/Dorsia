\set ON_ERROR_STOP on
SELECT set_config('dorsia.slot_id', :'slot_id', false);
DO $$
DECLARE
    confirmed_count bigint;
    booked integer;
    held integer;
    capacity integer;
    bad bigint;
BEGIN
    SELECT count(*) INTO confirmed_count FROM reservations WHERE slot_id = current_setting('dorsia.slot_id')::bigint AND status = 'confirmed';
    IF confirmed_count <> 50 THEN RAISE EXCEPTION 'expected 50 confirmed reservations, got %', confirmed_count; END IF;

    SELECT booked_count, held_count, slots.capacity INTO booked, held, capacity FROM slots WHERE id = current_setting('dorsia.slot_id')::bigint;
    IF booked <> 50 OR held + booked > capacity THEN
        RAISE EXCEPTION 'slot counters invalid: booked %, held %, capacity %', booked, held, capacity;
    END IF;
    SELECT count(*) INTO bad FROM (
        SELECT hold_id FROM reservations WHERE slot_id = current_setting('dorsia.slot_id')::bigint GROUP BY hold_id HAVING count(*) > 1
    ) duplicates;
    IF bad <> 0 THEN RAISE EXCEPTION 'duplicate reservations for a hold: %', bad; END IF;
    SELECT count(*) INTO bad FROM (
        SELECT member_id FROM reservations WHERE slot_id = current_setting('dorsia.slot_id')::bigint GROUP BY member_id HAVING count(*) > 1
    ) duplicates;
    IF bad <> 0 THEN RAISE EXCEPTION 'members with multiple reservations: %', bad; END IF;

    SELECT count(*) INTO bad FROM (
        SELECT hold_id FROM payments GROUP BY hold_id HAVING count(*) > 1
    ) duplicates;
    IF bad <> 0 THEN RAISE EXCEPTION 'duplicate payments by hold: %', bad; END IF;
    SELECT count(*) INTO bad FROM payments WHERE status IN ('authorized', 'captured') AND reservation_id IS NULL;
    IF bad <> 0 THEN RAISE EXCEPTION 'orphan authorized or captured payments: %', bad; END IF;
    SELECT count(*) INTO bad
      FROM reservations r
      WHERE r.slot_id = current_setting('dorsia.slot_id')::bigint AND r.card_cents > 0
        AND (SELECT count(*) FROM payments p WHERE p.reservation_id = r.id AND p.status = 'captured' AND p.amount_cents = r.card_cents) <> 1;
    IF bad <> 0 THEN RAISE EXCEPTION 'card reservations without exactly one matching capture: %', bad; END IF;

    SELECT coalesce(sum(amount_cents), 0) INTO bad FROM ledger_entries;
    IF bad <> 0 THEN RAISE EXCEPTION 'ledger total is not zero: %', bad; END IF;
    SELECT count(*) INTO bad FROM (
        SELECT transaction_id FROM ledger_entries GROUP BY transaction_id HAVING sum(amount_cents) <> 0
    ) unbalanced;
    IF bad <> 0 THEN RAISE EXCEPTION 'unbalanced ledger transactions: %', bad; END IF;
    SELECT count(*) INTO bad FROM ledger_transactions
      WHERE type = 'booking' AND reference_type = 'hold'
        AND reference_id IN (SELECT id FROM holds WHERE slot_id = current_setting('dorsia.slot_id')::bigint);
    IF bad <> 50 THEN RAISE EXCEPTION 'expected 50 booking ledger transactions, got %', bad; END IF;
END $$;

SELECT 'drop verification passed' AS result;
