<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Modules\Global\Tools\{BrazilianIdentifier,LinkedInProfile};
use InvalidArgumentException;

/** Server-normalized Finder data. This is not a supplier response parser. */
final class CandidateProjection
{
    public static function normalize(string $product, array $values): array
    {
        $allowed = match ($product) {
            'employees' => ['name','linkedinUrl','title','department','seniority','currentEmployment'],
            'socios' => ['name','cpf','linkedinUrl','role','ownershipPercentage','since','until'],
            'cnpj' => ['cnpj'],
            default => throw new InvalidArgumentException('Unsupported discovery projection.'),
        };
        if (!$values || array_diff(array_keys($values),$allowed)) throw new InvalidArgumentException('Only purchased-visible contribution fields are allowed.');
        foreach ($values as $field => &$value) {
            if ($field === 'currentEmployment') {
                if ($value !== true) throw new InvalidArgumentException('Employee relationship must be current.');
                continue;
            }
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/',$value)) {
                throw new InvalidArgumentException('Invalid purchased value.');
            }
            $value = trim($value);
            if ($field === 'cpf') $value = BrazilianIdentifier::cpf($value);
            if ($field === 'cnpj') $value = BrazilianIdentifier::cnpj($value);
            if ($field === 'linkedinUrl') $value = LinkedInProfile::profile('Contact',$value);
            if ($value === null) throw new InvalidArgumentException('Invalid purchased identity.');
            if ($field === 'ownershipPercentage') {
                if (!preg_match('/\A(?:\d{1,2}(?:\.\d{1,4})?|100(?:\.0{1,4})?)\z/',$value)) {
                    throw new InvalidArgumentException('Invalid ownership percentage.');
                }
                $value = rtrim(rtrim(number_format((float) $value,4,'.',''),'0'),'.');
            }
            if (in_array($field,['since','until'],true)) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d',$value);
                if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Invalid relationship date.');
            }
        }
        unset($value);
        if ($product === 'employees' && (!isset($values['name']) || ($values['currentEmployment'] ?? false) !== true)) {
            throw new InvalidArgumentException('Employee identity and current relationship required.');
        }
        if (isset($values['since'],$values['until']) && $values['since'] > $values['until']) {
            throw new InvalidArgumentException('Invalid relationship interval.');
        }
        ksort($values);
        return $values;
    }

    /** Formatting alone is never a useful second contribution. Identity fields are already canonical. */
    public static function equivalent(mixed $left, mixed $right): bool
    {
        if (!is_string($left) || !is_string($right)) return $left === $right;
        return mb_strtolower(preg_replace('/\s+/u',' ',trim($left))) ===
            mb_strtolower(preg_replace('/\s+/u',' ',trim($right)));
    }
}
