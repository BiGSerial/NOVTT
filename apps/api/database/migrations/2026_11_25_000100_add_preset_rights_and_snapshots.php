<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preset engine Sprint 001 (ADR 0012).
 *
 * - preset_versions.rights_status: distribution rights, a separate axis from the technical
 *   lifecycle in `status`. Mutable (rights can be quarantined after publication) and audited by
 *   the team command. Rows imported before this migration get 'draft' unless their manifest/2
 *   declares a status: no rights are assumed silently.
 * - preset_versions.compendium: optional novtt.compendium/1 part, immutable like the other parts.
 * - game_sessions.preset_version_id: the preset version pinned by the campaign when the session
 *   started (session snapshot), so a later upgrade never changes what a past session used.
 */
return new class extends Migration
{
    private const RIGHTS = ['draft', 'rights_review', 'approved', 'quarantined', 'published', 'retired'];

    public function up(): void
    {
        Schema::table('preset_versions', function (Blueprint $table): void {
            $table->string('rights_status', 16)->default('draft');
            $table->timestampTz('rights_updated_at')->nullable();
            $table->jsonb('compendium')->nullable();
        });
        DB::statement("ALTER TABLE preset_versions ADD CONSTRAINT preset_versions_rights_status CHECK (rights_status IN ('" . implode("','", self::RIGHTS) . "'))");
        DB::statement("UPDATE preset_versions SET rights_status = manifest->'license'->>'rights_status' WHERE manifest->>'contract' = 'novtt.preset-manifest/2' AND manifest->'license'->>'rights_status' IN ('" . implode("','", self::RIGHTS) . "')");
        DB::statement('CREATE INDEX preset_versions_catalogue ON preset_versions (status, rights_status)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION novtt_preset_version_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                lifecycle text[] := ARRAY['draft','structurally_valid','engine_compatible','reviewed','published'];
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'novtt.immutable_row: preset versions cannot be deleted'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF NEW.definition_id IS DISTINCT FROM OLD.definition_id
                    OR NEW.version IS DISTINCT FROM OLD.version
                    OR NEW.content_hash IS DISTINCT FROM OLD.content_hash
                    OR NEW.manifest IS DISTINCT FROM OLD.manifest
                    OR NEW.sheet_schema IS DISTINCT FROM OLD.sheet_schema
                    OR NEW.sheet_ui IS DISTINCT FROM OLD.sheet_ui
                    OR NEW.rules IS DISTINCT FROM OLD.rules
                    OR NEW.compendium IS DISTINCT FROM OLD.compendium
                    OR NEW.engine_version IS DISTINCT FROM OLD.engine_version
                    OR NEW.imported_by IS DISTINCT FROM OLD.imported_by THEN
                    RAISE EXCEPTION 'novtt.immutable_row: preset version content is immutable'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF array_position(lifecycle, NEW.status::text) < array_position(lifecycle, OLD.status::text) THEN
                    RAISE EXCEPTION 'novtt.invalid_transition: preset version status cannot move backwards'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);

        Schema::table('game_sessions', function (Blueprint $table): void {
            $table->foreignUuid('preset_version_id')->nullable()->constrained('preset_versions')->restrictOnDelete();
        });
        DB::statement('UPDATE game_sessions s SET preset_version_id = c.preset_version_id FROM campaigns c WHERE c.id = s.campaign_id');
    }

    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('preset_version_id');
        });
        DB::statement('DROP INDEX IF EXISTS preset_versions_catalogue');
        DB::statement('ALTER TABLE preset_versions DROP CONSTRAINT IF EXISTS preset_versions_rights_status');
        Schema::table('preset_versions', function (Blueprint $table): void {
            $table->dropColumn(['rights_status', 'rights_updated_at', 'compendium']);
        });
    }
};
