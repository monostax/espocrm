<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Utils\Language;
use Espo\Core\Utils\Metadata;
use InvalidArgumentException;

/** CRM values stay in quotes; provider vocabulary is applied only at dispatch. */
final class EmployeeFilters
{
    // Apollo has no board-member seniority. Use the Board Member title instead.
    private const APOLLO_JOB_LEVELS = [
        'Founder' => 'founder',
        'CXO' => 'c_suite',
        'SVP' => 'vp',
        'VP' => 'vp',
        'Executive Director' => 'director',
        'Senior Director' => 'director',
        'Director' => 'director',
        'General Manager' => 'manager',
        'Head' => 'head',
        'Associate Director' => 'director',
        'Senior Manager' => 'manager',
        'Manager' => 'manager',
        'Principal' => 'senior',
        'Lead' => 'manager',
        'Senior' => 'senior',
        'Individual Contributor' => 'entry',
    ];

    private const APOLLO_SENIORITIES = [
        'owner', 'founder', 'c_suite', 'partner', 'vp', 'head', 'director', 'manager', 'senior', 'entry', 'intern',
    ];

    public function __construct(private Metadata $metadata, private Language $language) {}

    public function options(): array
    {
        // Contact.title uses Account.contactRole for its native autocomplete.
        $titles = $this->metadata->get(['entityDefs', 'Account', 'fields', 'contactRole', 'options']) ?? [];
        $levels = $this->metadata->get(['entityDefs', 'Contact', 'fields', 'jobLevel', 'options']) ?? [];
        return [
            'titles' => $this->field('title', array_values(array_filter($titles))),
            'jobLevels' => $this->field('jobLevel', array_values(array_filter($levels,
                static fn (string $value): bool => isset(self::APOLLO_JOB_LEVELS[$value])))),
        ];
    }

    private function field(string $field, array $values): array
    {
        return [
            'field' => $field,
            'label' => $this->language->translateLabel($field, 'fields', 'Contact'),
            'options' => array_map(fn (string $value): array => [
                'value' => $value,
                'label' => $this->language->translateOption($value, $field, 'Contact'),
            ], $values),
        ];
    }

    public static function apollo(array $filters): array
    {
        if (array_diff(array_keys($filters), ['titles', 'jobLevels', 'seniorities'])) {
            throw new InvalidArgumentException('Unsupported employee filters.');
        }
        // Keep previously accepted quotes/native clients using Apollo codes resumable.
        $seniorities = $filters['seniorities'] ?? [];
        if (array_diff($seniorities, self::APOLLO_SENIORITIES)) {
            throw new InvalidArgumentException('Unsupported employee seniority.');
        }
        foreach ($filters['jobLevels'] ?? [] as $level) {
            if (!isset(self::APOLLO_JOB_LEVELS[$level])) {
                throw new InvalidArgumentException('Unsupported employee job level.');
            }
            $seniorities[] = self::APOLLO_JOB_LEVELS[$level];
        }
        $query = [];
        if ($filters['titles'] ?? []) $query['person_titles'] = array_values(array_unique($filters['titles']));
        if ($seniorities) $query['person_seniorities'] = array_values(array_unique($seniorities));
        return $query;
    }
}
