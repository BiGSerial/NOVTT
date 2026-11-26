<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Session prep (Roteiro), world Atlas with per-session timeline, and paired master devices
 * (SPEC-MVP-002 LT-07, LT-08; ADR 0011).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roteiros', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->string('title', 120);
            // {strong_start, scenes[{key,title,goal,clues[{title,body}]}], npcs[ids], clocks[{label,segments}], wants{character_id: text}}
            $table->jsonb('content')->default('{}');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['campaign_id', 'archived_at']);
        });
        DB::statement('ALTER TABLE roteiros ADD CONSTRAINT roteiros_content_size CHECK (octet_length(content::text) <= 65536)');
        DB::statement('ALTER TABLE game_sessions ADD CONSTRAINT game_sessions_roteiro FOREIGN KEY (roteiro_id) REFERENCES roteiros (id) ON DELETE RESTRICT');

        Schema::create('atlas_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->enum('kind', ['place', 'person', 'faction', 'secret', 'thread']);
            $table->string('title', 120);
            // Public summary (shown to players only when known_to_players) and master-only details.
            $table->string('summary', 500)->default('');
            $table->text('details')->default('');
            $table->boolean('known_to_players')->default(false);
            $table->enum('thread_status', ['open', 'advancing', 'resolved'])->nullable();
            $table->jsonb('links')->default('[]');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['campaign_id', 'kind']);
        });
        DB::statement('ALTER TABLE atlas_entries ADD CONSTRAINT atlas_entries_details CHECK (char_length(details) <= 8000)');

        Schema::create('atlas_timeline', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('entry_id')->constrained('atlas_entries')->restrictOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('game_sessions')->restrictOnDelete();
            $table->string('note', 500);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->index(['entry_id', 'created_at']);
        });

        Schema::create('paired_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('label', 60);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'revoked_at']);
        });

        // One-time pairing: the device shows `code`; a master confirms it while authenticated; the
        // device claims it with `claim_secret` (never shown on screen). Only digests are stored.
        Schema::create('pairing_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('code_hash', 64);
            $table->char('claim_hash', 64);
            $table->string('device_label', 60);
            $table->enum('status', ['pending', 'confirmed', 'claimed', 'cancelled']);
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('paired_device_id')->nullable()->constrained('paired_devices')->restrictOnDelete();
            $table->unsignedSmallInteger('claim_attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['code_hash', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pairing_codes');
        Schema::dropIfExists('paired_devices');
        Schema::dropIfExists('atlas_timeline');
        Schema::dropIfExists('atlas_entries');
        DB::statement('ALTER TABLE game_sessions DROP CONSTRAINT IF EXISTS game_sessions_roteiro');
        Schema::dropIfExists('roteiros');
    }
};
