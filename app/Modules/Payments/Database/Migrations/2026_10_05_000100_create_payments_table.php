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
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('members');
            $table->unsignedBigInteger('hold_id');
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->string('provider', 32)->default('fake');
            $table->string('provider_ref', 255)->nullable()->unique();
            $table->string('idempotency_key', 255)->unique();
            $table->bigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('status', 16);
            $table->string('decline_code', 64)->nullable();
            $table->timestampsTz();

            $table->index('reservation_id');
            $table->index('hold_id');
        });

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_cents_check CHECK (amount_cents > 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending', 'authorized', 'captured', 'failed', 'timed_out', 'refunded', 'capture_failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
