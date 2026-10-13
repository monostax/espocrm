<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use InvalidArgumentException;

/** Adapter-normalized response, never a client API payload. Items preserve supplier order. */
final class DiscoveryResult
{
    public function __construct(public readonly string $state,public readonly array $items = [],public readonly ?string $nextCursor = null)
    {
        if (!in_array($state,['succeeded','no_match','failed','unknown'],true) || !array_is_list($items) ||
            ($state !== 'succeeded' && ($items || $nextCursor !== null))) throw new InvalidArgumentException('Invalid discovery outcome.');
        if ($nextCursor !== null && ($nextCursor === '' || strlen($nextCursor) > 2048)) throw new InvalidArgumentException('Invalid supplier cursor.');
        if (strlen(json_encode([$items,$nextCursor],JSON_THROW_ON_ERROR)) > 2*1024*1024) throw new InvalidArgumentException('Discovery response exceeds transport bound.');
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['candidateKey'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/',$item['candidateKey'])) {
                throw new InvalidArgumentException('Stable supplier candidate key required.');
            }
            if (array_key_exists('completionToken',$item)) {
                if (array_diff(array_keys($item),['candidateKey','completionToken']) || !is_string($item['completionToken']) ||
                    $item['completionToken'] === '' || strlen($item['completionToken']) > 2048) throw new InvalidArgumentException('Invalid completion identity.');
            } elseif (array_diff(array_keys($item),['candidateKey','values','incidental','naturalPerson']) ||
                !is_array($item['values'] ?? null) || !is_array($item['incidental'] ?? []) || !is_bool($item['naturalPerson'] ?? false)) {
                throw new InvalidArgumentException('Invalid normalized candidate.');
            }
        }
    }

    public function hash(): string { return Quotes::hash([$this->state,$this->items,$this->nextCursor]); }
}
