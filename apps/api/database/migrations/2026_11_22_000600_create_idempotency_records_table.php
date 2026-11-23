<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stored outcomes of idempotent commands, scoped by actor and operation (ADR 0003): the same
 * actor + scope + key + payload returns the original response; another payload is a conflict.
 * Written in the same transaction as the command itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope', 96);
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->jsonb('response_body');
            $table->timestampTz('created_at');
            $table->unique(['actor_user_id', 'scope', 'idempotency_key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
