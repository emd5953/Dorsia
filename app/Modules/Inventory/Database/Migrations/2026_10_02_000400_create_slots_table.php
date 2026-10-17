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
        Schema::create('slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues');
            $table->foreignId('drop_id')->nullable()->constrained('drops');
            $table->timestampTz('starts_at', 6);
            $table->integer('capacity');
            $table->integer('held_count')->default(0);
            $table->integer('booked_count')->default(0);
            $table->smallInteger('min_party_size');
            $table->smallInteger('max_party_size');
            $table->timestampTz('released_at', 6);
            $table->timestampTz('sold_out_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['venue_id', 'starts_at', 'min_party_size', 'max_party_size']);
            $table->index(['venue_id', 'starts_at']);
            $table->index('drop_id');
            $table->index('updated_at');
        });

        DB::statement('ALTER TABLE slots ADD CONSTRAINT slots_capacity_check CHECK (capacity > 0)');
        DB::statement('ALTER TABLE slots ADD CONSTRAINT slots_held_count_check CHECK (held_count >= 0)');
        DB::statement('ALTER TABLE slots ADD CONSTRAINT slots_booked_count_check CHECK (booked_count >= 0)');
        DB::statement('ALTER TABLE slots ADD CONSTRAINT slots_booked_within_capacity CHECK (booked_count <= capacity)');
        DB::statement('ALTER TABLE slots ADD CONSTRAINT slots_min_party_size_check CHECK (min_party_size >= 1)');
        DB::statement('ALTER TABLE slots ADD CONSTRAINT slots_max_party_size_check CHECK (max_party_size >= min_party_size)');
        DB::statement('ALTER TABLE slots ADD CONSTRAINT slots_not_overbooked CHECK (held_count + booked_count <= capacity)');
    }

    public function down(): void
    {
        Schema::dropIfExists('slots');
    }
};
