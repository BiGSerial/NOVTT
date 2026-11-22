<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\PresetController;
use Illuminate\Support\Facades\Route;

// Versioned domain API (contracts/http/openapi.v1.yaml). Every route requires an
// authenticated actor; authorization is enforced by policies (ADR 0009).
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:novtt-api'])->group(function (): void {
    Route::get('presets', [PresetController::class, 'index']);
    Route::post('presets/imports', [PresetController::class, 'import'])->middleware('throttle:novtt-imports');
    Route::get('preset-versions/{presetVersionId}', [PresetController::class, 'show'])->whereUuid('presetVersionId');

    Route::get('campaigns', [CampaignController::class, 'index']);
    Route::post('campaigns', [CampaignController::class, 'store']);
    Route::get('campaigns/{campaignId}', [CampaignController::class, 'show'])->whereUuid('campaignId');
    Route::patch('campaigns/{campaignId}', [CampaignController::class, 'update'])->whereUuid('campaignId');
    Route::post('campaigns/{campaignId}/archive', [CampaignController::class, 'archive'])->whereUuid('campaignId');
});
