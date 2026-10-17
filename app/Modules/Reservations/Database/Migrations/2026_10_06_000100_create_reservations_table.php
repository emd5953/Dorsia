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
        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('members');
            $table->unsignedBigInteger('hold_id')->unique();
            $table->unsignedBigInteger('slot_id');
            $table->unsignedBigInteger('venue_id');
            $table->string('venue_timezone', 64);
            $table->timestampTz('starts_at', 6);
            $table->smallInteger('party_size');
            $table->bigInteger('min_spend_cents');
            $table->bigInteger('coupon_cents')->default(0);
            $table->bigInteger('credit_cents')->default(0);
            $table->bigInteger('card_cents')->default(0);
            $table->char('currency', 3);
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('status', 24);
            $table->string('idempotency_key', 255)->unique();
            $table->timestampTz('confirmed_at', 6);
            $table->timestampTz('cancelled_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->index(['member_id', 'starts_at']);
            $table->index('slot_id');
        });

        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_party_size_check CHECK (party_size >= 1)');
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_min_spend_check CHECK (min_spend_cents > 0)');
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_amounts_check CHECK (coupon_cents >= 0 AND credit_cents >= 0 AND card_cents >= 0)');
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_split_check CHECK (coupon_cents + credit_cents + card_cents = min_spend_cents)');
        // PostgreSQL evaluates check constraints by name; keep the status constraint first
        // so an unknown status is rejected by its own contract constraint.
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_a_status_check CHECK (status IN ('confirmed', 'cancelled_early', 'cancelled_late'))");
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_cancelled_at_check CHECK ((status = 'confirmed') = (cancelled_at IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
