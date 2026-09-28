<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAiUsage\Services;

use Espo\Modules\Chatwoot\Tools\Billing\PlanIncludedApplier;
use Espo\Modules\Chatwoot\Tools\Billing\Pricing;
use Espo\Modules\Chatwoot\Tools\Billing\RateCard;

/** Pure tenant-month ledger. Prices precede display filters; shared billing grains are never repriced. */
class Ledger
{
    public function build(iterable $source, Period $period, array $agreements, string $tenantId): array
    {
        $runs = [];
        $groups = [];
        $daily = [];
        foreach ($period->days() as $day) {
            $daily[$day] = ['day' => $day, 'runs' => 0] + self::emptyAmounts();
        }
        foreach ($source as $run) {
            $run['day'] = $period->day($run['runAt']);
            $type = $run['kind'] === 'opportunity-mention' ? 'opportunity' : 'conversation';
            $id = (string) ($run[$type . 'Id'] ?? '');
            $run['groupKey'] = $id !== '' ? $run['day'] . '|' . $type . '|' . $id : null;
            $runs[] = $run;
            $daily[$run['day']]['runs']++;
            if ($run['groupKey'] === null || self::exemption($run) !== null) {
                continue;
            }
            $groups[$run['groupKey']] ??= [
                'day' => $run['day'], 'scopeType' => $type, 'scopeId' => $id,
                'customer' => 0, 'other' => 0, 'runs' => 0,
            ];
            $groups[$run['groupKey']][$type === 'conversation' && $run['kind'] === 'customer-message' ? 'customer' : 'other']++;
            $groups[$run['groupKey']]['runs']++;
        }
        ksort($groups); // Stable FIFO: day, scope type, scope id (also stable across pagination).

        $contract = $this->contract($period, $agreements, $groups);
        $summary = self::emptyAmounts() + [
            'status' => $contract['status'], 'reason' => $contract['reason'],
            'model' => $contract['model'], 'allowance' => $contract['allowance'], 'remaining' => null,
        ];
        if ($contract['status'] !== 'ready') {
            $summary['allowance'] = null;
            $summary['model'] = null;
            foreach (['consumed', 'covered', 'overage', 'charges'] as $key) {
                $summary[$key] = null;
                foreach ($daily as &$day) {
                    $day[$key] = null;
                }
                unset($day);
            }
            return compact('runs', 'groups', 'summary', 'daily', 'contract');
        }

        foreach ($contract['rates'] as $rate) {
            $summary['charges'][$rate['currency']] = ['amount' => 0.0, 'base' => 0.0, 'extras' => 0.0];
        }

        $priced = (static function () use (&$groups, $contract, $tenantId): \Generator {
            foreach ($groups as $key => $group) {
                $card = $contract['byDay'][$group['day']]['card'];
                $metrics = match ($contract['model']) {
                    'pack199' => Pricing::pack199($group['runs'], $card),
                    'extra049' => Pricing::extra049($group['customer'], $group['other'], $card),
                    'credit' => Pricing::credit($group['customer'], $group['other'], $card),
                };
                yield $key => ['dayBucket' => $group['day'], 'tenantId' => $tenantId, 'rates' => $card, 'metrics' => $metrics];
            }
        })();
        [$includedKey, $billedKey] = PlanIncludedApplier::outputColumns($contract['model']);
        $unitKey = match ($contract['model']) {'pack199' => 'packs', 'extra049' => 'bases', default => 'credits'};
        $projectedRates = [];
        foreach ($contract['byDay'] as $day => $rate) {
            $projectedRates[$day] = $this->projectRate($rate);
        }
        foreach (PlanIncludedApplier::applyOrdered($priced, $contract['model']) as $key => $row) {
            $metrics = $row['metrics'];
            $card = $contract['byDay'][$row['dayBucket']]['card'];
            $base = $contract['model'] === 'extra049' ? Pricing::roundMoney($metrics[$billedKey] * $card->extraBasePrice) : 0.0;
            $amounts = [
                'consumed' => $metrics[$unitKey], 'covered' => $metrics[$includedKey], 'overage' => $metrics[$billedKey],
                'charges' => [$card->currency => [
                    'amount' => $metrics['amountDeal'], 'base' => $base,
                    'extras' => $contract['model'] === 'extra049' ? Pricing::roundMoney($metrics['amountDeal'] - $base) : 0.0,
                ]],
            ];
            $groups[$key]['billing'] = $amounts;
            $groups[$key]['rate'] = $projectedRates[$row['dayBucket']];
            self::add($summary, $amounts);
            self::add($daily[$groups[$key]['day']], $amounts);
        }
        $summary['remaining'] = max(0, $summary['allowance'] - $summary['covered']);
        return compact('runs', 'groups', 'summary', 'daily', 'contract');
    }

    private function contract(Period $period, array $agreements, array $groups): array
    {
        $result = ['status' => 'ready', 'reason' => null, 'model' => null, 'allowance' => null, 'byDay' => [], 'rates' => []];
        $daysWithUsage = array_fill_keys(array_column($groups, 'day'), true);
        $days = $period->days();
        $referenceDay = $period->toArray()['through'];
        if (!$days) {
            $days = [$referenceDay];
        }
        foreach ($days as $day) {
            $matches = array_values(array_filter($agreements, static fn ($a) =>
                $a['from'] <= $day && ($a['to'] === null || $a['to'] >= $day)
            ));
            if (!$matches && !isset($daysWithUsage[$day]) && $day !== $referenceDay) {
                continue; // A tenant's first contract can start after the first day of the month.
            }
            if (count($matches) !== 1 || !in_array($matches[0]['model'] ?? '', ['pack199', 'extra049', 'credit'], true)) {
                $result['status'] = 'configurationRequired';
                $result['reason'] = count($matches) > 1 ? 'overlappingRates' : 'missingRate';
                continue;
            }
            $rate = $matches[0];
            $allowance = $rate['model'] === 'credit' ? $rate['card']->planIncludedCredits : $rate['card']->planIncludedUsage;
            if ($result['model'] !== null && ($result['model'] !== $rate['model'] || $result['allowance'] !== $allowance)) {
                $result['status'] = 'configurationRequired';
                $result['reason'] = 'contractChanged';
            }
            $result['model'] ??= $rate['model'];
            $result['allowance'] ??= $allowance;
            $result['byDay'][$day] = $rate;
            $result['rates'][$rate['id'] ?? $rate['from']] = $this->projectRate($rate);
        }
        $result['rates'] = array_values($result['rates']);
        return $result;
    }

    private function projectRate(array $rate): array
    {
        /** @var RateCard $card */
        $card = $rate['card'];
        $terms = match ($rate['model']) {
            'pack199' => ['unitPrice' => $card->packUnitPrice, 'packSize' => $card->packSize],
            'extra049' => ['basePrice' => $card->extraBasePrice, 'extraPrice' => $card->extraUnitPrice, 'includedTurns' => $card->extraIncludedCustomerTurns],
            default => ['unitPrice' => $card->creditUnitPrice],
        };
        return ['from' => $rate['from'], 'to' => $rate['to'], 'currency' => $card->currency, 'model' => $rate['model']] + $terms;
    }

    /** Permanent exclusions take precedence over unresolved attribution and daily charges. */
    public static function exemption(array $run): ?string
    {
        if ($run['billingWaived'] ?? false) {
            return 'waived';
        }
        return ($run['runOutcome'] ?? null) === 'failed' ? 'failed' : null;
    }

    /** Status of the complete daily group, not an allocation to an individual run. */
    public static function billingStatus(?array $group): string
    {
        if ($group === null) {
            return 'pending';
        }
        $billing = $group['billing'] ?? null;
        if ($billing === null) {
            return 'unavailable';
        }
        if (array_sum(array_column($billing['charges'], 'amount')) > 0) {
            return $billing['covered'] > 0 ? 'partiallyBilled' : 'billed';
        }
        return $billing['covered'] > 0 && $billing['overage'] === 0 ? 'included' : 'notBilled';
    }

    public static function emptyAmounts(): array
    {
        return ['consumed' => 0, 'covered' => 0, 'overage' => 0, 'charges' => []];
    }

    public static function add(array &$target, array $source): void
    {
        foreach (['consumed', 'covered', 'overage'] as $key) {
            $target[$key] += $source[$key];
        }
        foreach ($source['charges'] as $currency => $values) {
            foreach ($values as $key => $value) {
                $target['charges'][$currency][$key] = Pricing::roundMoney(($target['charges'][$currency][$key] ?? 0) + $value);
            }
        }
    }
}
