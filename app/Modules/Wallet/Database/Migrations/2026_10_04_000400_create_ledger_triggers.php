<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fires once per inserted entry, at commit (deferred), and checks the whole transaction.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_assert_balanced() RETURNS trigger AS $$
            DECLARE
                entry_sum bigint;
                entry_count bigint;
                currency_mismatches bigint;
            BEGIN
                SELECT COALESCE(SUM(e.amount_cents), 0),
                       COUNT(*),
                       COUNT(*) FILTER (WHERE a.currency <> t.currency)
                  INTO entry_sum, entry_count, currency_mismatches
                  FROM ledger_entries e
                  JOIN ledger_accounts a ON a.id = e.account_id
                  JOIN ledger_transactions t ON t.id = e.transaction_id
                 WHERE e.transaction_id = NEW.transaction_id;

                IF entry_sum <> 0 THEN
                    RAISE EXCEPTION 'ledger transaction % does not balance', NEW.transaction_id
                        USING DETAIL = format('entries sum to %s', entry_sum);
                END IF;

                IF entry_count < 2 THEN
                    RAISE EXCEPTION 'ledger transaction % does not balance', NEW.transaction_id
                        USING DETAIL = format('%s entries, at least 2 required', entry_count);
                END IF;

                IF currency_mismatches > 0 THEN
                    RAISE EXCEPTION 'ledger transaction % does not balance', NEW.transaction_id
                        USING DETAIL = format('%s entries use an account in another currency', currency_mismatches);
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER ledger_entries_balanced
                AFTER INSERT ON ledger_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION ledger_assert_balanced()
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_assert_has_entries() RETURNS trigger AS $$
            DECLARE
                entry_count bigint;
            BEGIN
                SELECT COUNT(*) INTO entry_count FROM ledger_entries WHERE transaction_id = NEW.id;

                IF entry_count < 2 THEN
                    RAISE EXCEPTION 'ledger transaction % has % entries, at least 2 required', NEW.id, entry_count;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER ledger_transactions_have_entries
                AFTER INSERT ON ledger_transactions
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION ledger_assert_has_entries()
            SQL);

        // TRUNCATE is deliberately not blocked so concurrency tests can truncate.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_reject_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'ledger is append-only';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        foreach (['ledger_entries', 'ledger_transactions'] as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_append_only
                    BEFORE UPDATE OR DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION ledger_reject_change()
                SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_transactions_append_only ON ledger_transactions');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_append_only ON ledger_entries');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_transactions_have_entries ON ledger_transactions');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_balanced ON ledger_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_reject_change()');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_assert_has_entries()');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_assert_balanced()');
    }
};
