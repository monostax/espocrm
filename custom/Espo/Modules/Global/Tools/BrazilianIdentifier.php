<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Tools;

/** Structural validation only; does not establish ownership or registration. */
final class BrazilianIdentifier
{
    public static function cpf(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        if (!preg_match('/\A(?:[0-9]{11}|[0-9]{3}\.[0-9]{3}\.[0-9]{3}-[0-9]{2})\z/', $value)) {
            return null;
        }
        $value = str_replace(['.', '-'], '', $value);
        if (preg_match('/\A([0-9])\1{10}\z/', $value)) return null;
        $base = substr($value, 0, 9);
        $base .= self::digit($base, range(10, 2));
        $base .= self::digit($base, range(11, 2));
        return $base === $value ? $value : null;
    }

    public static function cnpj(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = strtoupper(trim($value));
        if (!preg_match('/\A(?:[A-Z0-9]{12}[0-9]{2}|[A-Z0-9]{2}\.[A-Z0-9]{3}\.[A-Z0-9]{3}\/[A-Z0-9]{4}-[0-9]{2})\z/', $value)) {
            return null;
        }
        $value = str_replace(['.', '/', '-'], '', $value);
        if (preg_match('/\A([0-9])\1{13}\z/', $value)) return null;
        // Receita Federal's alphanumeric algorithm: character ASCII value minus 48.
        // This also preserves the existing numeric CNPJ checksum.
        $base = substr($value, 0, 12);
        $base .= self::digit($base, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        $base .= self::digit($base, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        return $base === $value ? $value : null;
    }

    /** @param list<int> $weights */
    private static function digit(string $base, array $weights): string
    {
        $sum = 0;
        foreach ($weights as $i => $weight) $sum += (ord($base[$i]) - 48) * $weight;
        $remainder = $sum % 11;
        return (string) ($remainder < 2 ? 0 : 11 - $remainder);
    }
}
