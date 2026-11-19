<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Classes\AutomationActions;

/**
 * Which contact value a verification or channel action works on.
 *
 * `enrichmentResult` is the found value that started the run: the `enrichment.found`
 * signal puts `{attemptId, product, index}` on the trigger payload, never the value
 * itself. The value is resolved server-side from the encrypted enrichment receipt, as
 * the run-as user, at the moment it is needed (so an erased receipt stops the action).
 */
final class ContactChannelSource
{
    public const ENRICHMENT_RESULT = 'enrichmentResult';
    public const CONTACT_PRIMARY = 'contactPrimary';

    /**
     * The enrichment result on the trigger payload, or null when the run was not
     * started by a found enrichment value.
     *
     * @param array<string, mixed> $payload
     * @return array{attemptId: string, product: string, index: int}|null
     */
    public static function enrichmentResult(array $payload): ?array
    {
        $trigger = $payload['_trigger'] ?? null;
        $result = is_array($trigger) ? ($trigger['enrichment'] ?? null) : null;
        if (!is_array($result)) {
            return null;
        }

        $attemptId = $result['attemptId'] ?? null;
        $product = $result['product'] ?? null;
        $index = $result['index'] ?? null;

        if (!is_string($attemptId) || !preg_match('/\A[A-Za-z0-9]{1,24}\z/', $attemptId) ||
            !in_array($product, ['email', 'phone'], true) ||
            !is_int($index) || $index < 0 || $index > 100) {
            return null;
        }

        return ['attemptId' => $attemptId, 'product' => $product, 'index' => $index];
    }

    /**
     * Verification source descriptor for `product` ('email' | 'phone'), or null when the
     * chosen source has no value of that kind (e.g. an email check on a found phone).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public static function descriptor(string $source, string $product, array $payload): ?array
    {
        if ($source === self::CONTACT_PRIMARY) {
            return ['kind' => $product === 'email' ? 'contact_email' : 'contact_phone'];
        }

        if ($source !== self::ENRICHMENT_RESULT) {
            return null;
        }

        $result = self::enrichmentResult($payload);
        if ($result === null || $result['product'] !== $product) {
            return null;
        }

        return ['kind' => 'purchased', 'receiptId' => $result['attemptId'], 'index' => $result['index']];
    }

    /** Payload keys follow PayloadBag segments: letters, digits, underscore. */
    public static function key(mixed $as, string $fallback): string
    {
        $key = is_string($as) ? trim($as) : '';

        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/', $key) ? $key : $fallback;
    }
}
