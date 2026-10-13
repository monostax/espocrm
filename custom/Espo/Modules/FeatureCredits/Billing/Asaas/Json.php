<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing\Asaas;

/** Preserve provider number lexemes as decimal strings before PHP can round a money value. */
final class Json
{
    public static function decode(string $raw): array
    {
        json_decode($raw, true, 32, JSON_THROW_ON_ERROR); // Validate the original JSON grammar first.
        $quoted = preg_replace_callback('/("(?:[^"\\\\]|\\\\.)*")|(-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/s',
            static fn (array $m): string => $m[1] !== '' ? $m[1] : json_encode($m[2], JSON_THROW_ON_ERROR), $raw);
        $data = json_decode($quoted, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) throw new \InvalidArgumentException('Expected provider JSON object.');
        return $data;
    }
}
