<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment\Providers;

/** Normalized internal result; values remain restricted until atomic customer purchase. */
final readonly class Result
{
    public function __construct(public string $state, public array $values = [], public ?string $requestId = null,
        public ?string $subjectId = null, public int $retryAfter = 10,
        public array $hidden = [], public int $retentionSeconds = 90 * 86400)
    {
        if (!in_array($state, ['match', 'no_match', 'failed', 'ambiguous', 'pending'], true) ||
            ($state === 'match') !== (count($values) > 0) || ($state === 'pending' && $requestId === null)) {
            throw new \InvalidArgumentException('Invalid supplier result.');
        }
        if ($retentionSeconds < 1 || $retentionSeconds > 90 * 86400 ||
            ($hidden && !in_array($state, ['match', 'no_match'], true))) {
            throw new \InvalidArgumentException('Invalid hidden supplier retention.');
        }
        foreach ($hidden as $product => $items) {
            if (!in_array($product, ['email', 'phone'], true) || !is_array($items) || !array_is_list($items) || !$items) {
                throw new \InvalidArgumentException('Invalid hidden supplier product.');
            }
            foreach ($items as $item) if (!is_string($item) || $item === '') throw new \InvalidArgumentException('Invalid hidden supplier value.');
        }
    }
}
