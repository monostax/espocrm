<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Tools;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Accounting\Amount;
use Espo\Modules\FeatureCredits\Accounting\FixedSettlement;
use Espo\Modules\FeatureCredits\Accounting\ReservationInput;
use Espo\Modules\FeatureCredits\Accounting\Reservations;
use Espo\Modules\FeatureCredits\Accounting\WalletLock;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * Meta's cost in credits (decided 2026-11-12). Meta bills only delivered messages it marks billable, by pricing category
 * (marketing, utility, authentication, service); its status webhook says which. One `messaging` operation per billed
 * message, admitted and settled in one wallet transaction at Meta's rate converted to credits (`rates` /
 * `brlPerCredit`, rounded up to 0.0001). A message Meta didn't bill costs nothing. The operation key is the delivery
 * log's id, so a status delivered twice charges once.
 *
 * A workspace without an active credit agreement, or without the credits, isn't charged (the log keeps `billable`
 * with no `chargedAt`, and the reason in the CRM log): the message already went out.
 */
class Charges
{
    public function __construct(
        private EntityManager $em,
        private Settings $settings,
        private WalletLock $walletLock,
        private Reservations $reservations,
        private FixedSettlement $settlement,
        private Log $log,
    ) {}

    /** Meta's BRL rate for a category, in credits; null for a category without a rate. */
    public function credits(?string $category): ?string
    {
        return self::creditsFor($this->settings->rates(), $this->settings->brlPerCredit(), $category);
    }

    /** @param array<string, string> $rates pricing category => BRL */
    public static function creditsFor(array $rates, string $brlPerCredit, ?string $category): ?string
    {
        $rate = $rates[(string) $category] ?? null;
        if ($rate === null) return null;
        $credits = BigDecimal::of($rate)->dividedBy(BigDecimal::of($brlPerCredit), 4, RoundingMode::UP);
        return $credits->isPositive() ? (string) $credits : null;
    }

    public function charge(Entity $log): void
    {
        $tenantId = (string) $log->get('tenantId');
        $credits = $this->credits($log->get('pricingCategory'));
        if ($tenantId === '' || $credits === null || $log->get('chargedAt')) return;
        $key = 'messaging:' . strtolower((string) $log->getId());
        try {
            $this->walletLock->run($tenantId, function (PDO $pdo, array $wallet, string $now) use ($tenantId, $credits, $key): void {
                $query = $pdo->prepare('SELECT id FROM tenant_credit_billing_rate WHERE tenant_id = ? AND deleted = FALSE
                    AND effective_from <= ? AND (effective_until IS NULL OR effective_until > ?)');
                $query->execute([$tenantId, $now, $now]);
                $rates = $query->fetchAll(PDO::FETCH_COLUMN);
                if (count($rates) !== 1) throw new \RuntimeException('no single active credit agreement');
                $receipt = $this->reservations->reserveLocked($pdo, $wallet,  $now,
                    new ReservationInput($tenantId, 'messaging', $key, $key, (string) $rates[0], $credits));
                $usage = $this->row($pdo, 'SELECT * FROM credit_usage WHERE id = ? AND tenant_id = ?', [$receipt['usageId'], $tenantId]);
                if ($usage['state'] !== 'admitted') return;
                $reservation = $this->row($pdo, 'SELECT * FROM credit_reservation WHERE id = ? AND tenant_id = ?', [$receipt['reservationId'], $tenantId]);
                // The reservation just moved the wallet's projections; settle against the row as it is now.
                $wallet = $this->row($pdo, 'SELECT * FROM tenant_credit_balance WHERE id = ?', [$wallet['id']]);
                $this->settlement->settleLocked($pdo, $wallet, $now, $usage, $reservation, Amount::fromString($credits));
            });
        } catch (Throwable $e) {
            $this->log->warning("WhatsApp notifications: message {$log->getId()} was billed by Meta but not charged: " . $e->getMessage());
            return;
        }
        $log->set(['credits' => $credits, 'chargedAt' => gmdate('Y-m-d H:i:s')]);
        $this->em->saveEntity($log);
    }

    /** @return array<string, mixed> */
    private function row(PDO $pdo, string $sql, array $params): array
    {
        $query = $pdo->prepare($sql);
        $query->execute($params);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('credit row missing');
        return $row;
    }
}
