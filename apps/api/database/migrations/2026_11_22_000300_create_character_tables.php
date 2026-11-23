<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personal character library, campaign instances, NPCs, sheet revisions and grants (S001-04, S001-05).
 *
 * The library entry and the campaign instance are distinct entities: linking a library character
 * to a campaign creates a copy pinned to the campaign's preset version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_library', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('preset_version_id')->nullable()->constrained('preset_versions')->restrictOnDelete();
            $table->string('name', 120);
            $table->jsonb('sheet')->default('{}');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['owner_user_id', 'archived_at']);
        });

        Schema::create('campaign_characters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->foreignUuid('library_character_id')->nullable()->constrained('character_library')->nullOnDelete();
            $table->foreignUuid('player_user_id')->constrained('users')->restrictOnDelete();
            // Temporary custody by a master (e.g. absent player). Always audited.
            $table->foreignUuid('custodian_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('preset_version_id')->constrained('preset_versions')->restrictOnDelete();
            $table->string('name', 120);
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'archived'])->default('draft');
            // Canonical (official) snapshot. Mutated only by the atomic apply service.
            $table->jsonb('sheet')->default('{}');
            $table->unsignedInteger('sheet_revision')->default(0);
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['campaign_id', 'status']);
            $table->index('player_user_id');
        });
        DB::statement('CREATE UNIQUE INDEX campaign_characters_library_once ON campaign_characters (campaign_id, library_character_id) WHERE library_character_id IS NOT NULL');
        DB::statement("ALTER TABLE campaign_characters ADD CONSTRAINT campaign_characters_approval CHECK ((approved_at IS NULL) = (approved_by IS NULL))");
        DB::statement("ALTER TABLE campaign_characters ADD CONSTRAINT campaign_characters_archived_at CHECK ((status = 'archived') = (archived_at IS NOT NULL))");

        // Immutable history of official sheets. Revision 0 is the empty initial state and is not stored.
        Schema::create('character_sheet_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_character_id')->constrained('campaign_characters')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->jsonb('sheet');
            $table->uuid('change_request_id')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['campaign_character_id', 'revision']);
        });
        DB::statement('ALTER TABLE character_sheet_revisions ADD CONSTRAINT character_sheet_revisions_positive CHECK (revision >= 1)');
        DB::unprepared('CREATE TRIGGER character_sheet_revisions_append_only BEFORE UPDATE OR DELETE ON character_sheet_revisions FOR EACH ROW EXECUTE FUNCTION novtt_reject_mutation()');

        // Budget grants (initial budget, bonus points, XP). Granting never spends; spending needs a reviewed change.
        Schema::create('character_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_character_id')->constrained('campaign_characters')->restrictOnDelete();
            $table->string('budget_id', 64);
            $table->integer('amount');
            $table->string('reason', 1000)->nullable();
            $table->foreignUuid('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->index(['campaign_character_id', 'budget_id']);
        });
        DB::statement('ALTER TABLE character_grants ADD CONSTRAINT character_grants_amount CHECK (amount <> 0 AND amount BETWEEN -10000 AND 10000)');
        DB::unprepared('CREATE TRIGGER character_grants_append_only BEFORE UPDATE OR DELETE ON character_grants FOR EACH ROW EXECUTE FUNCTION novtt_reject_mutation()');

        Schema::create('npc_library', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('preset_version_id')->nullable()->constrained('preset_versions')->restrictOnDelete();
            $table->string('name', 120);
            $table->jsonb('sheet')->default('{}');
            $table->text('private_notes')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['owner_user_id', 'archived_at']);
        });

        // Sanitized per-campaign copy: private notes of the library entry are never copied.
        Schema::create('campaign_npcs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->foreignUuid('source_npc_id')->nullable()->constrained('npc_library')->nullOnDelete();
            $table->foreignUuid('preset_version_id')->constrained('preset_versions')->restrictOnDelete();
            $table->string('name', 120);
            $table->jsonb('sheet')->default('{}');
            $table->unsignedInteger('sheet_revision')->default(0);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['campaign_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_npcs');
        Schema::dropIfExists('npc_library');
        Schema::dropIfExists('character_grants');
        Schema::dropIfExists('character_sheet_revisions');
        Schema::dropIfExists('campaign_characters');
        Schema::dropIfExists('character_library');
    }
};
