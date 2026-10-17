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
        Schema::create('holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('slot_id')->constrained('slots');
            $table->foreignId('member_id')->constrained('members');
            $table->smallInteger('party_size');
            $table->string('status', 16);
            $table->string('idempotency_key', 255)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('expiring_notified_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->string('close_reason', 32)->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE holds ADD CONSTRAINT holds_party_size_check CHECK (party_size >= 1)');
        DB::statement("ALTER TABLE holds ADD CONSTRAINT holds_status_check CHECK (status IN ('active', 'converted', 'released', 'expired'))");
        DB::statement("ALTER TABLE holds ADD CONSTRAINT holds_closed_at_check CHECK ((status = 'active') = (closed_at IS NULL))");
        DB::statement("CREATE UNIQUE INDEX holds_one_active_per_member_slot ON holds (slot_id, member_id) WHERE status = 'active'");
        DB::statement("CREATE INDEX holds_active_expires_at_idx ON holds (expires_at) WHERE status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('holds');
    }
};
