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
        Schema::create('demand_signals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('venue_id');
            $table->smallInteger('weekday');
            $table->smallInteger('hour');
            $table->string('party_band', 8);
            $table->integer('sellout_seconds_p50')->nullable();
            $table->integer('unmet_searches')->default(0);
            $table->integer('bid_count')->default(0);
            $table->timestampTz('computed_at');

            $table->unique(['venue_id', 'weekday', 'hour', 'party_band']);
        });

        DB::statement('ALTER TABLE demand_signals ADD CONSTRAINT demand_signals_weekday_check CHECK (weekday BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE demand_signals ADD CONSTRAINT demand_signals_hour_check CHECK (hour BETWEEN 0 AND 23)');
        DB::statement('ALTER TABLE demand_signals ADD CONSTRAINT demand_signals_unmet_check CHECK (unmet_searches >= 0)');
        DB::statement('ALTER TABLE demand_signals ADD CONSTRAINT demand_signals_bid_count_check CHECK (bid_count >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_signals');
    }
};
