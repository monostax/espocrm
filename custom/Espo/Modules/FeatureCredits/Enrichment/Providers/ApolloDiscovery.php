<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment\Providers;

use Espo\Modules\FeatureCredits\Enrichment\{CandidateProjection, DiscoverySupplier, DiscoveryResult, EmployeeFilters, Quotes};
use Espo\Modules\Global\Tools\LinkedInProfile;

/** Every resolver/search/completion is one separately claimed request. */
final class ApolloDiscovery implements DiscoverySupplier
{
    public function __construct(private Credentials $credentials, private Transport $transport) {}
    public function retentionSeconds(string $product): int { return 90 * 86400; }

    public function revision(string $tenantId, string $targetType, string $product, array $identity): ?string
    {
        $connection = $this->credentials->get('apollo');
        if (!$connection || $targetType !== 'Account' || $product !== 'employees' || LinkedInProfile::profile('Account', $identity['linkedinUrl'] ?? null) === null) return null;
        return Quotes::hash(['apollo-company-search-crm-filters-v2', 'retention-reuse-approved-max90d', $tenantId, $connection, $this->retentionSeconds($product)]);
    }

    public function execute(array $request, int $timeoutMs): DiscoveryResult
    {
        $profile = LinkedInProfile::profile('Account', $request['identity']['linkedinUrl'] ?? null);
        $connection = $this->credentials->get('apollo');
        if (!$connection || $profile === null || ($request['product'] ?? null) !== 'employees') return new DiscoveryResult('failed');
        $filters = $request['filters'] ?? [];
        if (!is_array($filters)) return new DiscoveryResult('failed');
        try { $filterQuery = EmployeeFilters::apollo($filters); }
        catch (\InvalidArgumentException) { return new DiscoveryResult('failed'); }
        $headers = ['x-api-key: ' . $connection['key']];
        if (($request['kind'] ?? null) === 'completion') {
            $token = self::decode($request['input']['completionToken'] ?? null);
            if (!self::id($token['person'] ?? null) || !self::id($token['organization'] ?? null) ||
                ($request['input']['candidateKey'] ?? null) !== self::key($token['person'])) return new DiscoveryResult('failed');
            $response = $this->transport->send('apollo', 'POST', '/api/v1/people/match', ['id' => $token['person'],
                'reveal_personal_emails' => 'false', 'reveal_phone_number' => 'false',
                'run_waterfall_email' => 'false', 'run_waterfall_phone' => 'false'], $headers, $timeoutMs);
            if ($failure = self::failure($response)) return $failure;
            if (!array_key_exists('person', $response->data)) return new DiscoveryResult('unknown');
            $person = $response->data['person'];
            if ($person === null) return new DiscoveryResult('no_match');
            if (!is_array($person) || ($person['id'] ?? null) !== $token['person']) return new DiscoveryResult('unknown');
            $item = self::employee($person, $profile, $token['organization']);
            return new DiscoveryResult($item ? 'succeeded' : 'no_match', $item ? [$item] : []);
        }
        if (($request['kind'] ?? null) !== 'page') return new DiscoveryResult('failed');
        $cursor = $request['input']['cursor'] ?? null;
        if ($cursor === null) {
            // Search accepts organization IDs, not LinkedIn URLs. Persist resolution
            // as a zero-candidate page so a crash cannot repeat this supplier expense.
            $response = $this->transport->send('apollo', 'GET', '/api/v1/organizations/enrich', ['linkedin_url' => $profile], $headers, $timeoutMs);
            if ($failure = self::failure($response)) return $failure;
            if (!array_key_exists('organization', $response->data)) return new DiscoveryResult('unknown');
            $org = $response->data['organization'];
            if ($org === null) return new DiscoveryResult('no_match');
            if (!is_array($org) || !self::id($org['id'] ?? null) || LinkedInProfile::profile('Account', $org['linkedin_url'] ?? null) !== $profile) return new DiscoveryResult('unknown');
            return new DiscoveryResult('succeeded', [], self::encode(['organization' => $org['id'], 'page' => 1]));
        }
        $cursor = self::decode($cursor);
        if (!self::id($cursor['organization'] ?? null) || !is_int($cursor['page'] ?? null) || $cursor['page'] < 1 || $cursor['page'] > 5) return new DiscoveryResult('failed');
        $query = ['organization_ids' => [$cursor['organization']], 'page' => $cursor['page'], 'per_page' => 100,
            'include_similar_titles' => 'true', ...$filterQuery];
        $response = $this->transport->send('apollo', 'POST', '/api/v1/mixed_people/api_search', $query, $headers, $timeoutMs);
        if ($failure = self::failure($response)) return $failure;
        $people = $response->data['people'] ?? null; $total = $response->data['total_entries'] ?? null;
        if (!is_array($people) || !array_is_list($people) || count($people) > 100 || !is_int($total) || $total < 0) return new DiscoveryResult('unknown');
        $items = [];
        foreach ($people as $person) {
            if (!is_array($person) || !self::id($person['id'] ?? null)) return new DiscoveryResult('unknown');
            $item = self::employee($person, $profile, $cursor['organization']);
            $items[] = $item ?? ['candidateKey' => self::key($person['id']),
                'completionToken' => self::encode(['person' => $person['id'], 'organization' => $cursor['organization']])];
        }
        $next = $people && $total > $cursor['page'] * 100 ? self::encode(['organization' => $cursor['organization'], 'page' => $cursor['page'] + 1]) : null;
        return new DiscoveryResult($items || $next !== null ? 'succeeded' : 'no_match', $items, $next);
    }

    private static function employee(array $person, string $profile, string $organization): ?array
    {
        $linkedin = LinkedInProfile::profile('Contact', $person['linkedin_url'] ?? null);
        if ($linkedin === null || !is_string($person['name'] ?? null) || trim($person['name']) === '' ||
            !is_array($person['organization'] ?? null) || ($person['organization']['id'] ?? null) !== $organization ||
            LinkedInProfile::profile('Account', $person['organization']['linkedin_url'] ?? null) !== $profile ||
            !is_array($person['employment_history'] ?? null)) return null;
        $current = false;
        foreach ($person['employment_history'] as $job) {
            if (is_array($job) && ($job['organization_id'] ?? null) === $organization && ($job['current'] ?? null) === true && empty($job['end_date'])) $current = true;
        }
        if (!$current) return null;
        $values = ['name' => $person['name'], 'linkedinUrl' => $linkedin, 'currentEmployment' => true];
        foreach (['title', 'seniority'] as $field) if (is_string($person[$field] ?? null) && trim($person[$field]) !== '') $values[$field] = $person[$field];
        try { $values = CandidateProjection::normalize('employees', $values); }
        catch (\InvalidArgumentException) { return null; }
        return ['candidateKey' => self::key($person['id']), 'values' => $values, 'naturalPerson' => true];
    }

    private static function failure(Response $response): ?DiscoveryResult
    {
        if (in_array($response->status, [400, 401, 403, 422, 429], true)) return new DiscoveryResult('failed');
        if ($response->status !== 200 || $response->data === null) return new DiscoveryResult('unknown');
        if (isset($response->data['error']) || isset($response->data['error_details'])) return new DiscoveryResult('failed');
        return null;
    }

    private static function id(mixed $id): bool { return is_string($id) && (bool) preg_match('/\A[a-f0-9]{24}\z/', $id); }
    private static function key(string $id): string { return Quotes::hash(['apollo', $id]); }
    private static function encode(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
    private static function decode(mixed $value): array
    {
        if (!is_string($value)) return [];
        try { $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return []; }
        return is_array($decoded) ? $decoded : [];
    }
}
