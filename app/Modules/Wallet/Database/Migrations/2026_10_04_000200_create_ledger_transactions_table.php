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
        Schema::create('ledger_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32);
            $table->string('reference_type', 32);
            $table->unsignedBigInteger('reference_id');
            $table->string('idempotency_key', 255)->unique();
            $table->char('currency', 3);
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at', 6);

            $table->index(['reference_type', 'reference_id']);
        });

        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_type_valid CHECK (type IN ('booking', 'cancel_early', 'cancel_late', 'goodwill_credit'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_transactions');
    }
};
