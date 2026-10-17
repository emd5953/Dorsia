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
        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained('ledger_transactions');
            $table->foreignId('account_id')->constrained('ledger_accounts');
            // Positive = debit, negative = credit (D10).
            $table->bigInteger('amount_cents');
            $table->timestampTz('created_at', 6);

            $table->index('account_id');
            $table->index('transaction_id');
        });

        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_non_zero CHECK (amount_cents <> 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
