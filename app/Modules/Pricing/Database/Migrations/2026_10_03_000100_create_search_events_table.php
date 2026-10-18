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
        Schema::create('search_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('members');
            $table->unsignedBigInteger('venue_id');
            $table->date('requested_date');
            $table->smallInteger('requested_hour');
            $table->timestampTz('requested_at', 6);
            $table->smallInteger('local_weekday');
            $table->smallInteger('party_size');
            $table->string('party_band', 8);
            $table->boolean('had_availability');
            $table->timestampTz('created_at', 6);

            $table->unique(
                ['member_id', 'venue_id', 'requested_date', 'requested_hour', 'party_size'],
                'search_events_dedupe',
            );
            $table->index('created_at', 'search_events_created_at_index');
        });

        DB::statement('ALTER TABLE search_events ADD CONSTRAINT search_events_hour_check CHECK (requested_hour BETWEEN 0 AND 23)');
        DB::statement('ALTER TABLE search_events ADD CONSTRAINT search_events_weekday_check CHECK (local_weekday BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE search_events ADD CONSTRAINT search_events_party_size_check CHECK (party_size BETWEEN 1 AND 20)');
        DB::statement(
            'CREATE INDEX search_events_unmet_index ON search_events '
            .'(venue_id, local_weekday, requested_hour, party_band, created_at) WHERE had_availability = false',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('search_events');
    }
};
