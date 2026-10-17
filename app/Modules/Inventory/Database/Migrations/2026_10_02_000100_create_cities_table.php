<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->char('country_code', 2);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
