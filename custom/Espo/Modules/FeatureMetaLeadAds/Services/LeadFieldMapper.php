<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Services;

use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadForm;
use stdClass;

/**
 * Maps Meta Lead Ads field_data into a Contact attribute set.
 *
 * Meta's GET /{leadgenId} returns:
 *   {
 *     "field_data": [
 *       {"name": "email", "values": ["jane@example.com"]},
 *       {"name": "full_name", "values": ["Jane Doe"]},
 *       {"name": "phone_number", "values": ["+5511999999999"]},
 *       ...
 *     ]
 *   }
 *
 * Built-in defaults cover Meta's standard system fields. Custom-form fields
 * (the admin can rename them in Ads Manager) are handled via per-form
 * `fieldMapping` JSON, which is MERGED ON TOP of the defaults.
 *
 * Multi-tenancy note: each tenant has its own MariaDB database (k8s
 * namespace isolation). Dedup queries against Contact (by email/phone/
 * metaLeadId) therefore are naturally scoped to the tenant — no extra
 * tenant_id check needed. The fieldMapping is also per-form per-tenant.
 *
 * No state, no DI — safe to instantiate via `new`.
 */
class LeadFieldMapper
{
    /**
     * Default mapping for Meta's standard Lead Ads system fields →
     * EspoCRM Contact attributes.
     *
     * Reference: https://developers.facebook.com/docs/marketing-api/guides/lead-ads/retrieving
     *
     * Notes:
     *  - `full_name` is split into `firstName` + `lastName` when first/last
     *    are absent.
     *  - `phone_number` is normalized into both `phoneNumberData[0].phoneNumber`
     *    (E.164-ish) and the deprecated `phoneNumber` field.
     *  - `company_name` becomes `accountName` (free-text on Contact).
     *
     * @var array<string, string>
     */
    private const DEFAULT_MAP = [
        // Identity
        'email'                  => 'emailAddress',
        'work_email'             => 'emailAddress',
        'phone_number'           => 'phoneNumber',
        'work_phone_number'      => 'phoneNumber',

        // Name
        'first_name'             => 'firstName',
        'last_name'              => 'lastName',
        'full_name'              => '__fullName__',

        // Organization
        'company_name'           => 'accountName',
        'job_title'              => 'title',

        // Address (city kept; we deliberately ignore street/state/zip from
        // Meta because Contact has no street field by default and that's
        // best modeled per project)
        'city'                   => 'addressCity',
    ];

    /**
     * Build a Contact attribute set from Meta's field_data + form mapping.
     *
     * @param array<int, array{name: string, values: array<int, string>}> $fieldData
     * @return array<string, mixed>  Contact attributes, ready for `entity->set(...)`.
     */
    public function map(array $fieldData, MetaLeadForm $form): array
    {
        $merged = $this->effectiveMap($form);

        $attrs = [];
        $fullName = null;

        foreach ($fieldData as $field) {
            $metaName = $field['name'] ?? null;
            $values   = $field['values'] ?? [];

            if (!is_string($metaName) || $metaName === '' || !is_array($values) || $values === []) {
                continue;
            }

            $value = trim((string) $values[0]);
            if ($value === '') {
                continue;
            }

            $targetAttr = $merged[$metaName] ?? null;

            if ($targetAttr === null) {
                continue;
            }

            // Special-case: full_name needs splitting.
            if ($targetAttr === '__fullName__') {
                $fullName = $value;
                continue;
            }

            $attrs[$targetAttr] = $this->castValue($targetAttr, $value);
        }

        // Apply full_name fallback only if first/last weren't provided directly.
        if ($fullName !== null) {
            if (!isset($attrs['firstName']) && !isset($attrs['lastName'])) {
                [$first, $last] = $this->splitFullName($fullName);
                if ($first !== null) {
                    $attrs['firstName'] = $first;
                }
                if ($last !== null) {
                    $attrs['lastName'] = $last;
                }
            }
        }

        return $attrs;
    }

    /**
     * Merge per-form override map ON TOP of built-in defaults.
     *
     * @return array<string, string>
     */
    private function effectiveMap(MetaLeadForm $form): array
    {
        $defaults = self::DEFAULT_MAP;
        $override = $form->get('fieldMapping');

        if (!$override instanceof stdClass && !is_array($override)) {
            return $defaults;
        }

        if ($override instanceof stdClass) {
            $override = (array) $override;
        }

        // Normalize: keep only string→string entries.
        $cleanOverride = [];
        foreach ($override as $metaName => $target) {
            if (is_string($metaName) && is_string($target) && $metaName !== '' && $target !== '') {
                $cleanOverride[$metaName] = $target;
            }
        }

        return array_merge($defaults, $cleanOverride);
    }

    /**
     * Light value normalization for known target attributes.
     */
    private function castValue(string $targetAttr, string $value): string
    {
        if ($targetAttr === 'emailAddress') {
            return strtolower($value);
        }

        if ($targetAttr === 'phoneNumber') {
            // Strip whitespace; keep '+' / digits / parentheses for human readability.
            return preg_replace('/\s+/', '', $value) ?? $value;
        }

        return $value;
    }

    /**
     * Split a full name into (first, last) heuristically.
     *
     * - "Jane"            → ["Jane", null]
     * - "Jane Doe"        → ["Jane", "Doe"]
     * - "Jane Mary Doe"   → ["Jane", "Mary Doe"]
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitFullName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        $parts = array_values(array_filter($parts, fn($p) => $p !== ''));

        if ($parts === []) {
            return [null, null];
        }

        if (count($parts) === 1) {
            return [$parts[0], null];
        }

        $first = array_shift($parts);

        return [$first, implode(' ', $parts)];
    }
}
