<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAiUsage\Services;

use Espo\Core\Exceptions\BadRequest;

/** Pure read models. Action categories overlap; amounts only sum complete billing grains. */
class Dataset
{
    public const ACTIONS = [
        'wasTransferred' => 'transfer', 'hadAppointment' => 'appointments',
        'createdOpportunity' => 'create_opportunity', 'updatedOpportunity' => 'update_opportunity',
        'deletedOpportunity' => 'delete_opportunity', 'sentFollowupMessage' => 'send_followup_message',
    ];
    public const FILTERS = ['kind', 'action', 'agentId', 'chatwootAccountId', 'conversationId', 'opportunityId', 'from', 'to'];
    public const DIMENSIONS = ['kind', 'action', 'agent', 'account', 'conversation', 'opportunity'];

    public static function actions(array $run): array
    {
        $tools = $run['toolsUsed'] ?? [];
        if (is_string($tools)) {
            $tools = json_decode($tools, true);
        }
        $actions = array_filter(is_array($tools) ? $tools : [], 'is_string');
        foreach (self::ACTIONS as $field => $name) {
            if ($run[$field] ?? false) {
                $actions[] = $name;
            }
        }
        return array_values(array_unique($actions));
    }

    public function filter(array $runs, array $filters): array
    {
        return array_values(array_filter($runs, static function ($run) use ($filters) {
            foreach ($filters as $key => $value) {
                if ($value === '') {
                    continue;
                }
                if ($key === 'from' && $run['day'] < $value || $key === 'to' && $run['day'] > $value) {
                    return false;
                }
                if ($key === 'action' && !in_array($value, self::actions($run), true)) {
                    return false;
                }
                if (!in_array($key, ['from', 'to', 'action'], true) && (string) ($run[$key] ?? '') !== $value) {
                    return false;
                }
            }
            return true;
        }));
    }

    public function stats(array $runs): array
    {
        $conversations = $opportunities = [];
        $metered = $unassigned = $failed = 0;
        foreach ($runs as $run) {
            if ($run['conversationId'] ?? null) {
                $conversations[$run['conversationId']] = true;
            }
            if ($run['opportunityId'] ?? null) {
                $opportunities[$run['opportunityId']] = true;
            }
            $metered += (int) (($run['usageMetricsVersion'] ?? null) === 1);
            $unassigned += (int) (($run['groupKey'] ?? null) === null);
            $failed += (int) (($run['runOutcome'] ?? null) === 'failed');
        }
        return [
            'runs' => count($runs), 'conversations' => count($conversations), 'opportunities' => count($opportunities),
            'meteredRuns' => $metered, 'unassignedRuns' => $unassigned, 'failedRuns' => $failed,
        ];
    }

    public function breakdown(array $runs, array $groups, string $dimension, int $offset, int $limit): array
    {
        if (!in_array($dimension, self::DIMENSIONS, true)) {
            throw new BadRequest('Invalid breakdown dimension.');
        }
        $field = match ($dimension) {
            'agent' => 'agentId', 'account' => 'chatwootAccountId',
            'conversation' => 'conversationId', 'opportunity' => 'opportunityId', default => 'kind',
        };
        $buckets = [];
        foreach ($runs as $run) {
            $keys = $dimension === 'action' ? (self::actions($run) ?: ['']) : [(string) ($run[$field] ?? '')];
            foreach ($keys as $key) {
                $buckets[$key] ??= ['key' => $key, 'runs' => 0, 'failedRuns' => 0, 'days' => 0, 'grains' => []];
                $buckets[$key]['runs']++;
                $buckets[$key]['failedRuns'] += (int) (($run['runOutcome'] ?? null) === 'failed');
                $buckets[$key]['days'] |= 1 << ((int) substr($run['day'], -2) - 1);
            }
        }
        $total = count($buckets);
        uasort($buckets, static fn ($a, $b) => $b['runs'] <=> $a['runs'] ?: strcmp($a['key'], $b['key']));
        // Only materialize financial projections for the requested page.
        $buckets = array_slice($buckets, $offset, $limit, true);
        if (in_array($dimension, ['conversation', 'opportunity'], true)) {
            foreach ($runs as $run) {
                $key = (string) ($run[$field] ?? '');
                if (isset($buckets[$key]) && $run['groupKey'] !== null && ($run['runOutcome'] ?? null) !== 'failed') {
                    $grains = &$buckets[$key]['grains'];
                    $grains[$run['groupKey']] = ($grains[$run['groupKey']] ?? 0) + 1;
                    unset($grains);
                }
            }
        }
        $rows = [];
        foreach ($buckets as $bucket) {
            $amounts = Ledger::emptyAmounts();
            // Tools can overlap, and agents can share the same base/pack. Do not assign fictitious charges.
            $attributable = in_array($dimension, ['conversation', 'opportunity'], true) && $bucket['key'] !== '';
            foreach ($bucket['grains'] as $key => $matchedCount) {
                $grain = $groups[$key];
                if ($grain['scopeType'] !== $dimension || $matchedCount !== $grain['runs'] || !isset($grain['billing'])) {
                    $attributable = false;
                } elseif ($attributable) {
                    Ledger::add($amounts, $grain['billing']);
                }
            }
            $rows[] = [
                'key' => $bucket['key'], 'runs' => $bucket['runs'], 'failedRuns' => $bucket['failedRuns'],
                'days' => substr_count(decbin($bucket['days']), '1'),
                'share' => count($runs) ? round(100 * $bucket['runs'] / count($runs), 1) : 0,
                'billing' => $attributable && ($bucket['grains'] || $bucket['failedRuns'] === $bucket['runs']) ? $amounts : null,
            ];
        }
        return ['dimension' => $dimension, 'total' => $total, 'offset' => $offset, 'list' => $rows];
    }
}
