<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use stdClass;

/** One completed authoritative lookup that could not recover usage, not a job dispatch or timeout. */
final readonly class ReconciliationInput
{
    public string $json;
    public string $hash;

    public function __construct(
        public string $tenantId,
        public string $usageId,
        public string $executionId,
        public string $requestId,
        public string $attemptKey,
        public string $operator,
        public string $observedAt,
        public ReconciliationPolicy $policy,
        stdClass $evidence,
    ) {
        foreach ([$tenantId, $usageId, $requestId] as $id) {
            GrantInput::identity($id);
        }
        foreach ([$executionId, $attemptKey, $operator] as $key) {
            ReconciliationPolicy::key($key);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $observedAt, new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $observedAt || $operator !== $policy->operator) {
            throw new InvalidArgumentException('Valid UTC observation time and configured operator required.');
        }
        foreach (['source', 'reference', 'reason'] as $field) {
            if (!isset($evidence->$field) || !is_string($evidence->$field) || trim($evidence->$field) === '' ||
                strlen($evidence->$field) > 512) {
                throw new InvalidArgumentException('Lookup source, unique evidence reference and unavailability reason required.');
            }
        }
        $this->json = json_encode(GrantInput::canonical((object) [
            'version' => 'ai-reconciliation-v1', 'tenantId' => $tenantId, 'usageId' => $usageId,
            'executionId' => $executionId, 'requestId' => $requestId, 'attemptKey' => $attemptKey,
            'operator' => $operator, 'observedAt' => $observedAt,
            'policy' => (object) get_object_vars($policy), 'evidence' => $evidence,
        ]), JSON_THROW_ON_ERROR);
        $this->hash = hash('sha256', $this->json);
    }
}
