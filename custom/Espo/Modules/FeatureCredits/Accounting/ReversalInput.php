<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use InvalidArgumentException;

/** Privileged correction of a posted usage debit or native overrun, never a replacement grant. */
final readonly class ReversalInput
{
    public string $credits;
    public string $hash;

    public function __construct(
        public string $tenantId,
        public string $transactionId,
        public string $idempotencyKey,
        mixed $credits,
        public string $reason,
        public string $actorId,
    ) {
        foreach ([$tenantId, $transactionId, $actorId] as $id) GrantInput::identity($id);
        if (!preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,127}$/D', $idempotencyKey)) {
            throw new InvalidArgumentException('Canonical reversal key required.');
        }
        if (trim($reason) === '' || strlen($reason) > 1000 || preg_match('/[\x00-\x1f]/', $reason)) {
            throw new InvalidArgumentException('A bounded reversal reason is required.');
        }
        $amount = Amount::fromString($credits);
        if ($amount->compareTo(Amount::fromString('0')) <= 0) {
            throw new InvalidArgumentException('Reversal credits must be positive.');
        }
        $this->credits = (string) $amount;
        $this->hash = hash('sha256', json_encode([
            'usage-reversal-v1', $tenantId, $transactionId, $idempotencyKey, $this->credits, $reason, $actorId,
        ], JSON_THROW_ON_ERROR));
    }
}
