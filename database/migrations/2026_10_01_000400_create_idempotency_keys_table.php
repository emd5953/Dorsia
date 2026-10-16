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
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('members');
            $table->string('key', 200);
            $table->string('method', 8);
            $table->string('path', 255);
            $table->char('request_hash', 64);
            $table->string('status', 16);
            $table->smallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('locked_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['member_id', 'key']);
            $table->index('created_at');
        });

        DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_status_check CHECK (status IN ('processing', 'completed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
