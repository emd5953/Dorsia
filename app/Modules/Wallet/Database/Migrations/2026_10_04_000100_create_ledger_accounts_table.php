<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type', 16);
            $table->unsignedBigInteger('owner_id');
            $table->string('kind', 16);
            $table->char('currency', 3);
            $table->bigInteger('balance_cents')->default(0);
            $table->timestampsTz(6);

            $table->unique(['owner_type', 'owner_id', 'kind', 'currency'], 'ledger_accounts_owner_kind_currency_unique');
        });

        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_owner_type_valid CHECK (owner_type IN ('member', 'venue', 'system'))");
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_kind_valid CHECK (kind IN ('cash', 'credit', 'coupon', 'venue_payable', 'platform'))");
        DB::statement(<<<'SQL'
            ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_owner_kind CHECK (
                (owner_type = 'member' AND kind IN ('credit', 'coupon'))
                OR (owner_type = 'venue' AND kind = 'venue_payable')
                OR (owner_type = 'system' AND kind IN ('cash', 'platform'))
            )
            SQL);
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_member_non_negative CHECK (owner_type <> 'member' OR balance_cents >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
