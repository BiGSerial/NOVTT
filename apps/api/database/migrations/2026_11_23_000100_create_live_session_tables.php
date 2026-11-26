<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Live table (SPEC-MVP-002): sessions, the per-session event stream (also the realtime outbox),
 * roll requests and official rolls, suggestions, live character state, master notes, whispers
 * and clues.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->string('title', 120);
            $table->enum('status', ['active', 'paused', 'ended']);
            $table->foreignUuid('started_by')->constrained('users')->restrictOnDelete();
            $table->uuid('roteiro_id')->nullable();
            // Master-only orientation (scene, objective, clocks, spotlight) and table-visible initiative.
            $table->jsonb('compass')->default('{}');
            $table->jsonb('initiative')->default('{}');
            // NPCs shown to the table: name and public summary only, never numbers or secrets.
            $table->jsonb('revealed_npcs')->default('[]');
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();
            $table->index(['campaign_id', 'started_at']);
        });
        DB::statement("CREATE UNIQUE INDEX game_sessions_one_open ON game_sessions (campaign_id) WHERE status IN ('active', 'paused')");
        DB::statement("ALTER TABLE game_sessions ADD CONSTRAINT game_sessions_ended_at CHECK ((status = 'ended') = (ended_at IS NOT NULL))");

        // Append-only stream. `seq` is strictly increasing per session (allocated under the session
        // row lock). Rows are written in the transaction of the change; published_at is the only
        // column the relay may update.
        Schema::create('session_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('game_sessions')->restrictOnDelete();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->unsignedBigInteger('seq');
            $table->enum('audience', ['table', 'master', 'user']);
            $table->foreignUuid('audience_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type', 64);
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('published_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unique(['session_id', 'seq']);
        });
        DB::statement("ALTER TABLE session_events ADD CONSTRAINT session_events_audience_user CHECK ((audience = 'user') = (audience_user_id IS NOT NULL))");
        DB::statement('CREATE INDEX session_events_pending ON session_events (occurred_at) WHERE published_at IS NULL');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION novtt_session_events_publish_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'session_events is append-only'; END IF;
                IF (NEW.id, NEW.session_id, NEW.campaign_id, NEW.seq, NEW.audience, NEW.audience_user_id, NEW.type, NEW.payload, NEW.occurred_at)
                    IS DISTINCT FROM (OLD.id, OLD.session_id, OLD.campaign_id, OLD.seq, OLD.audience, OLD.audience_user_id, OLD.type, OLD.payload, OLD.occurred_at)
                THEN RAISE EXCEPTION 'session_events is append-only'; END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER session_events_append_only BEFORE UPDATE OR DELETE ON session_events FOR EACH ROW EXECUTE FUNCTION novtt_session_events_publish_only()');

        Schema::create('roll_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->foreignUuid('session_id')->constrained('game_sessions')->restrictOnDelete();
            $table->foreignUuid('campaign_character_id')->nullable()->constrained('campaign_characters')->restrictOnDelete();
            $table->foreignUuid('campaign_npc_id')->nullable()->constrained('campaign_npcs')->restrictOnDelete();
            // The user allowed to execute it: the character's player, or the master for NPC rolls.
            $table->foreignUuid('roller_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('label', 120);
            // Snapshot of the combination read from the official sheet: [{path, label, value}].
            $table->jsonb('components');
            $table->smallInteger('modifier')->default(0);
            $table->unsignedSmallInteger('faces');
            $table->enum('count_mode', ['pool', 'fixed']);
            $table->enum('reading', ['sum', 'highest', 'successes']);
            $table->unsignedSmallInteger('threshold');
            $table->unsignedSmallInteger('successes_per_die')->default(1);
            $table->smallInteger('bonus')->default(0);
            $table->integer('target')->nullable();
            $table->unsignedSmallInteger('min_dice');
            $table->unsignedSmallInteger('max_dice');
            $table->boolean('allows_choice')->default(false);
            $table->unsignedSmallInteger('attempts_allowed');
            $table->unsignedSmallInteger('attempts_consumed')->default(0);
            $table->boolean('secret')->default(false);
            $table->enum('status', ['OPEN', 'EXHAUSTED', 'CANCELLED', 'EXPIRED', 'CLOSED'])->default('OPEN');
            $table->unsignedInteger('revision')->default(0);
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index(['session_id', 'status']);
        });
        DB::statement('ALTER TABLE roll_requests ADD CONSTRAINT roll_requests_subject CHECK ((campaign_character_id IS NULL) <> (campaign_npc_id IS NULL))');
        DB::statement('ALTER TABLE roll_requests ADD CONSTRAINT roll_requests_dice CHECK (min_dice BETWEEN 1 AND max_dice AND max_dice <= 12 AND faces BETWEEN 2 AND 100 AND threshold BETWEEN 1 AND faces)');
        DB::statement('ALTER TABLE roll_requests ADD CONSTRAINT roll_requests_attempts CHECK (attempts_allowed BETWEEN 1 AND 10 AND attempts_consumed <= attempts_allowed)');

        Schema::create('official_rolls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('roll_request_id')->constrained('roll_requests')->restrictOnDelete();
            $table->foreignUuid('session_id')->constrained('game_sessions')->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 64);
            $table->char('payload_fingerprint', 64);
            $table->unsignedSmallInteger('attempt');
            $table->unsignedSmallInteger('dice_count');
            $table->jsonb('results');
            $table->integer('total');
            $table->unsignedSmallInteger('successes');
            $table->boolean('succeeded')->nullable();
            // Shapes only the throw animation; the faces above are the result.
            $table->unsignedBigInteger('animation_seed');
            $table->boolean('secret');
            $table->timestampTz('revealed_at')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['actor_user_id', 'idempotency_key']);
            $table->unique(['roll_request_id', 'attempt']);
            $table->index(['session_id', 'created_at']);
        });

        Schema::create('roll_suggestions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('game_sessions')->restrictOnDelete();
            $table->foreignUuid('campaign_character_id')->constrained('campaign_characters')->restrictOnDelete();
            $table->foreignUuid('player_user_id')->constrained('users')->restrictOnDelete();
            $table->jsonb('paths');
            $table->string('note', 200)->nullable();
            $table->enum('status', ['pending', 'accepted', 'dismissed'])->default('pending');
            $table->foreignUuid('roll_request_id')->nullable()->constrained('roll_requests')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['session_id', 'status']);
        });

        // Resource tracks and conditions during play, for a character or an NPC (master edits).
        Schema::create('live_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->enum('subject_kind', ['character', 'npc']);
            $table->uuid('subject_id');
            $table->jsonb('resources')->default('[]');
            $table->jsonb('conditions')->default('[]');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
            $table->unique(['subject_kind', 'subject_id']);
            $table->index('campaign_id');
        });

        Schema::create('master_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('game_sessions')->restrictOnDelete();
            $table->foreignUuid('author_user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->jsonb('tags')->default('[]');
            $table->boolean('pinned')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
            $table->index(['campaign_id', 'deleted_at']);
        });
        DB::statement('ALTER TABLE master_notes ADD CONSTRAINT master_notes_body CHECK (char_length(body) <= 4000)');

        Schema::create('whispers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('game_sessions')->restrictOnDelete();
            $table->foreignUuid('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('kind', ['message', 'clue']);
            $table->text('body');
            $table->timestampTz('created_at');
            $table->index(['campaign_id', 'recipient_user_id', 'created_at']);
            $table->index(['campaign_id', 'sender_user_id', 'created_at']);
        });
        DB::statement('ALTER TABLE whispers ADD CONSTRAINT whispers_body CHECK (char_length(body) BETWEEN 1 AND 2000)');

        Schema::create('clues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('game_sessions')->restrictOnDelete();
            $table->string('scene', 120)->nullable();
            $table->string('title', 120);
            $table->text('body');
            $table->enum('revealed_to', ['table', 'user'])->nullable();
            $table->foreignUuid('revealed_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('revealed_at')->nullable();
            $table->timestampsTz();
            $table->index(['campaign_id', 'session_id']);
        });
        DB::statement('ALTER TABLE clues ADD CONSTRAINT clues_body CHECK (char_length(body) <= 2000)');
        DB::statement("ALTER TABLE clues ADD CONSTRAINT clues_revealed CHECK ((revealed_to IS NULL) = (revealed_at IS NULL) AND ((revealed_to = 'user') = (revealed_user_id IS NOT NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('clues');
        Schema::dropIfExists('whispers');
        Schema::dropIfExists('master_notes');
        Schema::dropIfExists('live_states');
        Schema::dropIfExists('roll_suggestions');
        Schema::dropIfExists('official_rolls');
        Schema::dropIfExists('roll_requests');
        DB::unprepared('DROP TRIGGER IF EXISTS session_events_append_only ON session_events');
        Schema::dropIfExists('session_events');
        DB::unprepared('DROP FUNCTION IF EXISTS novtt_session_events_publish_only()');
        Schema::dropIfExists('game_sessions');
    }
};
