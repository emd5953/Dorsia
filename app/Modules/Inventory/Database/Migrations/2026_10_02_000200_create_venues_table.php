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
        Schema::create('venues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('city_id')->constrained('cities');
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->string('timezone', 64);
            $table->string('status', 16)->default('active');
            $table->bigInteger('base_per_person_cents');
            $table->char('currency', 3)->default('USD');
            $table->timestampsTz(6);

            $table->index('city_id');
        });

        DB::statement("ALTER TABLE venues ADD CONSTRAINT venues_timezone_check CHECK (timezone <> '')");
        DB::statement("ALTER TABLE venues ADD CONSTRAINT venues_status_check CHECK (status IN ('active', 'inactive'))");
        DB::statement('ALTER TABLE venues ADD CONSTRAINT venues_base_per_person_cents_check CHECK (base_per_person_cents > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('venues');
    }
};
