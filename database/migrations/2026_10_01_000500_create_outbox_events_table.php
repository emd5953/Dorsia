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
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->string('topic', 64);
            $table->jsonb('payload');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('published_at')->nullable();

            $table->index('published_at');
        });

        DB::statement('CREATE INDEX outbox_events_unpublished_idx ON outbox_events (id) WHERE published_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
