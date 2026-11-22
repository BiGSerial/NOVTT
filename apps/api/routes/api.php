<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Versioned domain API (contracts/http/openapi.v1.yaml). Every route requires an
// authenticated actor; authorization is enforced by policies (ADR 0009).
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
});
