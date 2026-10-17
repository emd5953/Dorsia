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
        Schema::create('drops', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues');
            $table->timestampTz('opens_at');
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('sold_out_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement('CREATE INDEX drops_unopened_opens_at_idx ON drops (opens_at) WHERE opened_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('drops');
    }
};
