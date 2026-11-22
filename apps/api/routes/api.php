<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CampaignCharacterController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\LibraryController;
use App\Http\Controllers\Api\V1\MembershipController;
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

    Route::get('campaigns/{campaignId}/members', [MembershipController::class, 'members'])->whereUuid('campaignId');
    Route::delete('campaigns/{campaignId}/members/{memberId}', [MembershipController::class, 'remove'])->whereUuid(['campaignId', 'memberId']);
    Route::get('campaigns/{campaignId}/invitations', [MembershipController::class, 'invitations'])->whereUuid('campaignId');
    Route::post('campaigns/{campaignId}/invitations', [MembershipController::class, 'invite'])->whereUuid('campaignId');
    Route::delete('campaigns/{campaignId}/invitations/{invitationId}', [MembershipController::class, 'revoke'])->whereUuid(['campaignId', 'invitationId']);
    Route::get('characters', [LibraryController::class, 'characters']);
    Route::post('characters', [LibraryController::class, 'storeCharacter']);
    Route::get('characters/{characterId}', [LibraryController::class, 'showCharacter'])->whereUuid('characterId');
    Route::delete('characters/{characterId}', [LibraryController::class, 'deleteCharacter'])->whereUuid('characterId');
    Route::post('characters/{characterId}/archive', [LibraryController::class, 'archiveCharacter'])->whereUuid('characterId');
    Route::get('npcs', [LibraryController::class, 'npcs']);
    Route::post('npcs', [LibraryController::class, 'storeNpc']);

    Route::get('campaigns/{campaignId}/characters', [CampaignCharacterController::class, 'index'])->whereUuid('campaignId');
    Route::post('campaigns/{campaignId}/characters', [CampaignCharacterController::class, 'store'])->whereUuid('campaignId');
    Route::get('campaigns/{campaignId}/npcs', [CampaignCharacterController::class, 'npcs'])->whereUuid('campaignId');
    Route::post('campaigns/{campaignId}/npcs', [CampaignCharacterController::class, 'storeNpc'])->whereUuid('campaignId');
    Route::get('campaign-characters/{campaignCharacterId}', [CampaignCharacterController::class, 'show'])->whereUuid('campaignCharacterId');
    Route::post('campaign-characters/{campaignCharacterId}/archive', [CampaignCharacterController::class, 'archive'])->whereUuid('campaignCharacterId');
    Route::post('campaign-characters/{campaignCharacterId}/transfer', [CampaignCharacterController::class, 'transfer'])->whereUuid('campaignCharacterId');
    Route::put('campaign-characters/{campaignCharacterId}/custody', [CampaignCharacterController::class, 'takeCustody'])->whereUuid('campaignCharacterId');
    Route::delete('campaign-characters/{campaignCharacterId}/custody', [CampaignCharacterController::class, 'releaseCustody'])->whereUuid('campaignCharacterId');

    Route::post('invitations/accept', [MembershipController::class, 'accept'])->middleware('throttle:novtt-invitations');
});
