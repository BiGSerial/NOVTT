<?php

declare(strict_types=1);

namespace App\Application;

use App\Models\AuditEvent;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit + outbox writer. Call inside the transaction of the state change. Metadata MUST NOT
 * contain secrets (tokens, passwords) or private sheet content.
 */
final class AuditTrail
{
    /** @param array<string, mixed> $metadata */
    public function record(?User $actor, ?string $campaignId, string $action, Model $subject, array $metadata = []): void
    {
        AuditEvent::query()->create([
            'occurred_at' => now(),
            'actor_user_id' => $actor?->getKey(),
            'campaign_id' => $campaignId,
            'action' => $action,
            'subject_type' => $subject->getTable(),
            'subject_id' => $subject->getKey(),
            'metadata' => $metadata,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function emit(?string $campaignId, string $eventType, Model $aggregate, array $payload, int $schemaVersion = 1): void
    {
        OutboxEvent::query()->create([
            'campaign_id' => $campaignId,
            'aggregate_type' => $aggregate->getTable(),
            'aggregate_id' => $aggregate->getKey(),
            'event_type' => $eventType,
            'schema_version' => $schemaVersion,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
