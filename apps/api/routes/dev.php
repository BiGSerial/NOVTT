<?php

declare(strict_types=1);

use App\Http\Controllers\Dev\DemoController;
use App\Http\Middleware\EnsureDemoMode;
use Illuminate\Support\Facades\Route;

// Local demonstration only (App\Demo\DemoMode). Not part of the versioned contract.
Route::prefix('api/dev/demo')->middleware(['api', EnsureDemoMode::class, 'throttle:novtt-api'])->group(function (): void {
    Route::get('/', [DemoController::class, 'show']);
    Route::post('session', [DemoController::class, 'login']);
    Route::delete('session', [DemoController::class, 'logout']);
});
