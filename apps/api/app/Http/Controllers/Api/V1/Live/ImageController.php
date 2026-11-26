<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Live;

use App\Application\AuditTrail;
use App\Domain\CampaignRole;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ApiProblem;
use App\Live\Audience;
use App\Live\ImageStore;
use App\Live\SessionAccess;
use App\Live\SessionStream;
use App\Models\CampaignCharacter;
use App\Models\CampaignImage;
use App\Models\CampaignNpc;
use App\Models\Clue;
use App\Models\GameSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Campaign images (SPEC-MVP-003 NT-02): upload, authorized download and portrait assignment.
 * Images are private to the campaign; players only receive the images the table may see.
 */
final class ImageController extends ApiController
{
    public function __construct(private readonly SessionAccess $access, private readonly ImageStore $images, private readonly SessionStream $stream, private readonly AuditTrail $audit) {}

    public function upload(Request $request, string $campaignId): JsonResponse
    {
        $actor = $this->actor($request);
        $role = $this->access->memberRole($actor, $campaignId);
        $unknown = array_diff(array_keys($request->all()), ['file', 'kind']);
        if ($unknown !== []) {
            throw new ApiProblem(422, 'request.unknown_field');
        }
        $data = Validator::make($request->all(), [
            'kind' => ['required', 'in:portrait,scene,handout'],
            'file' => ['required', 'file', 'max:'.(ImageStore::MAX_UPLOAD_BYTES / 1024)],
        ])->validate();
        if ($role !== CampaignRole::Master && $data['kind'] !== 'portrait') {
            throw new ApiProblem(403, 'live.master_only');
        }
        /** @var UploadedFile $file */
        $file = $data['file'];
        $image = $this->images->store($actor, $campaignId, $data['kind'], $file);
        $this->audit->record($actor, $campaignId, 'image.uploaded', $image, ['kind' => $image->kind]);

        return new JsonResponse(self::view($image), 201);
    }

    public function show(Request $request, string $imageId): Response
    {
        $actor = $this->actor($request);
        $image = Str::isUuid($imageId) ? CampaignImage::query()->find($imageId) : null;
        $role = $image === null ? null : $this->access->role($actor, $image->campaign_id);
        if ($image === null || $role === null || ($role !== CampaignRole::Master && ! $this->playerMaySee($actor, $image))) {
            throw ApiProblem::notFound();
        }
        $etag = '"'.$image->sha256.'"';
        $headers = [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=86400, immutable',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return new Response('', 304, $headers);
        }

        return new Response($this->images->bytes($image), 200, $headers);
    }

    public function setPortrait(Request $request, string $campaignId, string $kind, string $subjectId): JsonResponse
    {
        $actor = $this->actor($request);
        $role = $this->access->memberRole($actor, $campaignId);
        $data = $this->input($request, ['image_id' => ['present', 'nullable', 'uuid']]);
        $subject = ! Str::isUuid($subjectId) ? null : ($kind === 'character'
            ? CampaignCharacter::query()->where('campaign_id', $campaignId)->find($subjectId)
            : CampaignNpc::query()->where('campaign_id', $campaignId)->find($subjectId));
        if ($subject === null || ($kind === 'npc' && $role !== CampaignRole::Master)) {
            throw ApiProblem::notFound();
        }
        if ($role !== CampaignRole::Master && ($subject->custodian_user_id ?? $subject->player_user_id) !== $actor->getKey()) {
            throw new ApiProblem(403, 'portrait.not_yours');
        }
        if ($data['image_id'] !== null && ! CampaignImage::query()->where(['id' => $data['image_id'], 'campaign_id' => $campaignId, 'kind' => 'portrait'])->exists()) {
            throw new ApiProblem(422, 'image.unknown');
        }

        DB::transaction(function () use ($actor, $campaignId, $kind, $subject, $data): void {
            $session = $this->access->lockOpenForCampaign($campaignId);
            $subject->update(['portrait_image_id' => $data['image_id']]);
            $this->audit->record($actor, $campaignId, 'portrait.updated', $subject, ['kind' => $kind]);
            if ($session !== null) {
                $this->stream->append($session, 'portrait.updated', ['kind' => $kind, 'id' => $subject->getKey(), 'portrait_image_id' => $data['image_id']], $kind === 'character' ? Audience::table() : Audience::master());
            }
        });

        return new JsonResponse(['kind' => $kind, 'id' => $subject->getKey(), 'portrait_image_id' => $data['image_id']]);
    }

    /**
     * A player sees: portraits of the campaign's characters, the portraits they uploaded
     * themselves, portraits of NPCs revealed in the open session, the staged scene image and
     * images of clues revealed to the table or to them, and the scene map while it is shown.
     */
    private function playerMaySee(User $actor, CampaignImage $image): bool
    {
        $id = $image->getKey();
        if ($image->created_by === $actor->getKey()) {
            return true;
        }
        $session = GameSession::query()->where('campaign_id', $image->campaign_id)->whereIn('status', ['active', 'paused'])->first();
        if ($image->kind === 'portrait') {
            if (CampaignCharacter::query()->where(['campaign_id' => $image->campaign_id, 'portrait_image_id' => $id])->exists()) {
                return true;
            }
            if (in_array($id, array_map(static fn (array $n): ?string => $n['portrait_image_id'] ?? null, $session?->revealed_npcs ?? []), true)) {
                return true;
            }
            $revealed = array_values(array_filter(array_map(static fn (array $n): string => (string) ($n['id'] ?? ''), $session?->revealed_npcs ?? []), static fn (string $v): bool => Str::isUuid($v)));

            return $revealed !== [] && CampaignNpc::query()->where('campaign_id', $image->campaign_id)->whereIn('id', $revealed)->where('portrait_image_id', $id)->exists();
        }
        if ($session !== null && (($session->stage ?? [])['image_id'] ?? null) === $id) {
            return true;
        }
        // The scene map while the master shows it (NT-15).
        if ($session !== null && ($session->map['shown'] ?? false) && ($session->map['image_id'] ?? null) === $id) {
            return true;
        }

        return Clue::query()->where(['campaign_id' => $image->campaign_id, 'image_id' => $id])
            ->where(fn ($q) => $q->where('revealed_to', 'table')->orWhere('revealed_user_id', $actor->getKey()))->exists();
    }

    /** @return array<string, mixed> */
    public static function view(CampaignImage $image): array
    {
        return ['id' => $image->getKey(), 'kind' => $image->kind, 'width' => $image->width, 'height' => $image->height];
    }
}
