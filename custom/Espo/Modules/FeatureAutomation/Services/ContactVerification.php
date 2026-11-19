<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureCredits\Verification\Kind;
use Espo\Modules\FeatureCredits\Verification\Operations;

/**
 * Starts, or reads back, one paid contact check for an automation item.
 *
 * Built inside ActorScope, so every call runs as the automation's run-as user: the same
 * permission, workspace, Contact/field ACL, credit and rate-limit rules as a person
 * clicking "Verificar" in the inbox. The request key is fixed per item and check, so a
 * resumed item always lands on the operation it started (or joined / reused) before.
 */
class ContactVerification
{
    public function __construct(private Operations $operations) {}

    /**
     * @param array<string, mixed> $source verification source descriptor (no `key`)
     * @return array{operation: array<string, mixed>}|array{unavailable: string}
     * @throws \Espo\Core\Exceptions\Conflict with a `reason` body when admission is refused
     */
    public function startOrRead(
        string $tenantId,
        string $contactId,
        string $kind,
        array $source,
        string $requestKey,
    ): array {
        try {
            $status = $this->operations->status([
                'tenantId' => $tenantId,
                'contactId' => $contactId,
                'requestKey' => $requestKey,
            ], $kind);

            return ['operation' => $status['operation']];
        } catch (NotFound) {
            // Nothing started under this key yet.
        }

        $context = $this->operations->context((object) [
            'tenantId' => $tenantId,
            'contactId' => $contactId,
            'sources' => [(object) ($source + ['key' => 'source'])],
        ], $kind);

        $row = $context['rows']['source'] ?? null;
        if (!is_array($row)) {
            return ['unavailable' => 'source_unavailable'];
        }

        if (empty($row['eligible']) || !is_string($row['contextRevision'] ?? null)) {
            return ['unavailable' => (string) ($row['reason'] ?? 'verification_unavailable')];
        }

        $receipt = $this->operations->start((object) [
            'tenantId' => $tenantId,
            'contactId' => $contactId,
            'source' => (object) $source,
            'requestKey' => $requestKey,
            'contextRevision' => $row['contextRevision'],
            // Fresh evidence is reused for free; only expired evidence needs a paid refresh.
            'refresh' => (bool) ($row['actions']['requiresPaidRefresh'] ?? false),
            'maxChargeCredits' => Kind::price($kind),
        ], $kind, true);

        return ['operation' => $receipt['operation']];
    }
}
