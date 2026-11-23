<?php

declare(strict_types=1);

namespace App\Live;

use App\Http\Problems\ApiProblem;
use App\Models\CampaignImage;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accepts an uploaded image, verifies it really is JPEG / PNG / WebP, decodes it with bounded
 * dimensions and re-encodes it as WebP. Re-encoding drops every metadata chunk (EXIF, GPS, ICC
 * text) and any trailing payload, so what is served is pixels only.
 */
final class ImageStore
{
    public const MAX_UPLOAD_BYTES = 4 * 1024 * 1024;

    public const MAX_SIDE = 6000;

    /** Decoding cost bound for the 128 MB worker memory (4 bytes per pixel plus the copy). */
    public const MAX_PIXELS = 12_000_000;

    public const PER_CAMPAIGN = 300;

    private const ACCEPTED = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];

    private const LONGEST = ['portrait' => 1024, 'scene' => 1600, 'handout' => 1600];

    public function store(User $actor, string $campaignId, string $kind, UploadedFile $file): CampaignImage
    {
        $path = $file->getRealPath();
        if ($path === false || $file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw self::rejected('image.too_large');
        }
        $sniffed = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $info = @getimagesize($path);
        if ($info === false || ! isset(self::ACCEPTED[$info[2]]) || self::ACCEPTED[$info[2]] !== $sniffed) {
            throw self::rejected('image.type_unsupported');
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw self::rejected('image.too_large');
        }
        $source = @imagecreatefromstring((string) file_get_contents($path));
        if (! $source instanceof GdImage) {
            throw self::rejected('image.type_unsupported');
        }
        [$bytes, $w, $h] = $this->encode($source, self::LONGEST[$kind]);

        return DB::transaction(function () use ($actor, $campaignId, $kind, $bytes, $w, $h): CampaignImage {
            DB::table('campaigns')->where('id', $campaignId)->lockForUpdate()->first();
            if (CampaignImage::query()->where('campaign_id', $campaignId)->count() >= self::PER_CAMPAIGN) {
                throw ApiProblem::conflict('image.limit');
            }
            $id = (string) Str::uuid();
            DB::insert(
                "INSERT INTO campaign_images (id, campaign_id, kind, mime, bytes, width, height, sha256, created_by, created_at, updated_at) VALUES (?, ?, ?, 'image/webp', decode(?, 'base64'), ?, ?, ?, ?, now(), now())",
                [$id, $campaignId, $kind, base64_encode($bytes), $w, $h, hash('sha256', $bytes), $actor->getKey()],
            );

            return CampaignImage::query()->findOrFail($id);
        });
    }

    public function bytes(CampaignImage $image): string
    {
        $encoded = DB::table('campaign_images')->where('id', $image->getKey())->selectRaw("encode(bytes, 'base64') AS b")->value('b');

        return base64_decode(str_replace("\n", '', (string) $encoded), true) ?: '';
    }

    /** @return array{0: string, 1: int, 2: int} */
    private function encode(GdImage $source, int $longest): array
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(1.0, $longest / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $target = imagecreatetruecolor($tw, $th);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($source);
        ob_start();
        imagewebp($target, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($target);
        if ($bytes === '' || strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw self::rejected('image.too_large');
        }

        return [$bytes, $tw, $th];
    }

    private static function rejected(string $code): ApiProblem
    {
        return new ApiProblem(422, $code);
    }
}
