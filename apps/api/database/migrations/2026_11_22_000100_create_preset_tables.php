<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preset catalogue (SPEC-PRESET-AUTHORING-001).
 *
 * A family groups independent variants; a variant (preset_definitions) has immutable versions.
 * owner_user_id NULL means the canonical catalogue maintained by the team; otherwise the row
 * belongs to a user's personal library and never carries canonical status.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Shared helper: reject any UPDATE/DELETE on append-only tables.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION novtt_reject_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'novtt.immutable_row: % on % is not allowed', TG_OP, TG_TABLE_NAME
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$;
        SQL);

        Schema::create('preset_families', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('slug', 64);
            $table->string('display_name', 120);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE preset_families ADD CONSTRAINT preset_families_slug_format CHECK (slug ~ '^[a-z0-9][a-z0-9-]{0,63}$')");
        DB::statement('CREATE UNIQUE INDEX preset_families_canonical_slug ON preset_families (slug) WHERE owner_user_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX preset_families_personal_slug ON preset_families (owner_user_id, slug) WHERE owner_user_id IS NOT NULL');

        Schema::create('preset_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('family_id')->constrained('preset_families')->restrictOnDelete();
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('variant_slug', 64);
            $table->string('display_name', 120);
            $table->timestampsTz();
            $table->unique(['family_id', 'variant_slug']);
        });
        DB::statement("ALTER TABLE preset_definitions ADD CONSTRAINT preset_definitions_slug_format CHECK (variant_slug ~ '^[a-z0-9][a-z0-9-]{0,63}$')");

        Schema::create('preset_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('definition_id')->constrained('preset_definitions')->restrictOnDelete();
            $table->string('version', 64);
            $table->enum('status', ['draft', 'structurally_valid', 'engine_compatible', 'reviewed', 'published']);
            $table->char('content_hash', 64);
            $table->jsonb('manifest');
            $table->jsonb('sheet_schema');
            $table->jsonb('sheet_ui');
            $table->jsonb('rules');
            $table->jsonb('validation_report');
            $table->string('engine_version', 32);
            $table->foreignUuid('imported_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->unique(['definition_id', 'version']);
        });
        DB::statement("ALTER TABLE preset_versions ADD CONSTRAINT preset_versions_semver CHECK (version ~ '^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?$')");
        DB::statement("ALTER TABLE preset_versions ADD CONSTRAINT preset_versions_hash_format CHECK (content_hash ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE preset_versions ADD CONSTRAINT preset_versions_published_at CHECK ((status = 'published') = (published_at IS NOT NULL))");

        // Content of a version never changes after insert; corrections create a new version.
        // Status may only move forward through the documented lifecycle.
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
            CREATE TRIGGER preset_versions_guard BEFORE UPDATE OR DELETE ON preset_versions
                FOR EACH ROW EXECUTE FUNCTION novtt_preset_version_guard();
        SQL);

        Schema::create('user_preset_library', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('preset_version_id')->constrained('preset_versions')->restrictOnDelete();
            $table->timestampTz('added_at');
            $table->primary(['user_id', 'preset_version_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preset_library');
        DB::unprepared('DROP TRIGGER IF EXISTS preset_versions_guard ON preset_versions');
        Schema::dropIfExists('preset_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS novtt_preset_version_guard()');
        Schema::dropIfExists('preset_definitions');
        Schema::dropIfExists('preset_families');
        DB::unprepared('DROP FUNCTION IF EXISTS novtt_reject_mutation()');
    }
};
