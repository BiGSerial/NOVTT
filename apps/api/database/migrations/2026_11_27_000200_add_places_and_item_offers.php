<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SPEC-MVP-003 NT-13/NT-14: places in a session (who is near whom) and item offers between players. */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table): void {
            $table->jsonb('places')->nullable();
        });
        Schema::create('item_offers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('game_sessions')->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignUuid('from_character_id')->constrained('campaign_characters')->cascadeOnDelete();
            $table->foreignUuid('to_character_id')->constrained('campaign_characters')->cascadeOnDelete();
            $table->string('item_id', 40);
            $table->string('item_label', 80);
            $table->unsignedSmallInteger('qty');
            $table->string('status', 16)->default('pending');
            $table->foreignUuid('offered_by')->constrained('users');
            $table->foreignUuid('resolved_by')->nullable()->constrained('users');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->index(['session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_offers');
        Schema::table('game_sessions', function (Blueprint $table): void {
            $table->dropColumn('places');
        });
    }
};
