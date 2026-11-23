<?php

declare(strict_types=1);

namespace Novtt\Engine\Presets;

use Novtt\Engine\Sheets\CanonicalJson;
use Novtt\Engine\Validation\Severity;
use Novtt\Engine\Validation\ValidationIssue;
use Novtt\Engine\Validation\ValidationResult;

/**
 * Rights gate for public distribution (LICENSE-MATRIX, ADR 0012). Technical validity is not a
 * licence: a preset version may be listed publicly only when the package and every compendium
 * entry it carries have approved rights, a licence, provenance and, when the licence requires it,
 * attribution text. Quarantined content never passes. The gate never decides rights; it only
 * refuses to publish what lacks a recorded approval.
 */
final class PublicationGate
{
    public const PUBLISHABLE = ['approved', 'published'];
    public const RIGHTS_STATES = ['draft', 'rights_review', 'approved', 'quarantined', 'published', 'retired'];

    /**
     * @param array<string, mixed> $manifest novtt.preset-manifest/2
     * @param array<string, mixed>|null $compendium novtt.compendium/1
     */
    public function check(array $manifest, ?array $compendium = null): ValidationResult
    {
        $issues = [];
        if (($manifest['contract'] ?? null) !== 'novtt.preset-manifest/2') {
            return new ValidationResult([self::issue('rights.manifest_without_rights', '/manifest/contract')]);
        }
        $license = (array) ($manifest['license'] ?? []);
        $this->rights((string) ($license['rights_status'] ?? ''), '/manifest/license/rights_status', $issues);
        if (trim((string) ($license['identifier'] ?? '')) === '') {
            $issues[] = self::issue('rights.license_missing', '/manifest/license/identifier');
        }
        if (self::requiresAttribution($license) && trim((string) ($license['attribution'] ?? '')) === '') {
            $issues[] = self::issue('rights.attribution_missing', '/manifest/license/attribution');
        }
        if (trim((string) ($manifest['provenance']['source_authenticity'] ?? '')) === '') {
            $issues[] = self::issue('rights.provenance_missing', '/manifest/provenance');
        }

        $attributions = (array) ($compendium['attributions'] ?? []);
        foreach ($compendium['items'] ?? [] as $i => $item) {
            $at = "/compendium/items/{$i}";
            $source = (array) ($item['source'] ?? []);
            $this->rights((string) ($source['rights_status'] ?? ''), $at . '/source/rights_status', $issues);
            if (trim((string) ($source['license_id'] ?? '')) === '' || trim((string) ($source['source_id'] ?? '')) === '') {
                $issues[] = self::issue('rights.provenance_missing', $at . '/source');
            }
            if (self::requiresAttribution(['identifier' => $source['license_id'] ?? '', 'requires_attribution' => $source['requires_attribution'] ?? null])) {
                $ref = (string) ($source['attribution_ref'] ?? '');
                if ($ref === '' || trim((string) ($attributions[$ref] ?? '')) === '') {
                    $issues[] = self::issue('rights.attribution_missing', $at . '/source/attribution_ref');
                }
            }
            if (($source['review_status'] ?? null) !== 'verified') {
                $issues[] = self::issue('rights.unverified_source', $at . '/source/review_status');
            }
            if (isset($item['content_hash']) && $item['content_hash'] !== self::contentHash($item)) {
                $issues[] = self::issue('rights.content_hash_mismatch', $at . '/content_hash');
            }
        }

        return new ValidationResult($issues);
    }

    /** Whether a stored version may appear in the public catalogue. */
    public static function publiclyListable(string $technicalStatus, ?string $rightsStatus): bool
    {
        return $technicalStatus === 'published' && in_array($rightsStatus, self::PUBLISHABLE, true);
    }

    /** sha256 of the canonical JSON of an item without its own `content_hash`. @param array<string, mixed> $item */
    public static function contentHash(array $item): string
    {
        unset($item['content_hash']);

        return hash('sha256', CanonicalJson::encode($item));
    }

    /** @param array<string, mixed> $license */
    public static function requiresAttribution(array $license): bool
    {
        if (isset($license['requires_attribution']) && is_bool($license['requires_attribution'])) {
            return $license['requires_attribution'];
        }

        // Every Creative Commons licence except CC0 requires attribution.
        return preg_match('/^CC-BY/i', (string) ($license['identifier'] ?? '')) === 1;
    }

    /** @param list<ValidationIssue> $issues */
    private function rights(string $status, string $at, array &$issues): void
    {
        if ($status === 'quarantined') {
            $issues[] = self::issue('rights.quarantined', $at);
        } elseif (!in_array($status, self::PUBLISHABLE, true)) {
            $issues[] = self::issue('rights.not_approved', $at, ['rights_status' => $status === '' ? null : $status]);
        }
    }

    /** @param array<string, scalar|null> $params */
    private static function issue(string $rule, string $at, array $params = []): ValidationIssue
    {
        return new ValidationIssue($rule, Severity::Error, [], [$at], $params);
    }
}
