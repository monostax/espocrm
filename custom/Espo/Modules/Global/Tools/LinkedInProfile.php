<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Tools;

/** Match a record's own profile; never infer a person's employer profile or vice versa. */
final class LinkedInProfile
{
    public static function profile(string $type, mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        $url = parse_url(trim($value));
        if (!is_array($url) || !in_array($url['scheme'] ?? null,['https','http'],true) || isset($url['user']) || isset($url['pass']) || isset($url['port'])) return null;
        $host = strtolower($url['host'] ?? '');
        if (!preg_match('/^(?:www\.|[a-z]{2}\.)?linkedin\.com$/D',$host)) return null;
        $prefix = match ($type) { 'Contact' => 'in', 'Account' => 'company', default => null };
        if ($prefix === null || !preg_match('~^/' . $prefix . '/[A-Za-z0-9_%.-]+/?$~D',$url['path'] ?? '')) return null;
        return 'https://www.linkedin.com' . rtrim($url['path'],'/');
    }
}
