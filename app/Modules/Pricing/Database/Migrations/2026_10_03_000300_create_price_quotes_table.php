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
        Schema::create('price_quotes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hold_id')->unique();
            $table->unsignedBigInteger('slot_id');
            $table->unsignedBigInteger('venue_id');
            $table->smallInteger('party_size');
            $table->bigInteger('per_person_cents');
            $table->bigInteger('amount_cents');
            $table->char('currency', 3);
            $table->jsonb('inputs');
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('created_at', 6);
        });

        DB::statement('ALTER TABLE price_quotes ADD CONSTRAINT price_quotes_party_size_check CHECK (party_size >= 1)');
        DB::statement('ALTER TABLE price_quotes ADD CONSTRAINT price_quotes_per_person_check CHECK (per_person_cents > 0)');
        DB::statement('ALTER TABLE price_quotes ADD CONSTRAINT price_quotes_amount_check CHECK (amount_cents = per_person_cents * party_size)');
    }

    public function down(): void
    {
        Schema::dropIfExists('price_quotes');
    }
};
