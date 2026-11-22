<?php

declare(strict_types=1);

namespace Novtt\Engine\Sheets;

/**
 * Per-field authorization level declared in novtt.sheet-ui/1 (SPEC-SPRINT-001 rule 3).
 * Masters always read and decide; these levels restrict players.
 */
enum FieldAccess: string
{
    case Hidden = 'hidden';
    case ReadOnly = 'read_only';
    case Editable = 'editable';
    case ApprovalRequired = 'approval_required';
    case MasterOnly = 'master_only';

    public function playerCanRead(): bool
    {
        return match ($this) {
            self::Hidden, self::MasterOnly => false,
            self::ReadOnly, self::Editable, self::ApprovalRequired => true,
        };
    }

    /** Whether a player may include an operation on this field in a draft/proposal. */
    public function playerCanPropose(): bool
    {
        return $this === self::Editable || $this === self::ApprovalRequired;
    }

    /** Most restrictive of two levels (used when a parent and a child both declare access). */
    public function strictest(self $other): self
    {
        $rank = static fn (self $a): int => match ($a) {
            self::Editable => 0,
            self::ApprovalRequired => 1,
            self::ReadOnly => 2,
            self::Hidden => 3,
            self::MasterOnly => 4,
        };

        return $rank($this) >= $rank($other) ? $this : $other;
    }
}
