<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Tools;

use Espo\Core\Exceptions\BadRequest;

class Evidence
{
    /** Optional evidence must be omitted as a whole, never partially supplied. */
    public static function provided(mixed $revision, mixed $quote, mixed $start = null, mixed $end = null, bool $required = true): bool
    {
        if ($revision === null && $quote === null && $start === null && $end === null && !$required) return false;
        if (!is_string($revision) || $revision === '' || !is_string($quote) || $quote === '') {
            throw new BadRequest('A source revision and exact quote are required when providing evidence.');
        }
        return true;
    }

    /** Spans are UTF-8 byte offsets, end-exclusive, against the exact immutable source. */
    public static function validate(string $body, mixed $quote, mixed $start = null, mixed $end = null): array
    {
        if (!is_string($quote) || $quote === '' || strlen($quote) > 16000) throw new BadRequest('Evidence quote required (max 16 KB).');
        if ($start === null && $end === null) {
            $span = self::anchor($body, $quote);
            if ($span === null) throw new BadRequest('Evidence is missing or ambiguous; supply an exact span.');
            return $span;
        }
        if (!is_int($start) || !is_int($end) || $start < 0 || $end <= $start ||
            substr($body, $start, $end - $start) !== $quote) throw new BadRequest('Evidence span does not match the revision.');
        return [$start, $end];
    }

    public static function anchor(string $body, string $quote): ?array
    {
        if ($quote === '') return null;
        $start = strpos($body, $quote);
        if ($start === false || strpos($body, $quote, $start + 1) !== false) return null;
        return [$start, $start + strlen($quote)];
    }
}
