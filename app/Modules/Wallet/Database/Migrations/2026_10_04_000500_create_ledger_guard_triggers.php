<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Entries may only be added by the database transaction that inserted their ledger transaction. Without this,
        // a balanced pair could be appended to an already committed transaction and pass the zero-sum trigger.
        // A parent xmin that is still in progress belongs to this transaction (including a savepoint).
        // Rows from any previously committed transaction cannot receive additional entries.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_assert_entry_parent_is_current() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM ledger_transactions t
                     WHERE t.id = NEW.transaction_id
                       AND (
                           t.xmin = pg_current_xact_id()::xid
                           OR pg_xact_status(t.xmin::text::xid8) = 'in progress'
                       )
                ) THEN
                    RAISE EXCEPTION 'ledger entries can only be added in the database transaction that created ledger transaction %', NEW.transaction_id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_entries_parent_is_current
                BEFORE INSERT ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_assert_entry_parent_is_current()
            SQL);

        // Only the cached projection may change on an account; its identity is fixed once entries can point at it.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_assert_account_identity_unchanged() RETURNS trigger AS $$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id
                    OR NEW.owner_type IS DISTINCT FROM OLD.owner_type
                    OR NEW.owner_id IS DISTINCT FROM OLD.owner_id
                    OR NEW.kind IS DISTINCT FROM OLD.kind
                    OR NEW.currency IS DISTINCT FROM OLD.currency
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'ledger account identity is immutable: only balance_cents and updated_at may change';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_accounts_identity_immutable
                BEFORE UPDATE ON ledger_accounts
                FOR EACH ROW EXECUTE FUNCTION ledger_assert_account_identity_unchanged()
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_accounts_identity_immutable ON ledger_accounts');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_parent_is_current ON ledger_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_assert_account_identity_unchanged()');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_assert_entry_parent_is_current()');
    }
};
