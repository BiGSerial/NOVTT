<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns, membership and invitations (S001-02, S001-03).
 * A campaign pins exactly one preset version; changing it is an explicit migration, never implicit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('preset_version_id')->constrained('preset_versions')->restrictOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index('owner_user_id');
        });
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_archived_at CHECK ((status = 'archived') = (archived_at IS NOT NULL))");
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_description_length CHECK (description IS NULL OR char_length(description) <= 4000)');

        Schema::create('campaign_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('role', ['master', 'player']);
            $table->enum('status', ['active', 'removed'])->default('active');
            $table->foreignUuid('invitation_id')->nullable();
            $table->timestampTz('joined_at');
            $table->timestampTz('removed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['campaign_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
        DB::statement("ALTER TABLE campaign_members ADD CONSTRAINT campaign_members_removed_at CHECK ((status = 'removed') = (removed_at IS NOT NULL))");

        Schema::create('campaign_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            // Only a SHA-256 digest of the secret token is stored; the token is shown once.
            $table->char('token_hash', 64)->unique();
            $table->enum('role', ['player'])->default('player');
            $table->unsignedSmallInteger('max_uses')->default(1);
            $table->unsignedSmallInteger('uses')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['campaign_id', 'revoked_at']);
        });
        DB::statement('ALTER TABLE campaign_invitations ADD CONSTRAINT campaign_invitations_uses CHECK (max_uses BETWEEN 1 AND 100 AND uses <= max_uses)');

        Schema::table('campaign_members', function (Blueprint $table): void {
            $table->foreign('invitation_id')->references('id')->on('campaign_invitations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_members', function (Blueprint $table): void {
            $table->dropForeign(['invitation_id']);
        });
        Schema::dropIfExists('campaign_invitations');
        Schema::dropIfExists('campaign_members');
        Schema::dropIfExists('campaigns');
    }
};
