<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Billing;

use Brick\Math\BigDecimal;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Accounting\{Amount, Clock, Funding, GrantInput};
use Espo\Modules\FeatureCredits\Billing\Asaas\{Command, Connections, Transport};
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * Monthly credits for a negotiated plan billed by an Asaas subscription made by
 * hand in the Asaas dashboard, not by our checkout (Instituto Ferraz Veloso,
 * decided 2026-11-12). The billing engine only funds subscriptions it created,
 * so this links one such subscription to a tenant from config
 * `featureCreditsExternalSubscriptions`:
 *
 *   {"ifv-subscription": {"tenantId": "…", "integration": "asaas-production",
 *     "subscription": "sub_…", "customer": "cus_…", "firstDueDate": "2026-11-06",
 *     "value": "2023.00", "credits": "4200"}}
 *
 * Each paid invoice of that subscription grants `credits` once, as a
 * `subscription` grant keyed `<link>:<original due date>`. Like the engine, the
 * grant starts when its period starts (the due date, São Paulo midnight) and
 * lasts until the end of the following period, so unused credits carry over one
 * month. Every payment is read from Asaas; an invoice with another customer,
 * value or schedule, a refund or a chargeback is logged and not funded. A refund
 * after funding is not reversed here: it needs an operator.
 */
final class ExternalSubscriptions
{
    private const PAID = ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'];
    private const PAGE = 100;
    private const MAX_PAGES = 10;

    public function __construct(
        private Config $config,
        private EntityManager $entityManager,
        private Connections $connections,
        private Transport $transport,
        private Funding $funding,
        private Clock $clock,
        private Log $log,
    ) {}

    /** @return array{granted: int, failed: int} */
    public function fundAll(): array
    {
        $links = $this->config->get('featureCreditsExternalSubscriptions');
        $result = ['granted' => 0, 'failed' => 0];
        if (!is_array($links)) return $result;
        foreach ($links as $key => $link) {
            try {
                $result['granted'] += $this->fund((string) $key, self::link($key, $link));
            } catch (Throwable $e) {
                $result['failed']++;
                $this->log->error("Credits: external subscription link '{$key}' failed: " . $e->getMessage());
            }
        }
        return $result;
    }

    /** Grants every paid, started period of one link that has no grant yet. Returns how many it granted. */
    public function fund(string $key, array $link): int
    {
        $connection = $this->connections->get($link['integration']);
        $granted = 0;
        foreach ($this->payments($connection, $link['subscription']) as $payment) {
            $id = is_string($payment['id'] ?? null) ? $payment['id'] : '?';
            $status = $payment['status'] ?? null;
            if (!in_array($status, self::PAID, true) || ($payment['deleted'] ?? false) === true) continue;
            $problem = self::problem($payment, $link);
            if ($problem !== null) {
                $this->log->warning("Credits: external subscription '{$key}' payment {$id} not funded: {$problem}.");
                continue;
            }
            $due = $payment['originalDueDate'];
            try {
                $period = MonthlyPeriod::forDueDate($link['firstDueDate'], $due);
            } catch (\InvalidArgumentException $e) {
                $this->log->warning("Credits: external subscription '{$key}' payment {$id} not funded: " . $e->getMessage());
                continue;
            }
            if ($period['start'] > $this->clock->now()) continue; // Paid early: funded when its period starts.
            $sourceKey = $key . ':' . $due;
            if ($this->hasGrant($link['tenantId'], $sourceKey)) continue;
            $receipt = $this->funding->grant(new GrantInput(
                $link['tenantId'], 'subscription', $sourceKey, $link['credits'], $period['start'],
                MonthlyPeriod::followingEnd($period['start'], $period['end']),
                (object) [
                    'source' => 'external-subscription', 'link' => $key, 'provider' => 'asaas',
                    'integrationId' => $link['integration'], 'environment' => $connection->environment,
                    'customer' => $link['customer'], 'subscription' => $link['subscription'],
                    'externalPaymentId' => $id, 'originalDueDate' => $due, 'value' => $link['value'],
                    'periodStart' => $period['start'], 'periodEnd' => $period['end'],
                ],
            ));
            $granted++;
            $this->log->info("Credits: external subscription '{$key}' payment {$id} granted {$receipt['grantedCredits']} credits " .
                "to tenant {$link['tenantId']} (grant {$receipt['grantId']}, expires {$receipt['expiresAt']}).");
        }
        return $granted;
    }

    /** @return list<array<string, mixed>> */
    private function payments(\Espo\Modules\FeatureCredits\Billing\Asaas\Connection $connection, string $subscription): array
    {
        $all = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->transport->send($connection, 'GET', '/payments', null,
                ['subscription' => $subscription, 'limit' => self::PAGE, 'offset' => $page * self::PAGE]);
            if ($response->status !== 200 || !is_array($response->data) || !is_array($response->data['data'] ?? null)) {
                throw new \RuntimeException("Asaas payment list returned HTTP {$response->status}.");
            }
            foreach ($response->data['data'] as $payment) if (is_array($payment)) $all[] = $payment;
            if (($response->data['hasMore'] ?? false) !== true) return $all;
        }
        throw new \RuntimeException('Asaas payment list has more than ' . self::PAGE * self::MAX_PAGES . ' payments.');
    }

    /** Why a paid payment must not be funded, or null. */
    private static function problem(array $payment, array $link): ?string
    {
        if (($payment['object'] ?? null) !== 'payment' || !is_string($payment['id'] ?? null)) return 'not a payment';
        if (($payment['subscription'] ?? null) !== $link['subscription']) return 'another subscription';
        if (($payment['customer'] ?? null) !== $link['customer']) return 'another customer';
        if (!empty($payment['installment'])) return 'an installment';
        if (!self::money($payment['value'] ?? null, $link['value'])) return 'value differs from the plan';
        if (!empty($payment['refunds']) || !empty($payment['chargeback'])) return 'refunded or charged back';
        $due = $payment['originalDueDate'] ?? null;
        if (!is_string($due) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due)) return 'no original due date';
        return null;
    }

    private function hasGrant(string $tenantId, string $sourceKey): bool
    {
        $query = $this->entityManager->getPDO()->prepare(
            "SELECT 1 FROM credit_grant WHERE tenant_id = ? AND source_type = 'subscription' AND source_key = ?");
        $query->execute([$tenantId, $sourceKey]);
        return (bool) $query->fetchColumn();
    }

    private static function money(mixed $value, string $expected): bool
    {
        return is_string($value) && preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D', $value) === 1 &&
            BigDecimal::of($value)->compareTo(BigDecimal::of($expected)) === 0;
    }

    /** A validated link; anything else in the config fails closed. */
    public static function link(mixed $key, mixed $link): array
    {
        $fields = ['tenantId', 'integration', 'subscription', 'customer', 'firstDueDate', 'value', 'credits'];
        if (!is_string($key) || !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $key) || !is_array($link) ||
            array_diff(array_keys($link), $fields) || array_diff($fields, array_keys($link))) {
            throw new \InvalidArgumentException('Invalid external subscription link.');
        }
        foreach ($fields as $field) if (!is_string($link[$field])) throw new \InvalidArgumentException("Invalid link field {$field}.");
        GrantInput::identity($link['tenantId']);
        GrantInput::identity($link['integration']);
        Command::externalId('subscription', $link['subscription']);
        Command::externalId('customer', $link['customer']);
        GrantInput::timestamp($link['firstDueDate'] . ' 00:00:00');
        if (!preg_match('/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D', $link['value']) || BigDecimal::of($link['value'])->isLessThanOrEqualTo(0)) {
            throw new \InvalidArgumentException('Invalid link value.');
        }
        if (Amount::fromString($link['credits'])->compareTo(Amount::fromString('0')) <= 0) {
            throw new \InvalidArgumentException('Invalid link credits.');
        }
        return $link;
    }
}
