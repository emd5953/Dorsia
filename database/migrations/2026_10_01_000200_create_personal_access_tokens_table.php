<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at', 6)->nullable();
            $table->timestampTz('expires_at', 6)->nullable()->index();
            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
