<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drafts, change requests, typed operations and per-operation decisions (S001-05).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Player workspace, separate from the official sheet. One draft per author per character.
        Schema::create('character_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_character_id')->constrained('campaign_characters')->restrictOnDelete();
            $table->foreignUuid('author_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('base_sheet_revision');
            $table->unsignedInteger('draft_version')->default(1);
            // novtt.sheet-change-set/1 document.
            $table->jsonb('change_set');
            $table->timestampsTz();
            $table->unique(['campaign_character_id', 'author_user_id']);
        });

        Schema::create('change_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_character_id')->constrained('campaign_characters')->restrictOnDelete();
            $table->foreignUuid('author_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', [
                'submitted', 'in_review', 'awaiting_resolution',
                'applied', 'rejected', 'withdrawn', 'superseded',
            ]);
            $table->unsignedInteger('base_sheet_revision');
            $table->unsignedInteger('draft_version');
            $table->unsignedInteger('review_version')->default(1);
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->unsignedInteger('resulting_sheet_revision')->nullable();
            $table->foreignUuid('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at');
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampsTz();
            $table->unique(['author_user_id', 'idempotency_key']);
            $table->index(['campaign_character_id', 'status']);
        });
        // SPEC-SPRINT-001 rule 6: at most one active proposal per campaign character.
        DB::statement("CREATE UNIQUE INDEX change_requests_one_active ON change_requests (campaign_character_id) WHERE status IN ('submitted', 'in_review', 'awaiting_resolution')");
        DB::statement("ALTER TABLE change_requests ADD CONSTRAINT change_requests_finalization CHECK ((status IN ('applied', 'rejected')) = (finalized_at IS NOT NULL AND finalized_by IS NOT NULL))");
        DB::statement("ALTER TABLE change_requests ADD CONSTRAINT change_requests_applied_revision CHECK ((status = 'applied') = (resulting_sheet_revision IS NOT NULL))");

        Schema::create('change_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('change_request_id')->constrained('change_requests')->restrictOnDelete();
            // Stable id chosen by the client inside the change set, used in validation results.
            $table->string('operation_key', 64);
            $table->unsignedSmallInteger('position');
            $table->enum('type', ['set_field', 'unset_field', 'add_item', 'set_item_field', 'remove_item', 'reorder_items']);
            $table->string('path', 512);
            $table->string('section_id', 64);
            $table->jsonb('payload');
            $table->timestampTz('created_at');
            $table->unique(['change_request_id', 'position']);
            $table->unique(['change_request_id', 'operation_key']);
        });
        DB::unprepared('CREATE TRIGGER change_operations_append_only BEFORE UPDATE OR DELETE ON change_operations FOR EACH ROW EXECUTE FUNCTION novtt_reject_mutation()');

        // Provisional decisions are editable; once is_final the row is frozen.
        Schema::create('operation_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('change_operation_id')->unique()->constrained('change_operations')->restrictOnDelete();
            $table->foreignUuid('change_request_id')->constrained('change_requests')->restrictOnDelete();
            $table->enum('decision', ['approved', 'rejected']);
            $table->text('reason')->nullable();
            $table->boolean('is_final')->default(false);
            $table->unsignedInteger('review_version');
            $table->foreignUuid('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at');
            $table->timestampsTz();
            $table->index('change_request_id');
        });
        DB::statement("ALTER TABLE operation_decisions ADD CONSTRAINT operation_decisions_rejection_reason CHECK ((decision = 'rejected') = (reason IS NOT NULL) AND (reason IS NULL OR char_length(btrim(reason)) BETWEEN 10 AND 1000))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION novtt_operation_decision_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.is_final THEN
                    RAISE EXCEPTION 'novtt.immutable_row: final decisions cannot change'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER operation_decisions_guard BEFORE UPDATE OR DELETE ON operation_decisions
                FOR EACH ROW EXECUTE FUNCTION novtt_operation_decision_guard();
        SQL);

        Schema::table('character_sheet_revisions', function (Blueprint $table): void {
            $table->foreign('change_request_id')->references('id')->on('change_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('character_sheet_revisions', function (Blueprint $table): void {
            $table->dropForeign(['change_request_id']);
        });
        Schema::dropIfExists('operation_decisions');
        DB::unprepared('DROP FUNCTION IF EXISTS novtt_operation_decision_guard()');
        Schema::dropIfExists('change_operations');
        Schema::dropIfExists('change_requests');
        Schema::dropIfExists('character_drafts');
    }
};
