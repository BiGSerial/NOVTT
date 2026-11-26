<?php

declare(strict_types=1);

namespace App\Live;

use InvalidArgumentException;

/** Who may receive a session event: the whole table, the campaign masters, or one user. */
final readonly class Audience
{
    private function __construct(public string $kind, public ?string $userId) {}

    public static function table(): self
    {
        return new self('table', null);
    }

    public static function master(): self
    {
        return new self('master', null);
    }

    public static function user(string $userId): self
    {
        if ($userId === '') {
            throw new InvalidArgumentException('Missing user.');
        }

        return new self('user', $userId);
    }

    /** Private channel name (Reverb) for this audience. */
    public function channel(string $campaignId): string
    {
        return match ($this->kind) {
            'table' => "campaign.{$campaignId}",
            'master' => "campaign.{$campaignId}.master",
            default => "user.{$this->userId}",
        };
    }
}
