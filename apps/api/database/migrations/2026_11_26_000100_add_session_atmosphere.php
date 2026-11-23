<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table atmosphere (SPEC-MVP-003 NT-10): time of day, weather, intensity and time flow chosen by
 * the master and shown to the whole table. NULL until the master sets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table): void {
            $table->jsonb('atmosphere')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table): void {
            $table->dropColumn('atmosphere');
        });
    }
};
