<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Delivery;

use Espo\Core\WebSocket\ConfigDataProvider;
use Espo\Core\WebSocket\Sender;
use Espo\Modules\FeatureLiveRecords\Outbox\Intent;
use Espo\Modules\FeatureLiveRecords\Outbox\Lease;
use Espo\Modules\FeatureLiveRecords\Outbox\Store;

/** Internal native-socket hint. The native server authorizes projections before browser delivery. */
final class Gateway
{
    public function __construct(private Sender $sender, private ConfigDataProvider $config) {}

    public function deliver(Lease $lease): void
    {
        if (!$lease->createdAt) return;
        $created = (new \DateTimeImmutable($lease->createdAt, new \DateTimeZone('UTC')))->getTimestamp();
        $age = microtime(true) - $created;
        if ($age < 0 || $age >= Store::FRESHNESS_SECONDS) return;
        $data = json_decode($lease->intent, true, flags: JSON_THROW_ON_ERROR);
        if (($data['version'] ?? null) !== 1 || array_diff(array_keys($data), ['version', 'kind', 'records'])) {
            throw new \InvalidArgumentException('Unsupported outbox protocol.');
        }
        $intent = new Intent($lease->tenantId, $data['kind'] ?? '', $data['records'] ?? []);
        if (!$this->config->isEnabled()) throw new \RuntimeException('Native CRM WebSocket is disabled.');
        // Sender failures propagate to the bounded outbox retry path. This is
        // publication, not an acknowledgement of browser receipt (D31).
        $this->sender->send(json_encode([
            'topicId' => 'liveRecordChange', 'eventId' => $lease->id,
            'intentCreatedAtMs' => $created * 1000,
            'tenantId' => $intent->tenantId, 'kind' => $intent->kind,
            'records' => $intent->records,
        ], JSON_THROW_ON_ERROR));
    }
}
