<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SPEC-MVP-003 NT-15: a map image with character pins, moved by the master only. */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table): void {
            $table->jsonb('map')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table): void {
            $table->dropColumn('map');
        });
    }
};
