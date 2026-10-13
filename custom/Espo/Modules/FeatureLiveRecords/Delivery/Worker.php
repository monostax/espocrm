<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Delivery;

use DateTimeImmutable;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureLiveRecords\Outbox\Store;
use Throwable;

final class Worker
{
    public function __construct(private EntityManager $em, private Gateway $gateway, private Log $log) {}

    public function tick(): bool
    {
        $store = new Store($this->em->getPDO());
        $store->expire(new DateTimeImmutable());
        $leases = $store->claim(new DateTimeImmutable(), 1);
        foreach ($leases as $lease) {
            try {
                $this->gateway->deliver($lease);
                $store->complete($lease, new DateTimeImmutable());
            } catch (Throwable $error) {
                $code = $error instanceof \JsonException || $error instanceof \InvalidArgumentException
                    ? 'malformed_intent' : 'bridge_unavailable';
                $store->fail($lease, new DateTimeImmutable(), $code);
                // Deliberately omit exception messages, routing IDs and lease data.
                $this->log->warning('Live records outbox retry.', ['failureCode' => $code]);
            }
        }
        return $leases !== [];
    }

    public function maintain(): array
    {
        $store = new Store($this->em->getPDO());
        $now = new DateTimeImmutable();
        $store->purgeCompleted($now);
        return $store->pendingMetrics($now);
    }
}
