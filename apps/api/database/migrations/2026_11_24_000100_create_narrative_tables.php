<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Narrative table (SPEC-MVP-003): campaign images (portraits, scene art, handouts), the session
 * plot (master-only scenes) and stage (what the table sees now), and live inventory.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Images are stored re-encoded (WebP, metadata stripped) and served only through the API
        // after an authorization check; never from a public disk.
        Schema::create('campaign_images', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->enum('kind', ['portrait', 'scene', 'handout']);
            $table->string('mime', 32);
            $table->binary('bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->char('sha256', 64);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['campaign_id', 'kind']);
        });
        DB::statement("ALTER TABLE campaign_images ADD CONSTRAINT campaign_images_mime CHECK (mime = 'image/webp')");
        DB::statement('ALTER TABLE campaign_images ADD CONSTRAINT campaign_images_size CHECK (octet_length(bytes) <= 4194304)');

        foreach (['campaign_characters', 'campaign_npcs'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignUuid('portrait_image_id')->nullable()->constrained('campaign_images')->nullOnDelete();
            });
        }
        Schema::table('live_states', function (Blueprint $table): void {
            $table->jsonb('items')->default('[]');
        });
        Schema::table('game_sessions', function (Blueprint $table): void {
            $table->jsonb('plot')->default('{"scenes":[],"current":null}');
            $table->jsonb('stage')->nullable();
        });
        Schema::table('clues', function (Blueprint $table): void {
            $table->foreignUuid('image_id')->nullable()->constrained('campaign_images')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clues', fn (Blueprint $t) => $t->dropConstrainedForeignId('image_id'));
        Schema::table('game_sessions', fn (Blueprint $t) => $t->dropColumn(['plot', 'stage']));
        Schema::table('live_states', fn (Blueprint $t) => $t->dropColumn('items'));
        foreach (['campaign_characters', 'campaign_npcs'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropConstrainedForeignId('portrait_image_id'));
        }
        Schema::dropIfExists('campaign_images');
    }
};
