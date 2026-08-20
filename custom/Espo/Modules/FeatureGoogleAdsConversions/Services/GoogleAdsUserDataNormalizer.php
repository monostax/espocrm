<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Services;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Normalizes and SHA-256 hashes Contact identifiers for Data Manager UserData.
 */
class GoogleAdsUserDataNormalizer
{
    private const MAX_IDENTIFIERS = 10;

    public function __construct(private EntityManager $entityManager) {}

    /**
     * @return array{userIdentifiers: list<array<string, mixed>>}|array{}
     */
    public function normalize(Entity $contact): array
    {
        $identifiers = [];

        foreach ($this->emails($contact) as $email) {
            $normalized = $this->normalizeEmail($email);

            if ($normalized !== null) {
                $identifiers[] = ['emailAddress' => hash('sha256', $normalized)];
            }
        }

        foreach ($this->phones($contact) as $phone) {
            $normalized = $this->normalizePhone($phone);

            if ($normalized !== null) {
                $identifiers[] = ['phoneNumber' => hash('sha256', $normalized)];
            }
        }

        $identifiers = array_slice($this->unique($identifiers), 0, self::MAX_IDENTIFIERS);

        return $identifiers === [] ? [] : ['userIdentifiers' => $identifiers];
    }

    /** @return list<string> */
    private function emails(Entity $contact): array
    {
        $values = $this->valuesFromData($contact->get('emailAddressData'), 'emailAddress');
        $primary = $contact->get('emailAddress');

        if (is_string($primary)) {
            array_unshift($values, $primary);
        }

        return $this->withRelatedValues($contact, 'emailAddresses', $values);
    }

    /** @return list<string> */
    private function phones(Entity $contact): array
    {
        $values = $this->valuesFromData($contact->get('phoneNumberData'), 'phoneNumber');
        $primary = $contact->get('phoneNumber');

        if (is_string($primary)) {
            array_unshift($values, $primary);
        }

        return $this->withRelatedValues($contact, 'phoneNumbers', $values);
    }

    /**
     * @return list<string>
     */
    private function valuesFromData(mixed $data, string $key): array
    {
        if (!is_array($data)) {
            return [];
        }

        $values = [];

        foreach ($data as $row) {
            $value = is_array($row)
                ? ($row[$key] ?? null)
                : (is_object($row) ? ($row->{$key} ?? null) : null);

            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function withRelatedValues(Entity $contact, string $link, array $values): array
    {
        try {
            if ($contact->hasId()) {
                $related = $this->entityManager
                    ->getRDBRepository($contact->getEntityType())
                    ->getRelation($contact, $link)
                    ->find();

                foreach ($related as $row) {
                    $name = $row->get('name');

                    if (is_string($name)) {
                        $values[] = $name;
                    }
                }
            }
        } catch (Throwable) {
            // The primary field remains usable if a relation is unavailable.
        }

        return array_values(array_unique(array_filter(array_map('trim', $values))));
    }

    private function normalizeEmail(string $value): ?string
    {
        $value = mb_strtolower(preg_replace('/\s+/u', '', trim($value)) ?? '');

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        [$local, $domain] = explode('@', $value, 2);

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = explode('+', $local, 2)[0];
            $local = str_replace('.', '', $local);
            $value = $local . '@' . $domain;
        }

        return $value;
    }

    private function normalizePhone(string $value): ?string
    {
        $value = trim($value);

        if (!str_starts_with($value, '+')) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+' . $digits : null;
    }

    /**
     * @param list<array<string, mixed>> $identifiers
     * @return list<array<string, mixed>>
     */
    private function unique(array $identifiers): array
    {
        $result = [];
        $seen = [];

        foreach ($identifiers as $identifier) {
            $key = json_encode($identifier);

            if (!is_string($key) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $identifier;
        }

        return $result;
    }
}
