<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use Brick\Math\BigDecimal;
use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Accounting\Amount;

/**
 * A seat plan subscription's terms over time. Accepted terms never change; a
 * plan change (attendants or monthly credits, decided 2026-11-27) is appended
 * to the subscription record by Subscriptions and, once Asaas charges its
 * price, applies from a period start on (`effectiveFrom`, the start of the
 * next unpaid period when it was applied). Renewal payments are verified
 * against, and fund, the terms in force for their period.
 *
 * Within a paid period the workspace keeps the most it paid for: an upgrade
 * paid mid-period counts at once, a downgrade waits for the renewal (`level`).
 */
final class PlanTerms
{
    /**
     * The subscription record of an obligation row, hash-checked (Subscriptions
     * also checks its binding to the original creation).
     */
    public static function record(?string $json): array
    {
        if ($json === null) return ['changes' => []];
        try { $saved = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new Conflict('Invalid subscription lifecycle evidence.'); }
        if (!is_array($saved)) throw new Conflict('Invalid subscription lifecycle evidence.');
        $hash = $saved['hash'] ?? null; unset($saved['hash']);
        if ($hash !== Payments::hash($saved)) throw new Conflict('Invalid subscription lifecycle evidence.');
        return $saved;
    }

    /** Applied changes, in the order they were applied (`effectiveFrom` never decreases along it). */
    public static function applied(array $record): array
    {
        return array_values(array_filter($record['changes'] ?? [], static fn (array $change) => $change['state'] === 'applied'));
    }

    /** Terms in force for the period starting at $start (UTC). */
    public static function forPeriod(array $accepted, array $record, string $start): array
    {
        $terms = $accepted;
        foreach (self::applied($record) as $change) {
            if ($change['effectiveFrom'] <= $start) $terms = $change['terms'];
        }
        return $terms;
    }

    /** The terms Asaas charges from now on: the newest applied change, else the accepted terms. */
    public static function latest(array $accepted, array $record): array
    {
        $applied = self::applied($record);
        return $applied ? end($applied)['terms'] : $accepted;
    }

    /**
     * Payment verification schedule: from each applied change's period start on, its price.
     *
     * @return list<array{from: string, value: string}>
     */
    public static function schedule(array $record): array
    {
        return array_map(static fn (array $change) => ['from' => $change['effectiveFrom'], 'value' => $change['terms']['price']],
            self::applied($record));
    }

    /**
     * What the workspace has in the paid period [$start, $end): per dimension,
     * the most of the period's terms and of the changes applied during it (they
     * take effect at $end). Its price is what those seats and credits cost a month.
     *
     * @return array{seats: int, baseCredits: string, basePrice: string, unitPrice: string, includedCredits: string,
     *     credits: string, price: string}
     */
    public static function level(array $accepted, array $record, string $start, string $end): array
    {
        $period = self::forPeriod($accepted, $record, $start);
        $seatsFrom = $period; $creditsFrom = $period;
        foreach (self::applied($record) as $change) {
            if ($change['effectiveFrom'] !== $end) continue;
            if ($change['terms']['seats']['quantity'] > $seatsFrom['seats']['quantity']) $seatsFrom = $change['terms'];
            if (BigDecimal::of(self::baseCredits($change['terms']))->isGreaterThan(self::baseCredits($creditsFrom))) $creditsFrom = $change['terms'];
        }
        $seats = $seatsFrom['seats']['quantity'];
        $included = $seatsFrom['seats']['includedCredits'] ?? '0.0000';
        $base = self::baseCredits($creditsFrom);
        $credits = Amount::fromString($base)->plus(Amount::fromString((string) BigDecimal::of($included)->multipliedBy($seats)->toScale(4)));
        $price = BigDecimal::of($creditsFrom['seats']['basePrice'])
            ->plus(BigDecimal::of($seatsFrom['seats']['unitPrice'])->multipliedBy($seats))->toScale(2);
        return ['seats' => $seats, 'baseCredits' => $base, 'basePrice' => $creditsFrom['seats']['basePrice'],
            'unitPrice' => $seatsFrom['seats']['unitPrice'], 'includedCredits' => $included, 'credits' => (string) $credits,
            'price' => (string) $price];
    }

    /** The monthly credits on top of the seats' (all of them for seat terms without included credits). */
    public static function baseCredits(array $terms): string
    {
        return $terms['seats']['baseCredits'] ?? $terms['credits'];
    }
}
