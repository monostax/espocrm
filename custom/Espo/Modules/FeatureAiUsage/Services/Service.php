<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAiUsage\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Config;
use Espo\Modules\Chatwoot\Tools\Billing\TenantRateLookup;
use Espo\ORM\EntityManager;

class Service
{
    private const MAX_RUNS = 100000;
    private const FIELDS = [
        'id', 'runAt', 'kind', 'agentId', 'chatwootAccountId', 'conversationId', 'opportunityId',
        'chatwootContactId', 'sourceNoteId', 'toolsUsed', 'wasTransferred', 'hadAppointment',
        'createdOpportunity', 'updatedOpportunity', 'deletedOpportunity', 'sentFollowupMessage',
        'usageMetricsVersion', 'model', 'durationMs', 'modelRequestCount', 'inputTokens', 'outputTokens', 'cachedInputTokens', 'runOutcome',
    ];

    public function __construct(
        private Access $access,
        private EntityManager $entityManager,
        private Config $config,
        private TenantRateLookup $rates,
        private Ledger $ledger,
        private Dataset $dataset,
        private Projection $projection,
    ) {}

    public function context(): array
    {
        $period = $this->period('');
        return ['tenants' => $this->access->tenants(), 'currentMonth' => $period->start->format('Y-m'), 'timeZone' => $period->toArray()['timeZone']];
    }

    public function summary(array $query): array
    {
        $tenantId = $this->text($query, 'tenantId');
        $this->access->assertTenant($tenantId);
        $period = $this->period($this->text($query, 'month'));
        $view = $this->text($query, 'view') ?: 'overview';
        if (!in_array($view, ['overview', 'breakdown', 'activity'], true)) {
            throw new BadRequest('Invalid usage view.');
        }
        $filters = $this->filters($query, $period);
        $offset = $this->integer($query, 'offset', 0, 0, self::MAX_RUNS);
        $limit = $this->integer($query, 'limit', 25, 1, 50);
        $book = $this->rates->forTenants([$tenantId]);
        $agreements = $book->periodsFor($tenantId);
        $ledger = $this->load($tenantId, $period, $agreements);
        $filtered = $this->dataset->filter($ledger['runs'], $filters);
        $result = [
            'tenantId' => $tenantId, 'period' => $period->toArray(), 'view' => $view, 'filters' => (object) $filters,
            'billing' => $this->amounts($ledger['summary']), 'rates' => $ledger['contract']['rates'],
            'usage' => $this->dataset->stats($ledger['runs']), 'filteredUsage' => $this->dataset->stats($filtered),
            'generatedAt' => gmdate(DATE_ATOM),
        ];
        if ($view === 'overview') {
            $result['daily'] = array_map(fn ($day) => $this->amounts($day), array_values($ledger['daily']));
            unset($filtered, $ledger); // Do not keep two full tenant-month ledgers in memory.
            $previous = $period->previous();
            $previousLedger = $this->load($tenantId, $previous, $agreements);
            $result['comparison'] = [
                'period' => $previous->toArray(), 'usage' => $this->dataset->stats($previousLedger['runs']),
                'billing' => $this->amounts($previousLedger['summary']),
            ];
        } elseif ($view === 'breakdown') {
            $dimension = $this->text($query, 'dimension') ?: 'kind';
            $breakdown = $this->dataset->breakdown($filtered, $ledger['groups'], $dimension, $offset, $limit);
            $scopes = ['agent' => 'ChatwootAccountUserMembership', 'account' => 'ChatwootAccount', 'conversation' => 'ChatwootConversation', 'opportunity' => 'Opportunity'];
            foreach ($breakdown['list'] as &$row) {
                $row['record'] = isset($scopes[$dimension]) ? $this->projection->record($scopes[$dimension], $row['key']) : null;
                $row['billing'] = $row['billing'] !== null ? $this->amounts($row['billing']) : null;
            }
            unset($row);
            $result['breakdown'] = $breakdown;
        } else {
            $accessible = $this->projection->accessibleIds($tenantId, $period);
            $visible = array_values(array_filter($filtered, fn ($row) => isset($accessible[$row['id']])));
            usort($visible, static fn ($a, $b) => strcmp($b['runAt'], $a['runAt']) ?: strcmp($b['id'], $a['id']));
            $result['activity'] = [
                'total' => count($visible), 'offset' => $offset,
                'list' => array_map(fn ($row) => $this->projection->activity(
                    $row, $ledger['groups'][$row['groupKey']] ?? null,
                ), array_slice($visible, $offset, $limit)),
            ];
        }
        return $result;
    }

    public function detail(string $id, array $query): array
    {
        $tenantId = $this->text($query, 'tenantId');
        $this->access->assertTenant($tenantId);
        $run = $this->entityManager->getEntityById('ChatwootAiAgentRun', $id);
        if (!$run || $run->get('tenantId') !== $tenantId || !$this->projection->canRead($run)) {
            throw new NotFound();
        }
        $period = $this->period($this->text($query, 'month'));
        $ledger = $this->load($tenantId, $period, $this->rates->forTenants([$tenantId])->periodsFor($tenantId));
        foreach ($ledger['runs'] as $row) {
            if ($row['id'] !== $id) {
                continue;
            }
            $group = $ledger['groups'][$row['groupKey']] ?? null;
            return [
                'activity' => $this->projection->activity($row, $group),
                'billingGroup' => $group ? [
                    'day' => $group['day'], 'runs' => $group['runs'],
                    'billing' => isset($group['billing']) ? $this->amounts($group['billing']) : null,
                    'rate' => $group['rate'] ?? null,
                ] : null,
            ];
        }
        throw new NotFound();
    }

    private function load(string $tenantId, Period $period, array $agreements): array
    {
        return $this->ledger->build($this->runs($tenantId, $period), $period, $agreements, $tenantId);
    }

    private function runs(string $tenantId, Period $period): \Generator
    {
        // Explicit tenant admin gate precedes this aggregate read. No relationship joins can multiply runs.
        $rows = $this->entityManager->getRDBRepository('ChatwootAiAgentRun')->select(self::FIELDS)
            ->where(['tenantId' => $tenantId, 'runAt>=' => $period->utcStart(), 'runAt<' => $period->utcCutoff()])
            ->order('runAt')->limit(0, self::MAX_RUNS + 1)->sth()->find();
        $count = 0;
        foreach ($rows as $row) {
            if ($count++ === self::MAX_RUNS) {
                throw new BadRequest('This tenant-month exceeds the usage query limit. No partial billing total was returned.');
            }
            $values = [];
            foreach (self::FIELDS as $field) {
                $values[$field] = $row->get($field);
            }
            yield $values;
        }
    }

    private function period(string $month): Period
    {
        return Period::create($month, (string) $this->config->get('timeZone', 'UTC'));
    }

    private function filters(array $query, Period $period): array
    {
        $filters = [];
        foreach (Dataset::FILTERS as $key) {
            $value = $this->text($query, $key);
            if ($value === '') {
                continue;
            }
            if (in_array($key, ['from', 'to'], true)) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value || substr($value, 0, 7) !== $period->start->format('Y-m')) {
                    throw new BadRequest('Filter dates must be within the selected month.');
                }
            }
            $filters[$key] = $value;
        }
        if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
            throw new BadRequest('The start date must precede the end date.');
        }
        return $filters;
    }

    private function text(array $query, string $key): string
    {
        $value = $query[$key] ?? '';
        if (!is_string($value) || strlen($value) > 200) {
            throw new BadRequest('Invalid ' . $key . '.');
        }
        return trim($value);
    }

    private function integer(array $query, string $key, int $default, int $min, int $max): int
    {
        if (!isset($query[$key])) {
            return $default;
        }
        $value = filter_var($query[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            throw new BadRequest('Invalid ' . $key . '.');
        }
        return $value;
    }

    private function amounts(array $data): array
    {
        if (isset($data['charges'])) {
            $charges = [];
            foreach ($data['charges'] as $currency => $amounts) {
                $charges[] = ['currency' => $currency] + $amounts;
            }
            $data['charges'] = $charges;
        }
        return $data;
    }
}
