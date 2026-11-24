<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AccountController;
use App\Http\Controllers\Api\V1\Auth\PairingController;
use App\Http\Controllers\Api\V1\CampaignCharacterController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\LibraryController;
use App\Http\Controllers\Api\V1\Live\ImageController;
use App\Http\Controllers\Api\V1\Live\RollController;
use App\Http\Controllers\Api\V1\Live\SessionController;
use App\Http\Controllers\Api\V1\Live\TableController;
use App\Http\Controllers\Api\V1\Live\WorldController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\PresetController;
use App\Http\Controllers\Api\V1\ReviewController;
use Illuminate\Support\Facades\Route;

// Accounts and device pairing (SPEC-MVP-002 LT-01, LT-08): reachable before signing in.
Route::prefix('v1')->middleware(['throttle:novtt-api'])->group(function (): void {
    Route::post('auth/register', [AccountController::class, 'register'])->middleware('throttle:novtt-auth');
    Route::post('auth/login', [AccountController::class, 'login'])->middleware('throttle:novtt-auth');
    Route::post('auth/logout', [AccountController::class, 'logout']);
    Route::post('pairing', [PairingController::class, 'create'])->middleware('throttle:novtt-pairing');
    Route::post('pairing/{pairingId}/claim', [PairingController::class, 'claim'])->middleware('throttle:novtt-pairing');
});

// Versioned domain API (contracts/http/openapi.v1.yaml). Every route requires an
// authenticated actor; authorization is enforced by policies (ADR 0009).
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:novtt-api'])->group(function (): void {
    Route::get('presets', [PresetController::class, 'index']);
    Route::post('presets/imports', [PresetController::class, 'import'])->middleware('throttle:novtt-imports');
    Route::get('preset-versions/{presetVersionId}', [PresetController::class, 'show'])->whereUuid('presetVersionId');

    Route::get('campaigns', [CampaignController::class, 'index']);
    Route::post('campaigns', [CampaignController::class, 'store']);
    Route::get('campaigns/{campaignId}/preset-upgrades', [CampaignController::class, 'presetUpgrades'])->whereUuid('campaignId');
    Route::post('campaigns/{campaignId}/preset-upgrade', [CampaignController::class, 'upgradePreset'])->whereUuid('campaignId');
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
    Route::get('campaign-characters/{campaignCharacterId}/adaptation', [CampaignCharacterController::class, 'adaptation'])->whereUuid('campaignCharacterId');
    Route::post('campaign-characters/{campaignCharacterId}/adaptation', [CampaignCharacterController::class, 'adapt'])->whereUuid('campaignCharacterId');
    Route::post('campaign-characters/{campaignCharacterId}/archive', [CampaignCharacterController::class, 'archive'])->whereUuid('campaignCharacterId');
    Route::post('campaign-characters/{campaignCharacterId}/transfer', [CampaignCharacterController::class, 'transfer'])->whereUuid('campaignCharacterId');
    Route::put('campaign-characters/{campaignCharacterId}/custody', [CampaignCharacterController::class, 'takeCustody'])->whereUuid('campaignCharacterId');
    Route::delete('campaign-characters/{campaignCharacterId}/custody', [CampaignCharacterController::class, 'releaseCustody'])->whereUuid('campaignCharacterId');

    Route::get('campaign-characters/{campaignCharacterId}/draft', [ReviewController::class, 'showDraft'])->whereUuid('campaignCharacterId');
    Route::put('campaign-characters/{campaignCharacterId}/draft', [ReviewController::class, 'saveDraft'])->whereUuid('campaignCharacterId');
    Route::post('campaign-characters/{campaignCharacterId}/draft/rebase-preview', [ReviewController::class, 'rebasePreview'])->whereUuid('campaignCharacterId');
    Route::post('campaign-characters/{campaignCharacterId}/draft/rebase', [ReviewController::class, 'rebase'])->whereUuid('campaignCharacterId');
    Route::get('campaign-characters/{campaignCharacterId}/change-requests', [ReviewController::class, 'index'])->whereUuid('campaignCharacterId');
    Route::post('campaign-characters/{campaignCharacterId}/change-requests', [ReviewController::class, 'submit'])->whereUuid('campaignCharacterId');
    Route::post('campaign-characters/{campaignCharacterId}/approve', [ReviewController::class, 'approve'])->whereUuid('campaignCharacterId');
    Route::get('campaign-characters/{campaignCharacterId}/grants', [ReviewController::class, 'grants'])->whereUuid('campaignCharacterId');
    Route::post('campaign-characters/{campaignCharacterId}/grants', [ReviewController::class, 'grant'])->whereUuid('campaignCharacterId');
    Route::get('change-requests/{changeRequestId}', [ReviewController::class, 'show'])->whereUuid('changeRequestId');
    Route::post('change-requests/{changeRequestId}/withdraw', [ReviewController::class, 'withdraw'])->whereUuid('changeRequestId');
    Route::put('change-requests/{changeRequestId}/decisions', [ReviewController::class, 'decide'])->whereUuid('changeRequestId');
    Route::post('change-requests/{changeRequestId}/finalize', [ReviewController::class, 'finalize'])->whereUuid('changeRequestId');

    Route::get('me', [AccountController::class, 'show']);
    Route::post('pairing/confirm', [PairingController::class, 'confirm'])->middleware('throttle:novtt-invitations');
    Route::get('devices', [PairingController::class, 'devices']);
    Route::delete('devices/{deviceId}', [PairingController::class, 'revoke']);

    Route::get('campaigns/{campaignId}/roteiros', [WorldController::class, 'roteiros'])->whereUuid('campaignId');
    Route::post('campaigns/{campaignId}/roteiros', [WorldController::class, 'createRoteiro'])->whereUuid('campaignId');
    Route::put('roteiros/{roteiroId}', [WorldController::class, 'updateRoteiro']);
    Route::post('roteiros/{roteiroId}/archive', [WorldController::class, 'archiveRoteiro']);
    Route::get('campaigns/{campaignId}/atlas', [WorldController::class, 'atlas'])->whereUuid('campaignId');
    Route::post('campaigns/{campaignId}/atlas', [WorldController::class, 'createEntry'])->whereUuid('campaignId');
    Route::patch('atlas/{entryId}', [WorldController::class, 'updateEntry']);
    Route::post('atlas/{entryId}/timeline', [WorldController::class, 'addTimeline']);

    // Live table (SPEC-MVP-002).
    Route::get('campaigns/{campaignId}/sessions/current', [SessionController::class, 'current'])->whereUuid('campaignId');
    Route::post('campaigns/{campaignId}/sessions', [SessionController::class, 'start'])->whereUuid('campaignId');
    Route::post('sessions/{sessionId}/{action}', [SessionController::class, 'transition'])->whereUuid('sessionId')->whereIn('action', ['pause', 'resume', 'end']);
    Route::get('sessions/{sessionId}/snapshot', [SessionController::class, 'snapshot'])->whereUuid('sessionId');
    Route::get('sessions/{sessionId}/events', [SessionController::class, 'events'])->whereUuid('sessionId');
    Route::put('sessions/{sessionId}/compass', [SessionController::class, 'compass'])->whereUuid('sessionId');
    Route::put('sessions/{sessionId}/initiative', [SessionController::class, 'initiative'])->whereUuid('sessionId');
    Route::put('sessions/{sessionId}/plot', [SessionController::class, 'plot'])->whereUuid('sessionId');
    Route::put('sessions/{sessionId}/stage', [SessionController::class, 'stage'])->whereUuid('sessionId');
    Route::put('sessions/{sessionId}/atmosphere', [SessionController::class, 'atmosphere'])->whereUuid('sessionId');

    // Narrative table (SPEC-MVP-003): images, portraits and prep carry-over.
    Route::post('campaigns/{campaignId}/images', [ImageController::class, 'upload'])->whereUuid('campaignId')->middleware('throttle:novtt-uploads');
    Route::get('images/{imageId}', [ImageController::class, 'show']);
    Route::put('campaigns/{campaignId}/portraits/{kind}/{subjectId}', [ImageController::class, 'setPortrait'])->whereUuid('campaignId')->whereIn('kind', ['character', 'npc']);
    Route::get('campaigns/{campaignId}/prep/carry-over', [WorldController::class, 'carryOver'])->whereUuid('campaignId');

    Route::post('sessions/{sessionId}/roll-requests', [RollController::class, 'request'])->whereUuid('sessionId');
    Route::post('roll-requests/{requestId}/rolls', [RollController::class, 'roll'])->middleware('throttle:novtt-rolls');
    Route::post('roll-requests/{requestId}/cancel', [RollController::class, 'cancel']);
    Route::post('roll-requests/{requestId}/grant-attempt', [RollController::class, 'grant']);
    Route::post('sessions/{sessionId}/npc-rolls', [RollController::class, 'npcRoll'])->whereUuid('sessionId')->middleware('throttle:novtt-rolls');
    Route::post('official-rolls/{rollId}/reveal', [RollController::class, 'reveal']);

    Route::put('campaigns/{campaignId}/live-states/{kind}/{subjectId}', [TableController::class, 'saveLiveState'])->whereUuid('campaignId')->whereIn('kind', ['character', 'npc']);
    Route::get('campaigns/{campaignId}/notes', [TableController::class, 'notes'])->whereUuid('campaignId');
    Route::post('campaigns/{campaignId}/notes', [TableController::class, 'createNote'])->whereUuid('campaignId');
    Route::patch('notes/{noteId}', [TableController::class, 'updateNote']);
    Route::delete('notes/{noteId}', [TableController::class, 'deleteNote']);
    Route::post('campaigns/{campaignId}/whispers', [TableController::class, 'whisper'])->whereUuid('campaignId')->middleware('throttle:novtt-whispers');
    Route::post('sessions/{sessionId}/clues', [TableController::class, 'createClue'])->whereUuid('sessionId');
    Route::post('clues/{clueId}/reveal', [TableController::class, 'revealClue']);
    Route::post('sessions/{sessionId}/npc-reveals', [TableController::class, 'revealNpc'])->whereUuid('sessionId');
    Route::post('sessions/{sessionId}/suggestions', [TableController::class, 'suggest'])->whereUuid('sessionId')->middleware('throttle:novtt-whispers');
    Route::post('suggestions/{suggestionId}/dismiss', [TableController::class, 'dismissSuggestion']);

    Route::post('invitations/accept', [MembershipController::class, 'accept'])->middleware('throttle:novtt-invitations');
});
