<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail and transactional outbox. Both are written in the same transaction
 * as the state change they describe; nothing is broadcast before commit (SPEC-003 OR-07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestampTz('occurred_at');
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('campaign_id')->nullable()->constrained('campaigns')->restrictOnDelete();
            $table->string('action', 96);
            $table->string('subject_type', 64);
            $table->uuid('subject_id');
            // Never contains secrets (tokens, passwords) or full private sheets.
            $table->jsonb('metadata')->default('{}');
            $table->index(['campaign_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });
        DB::unprepared('CREATE TRIGGER audit_events_append_only BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION novtt_reject_mutation()');

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->nullable()->constrained('campaigns')->restrictOnDelete();
            $table->string('aggregate_type', 64);
            $table->uuid('aggregate_id');
            $table->string('event_type', 96);
            $table->unsignedSmallInteger('schema_version');
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
        });
        DB::statement('CREATE INDEX outbox_events_pending ON outbox_events (occurred_at) WHERE published_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('audit_events');
    }
};
