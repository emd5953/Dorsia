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
            $table->timestampTz('opens_at', 6);
            $table->timestampTz('opened_at', 6)->nullable();
            $table->timestampTz('sold_out_at', 6)->nullable();
            $table->timestampsTz(6);
        });

        DB::statement('CREATE INDEX drops_unopened_opens_at_idx ON drops (opens_at) WHERE opened_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('drops');
    }
};
