<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEvent;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEventType;
use Espo\Modules\FeatureTrackingEvent\Services\TrackingEventNameBuilder;
use Espo\ORM\EntityManager;

/**
 * One-off backfill: rewrites legacy "{code} @ {timestamp}" TrackingEvent
 * names (and raw-code TrackingEventType names) to the human-friendly
 * format produced by TrackingEventNameBuilder ("{label} · {detail}").
 *
 * Re-runnable and idempotent: rows already carrying the new format are
 * rewritten to the same value and skipped by the changed-check. Updates go
 * through direct update queries - no hooks, no modifiedAt churn, no stream
 * notes.
 *
 * Usage (inside the CRM pod):
 *   php command.php tracking-event:rebuild-names [--dry-run]
 */
class TrackingEventRebuildNames implements Command
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private EntityManager $entityManager,
        private TrackingEventNameBuilder $nameBuilder,
    ) {}

    public function run(Params $params, IO $io): void
    {
        $dryRun = $params->hasFlag('dry-run');

        if ($dryRun) {
            $io->writeLine('Dry run - nothing will be written.');
        }

        $typesRenamed = $this->rebuildTypeNames($io, $dryRun);
        $eventsRenamed = $this->rebuildEventNames($io, $dryRun);

        $io->writeLine('');
        $io->writeLine("Done. Event types renamed: {$typesRenamed}; events renamed: {$eventsRenamed}.");
    }

    /**
     * TrackingEventType rows whose name still equals the raw code get the
     * translated label (tenant language). Human-renamed types are untouched.
     */
    private function rebuildTypeNames(IO $io, bool $dryRun): int
    {
        $count = 0;

        $types = $this->entityManager
            ->getRDBRepository(TrackingEventType::ENTITY_TYPE)
            ->sth()
            ->find();

        foreach ($types as $type) {
            $code = (string) $type->get('code');
            $name = (string) $type->get('name');
            $tenantId = (string) ($type->get('tenantId') ?? '');

            if ($code === '' || $name !== $code) {
                continue;
            }

            $label = $this->nameBuilder->labelForCode($code, $tenantId);

            if ($label === $name) {
                continue;
            }

            $count++;

            $io->writeLine("TrackingEventType {$type->getId()}: '{$name}' -> '{$label}'");

            if (!$dryRun) {
                $this->updateName(TrackingEventType::ENTITY_TYPE, $type->getId(), $label);
            }
        }

        return $count;
    }

    private function rebuildEventNames(IO $io, bool $dryRun): int
    {
        $count = 0;
        $scanned = 0;
        $lastId = '';

        /** @var array<string, ?string> $typeNameById */
        $typeNameById = [];

        while (true) {
            $events = $this->entityManager
                ->getRDBRepository(TrackingEvent::ENTITY_TYPE)
                ->where(['id>' => $lastId])
                ->order('id')
                ->limit(0, self::BATCH_SIZE)
                ->find();

            $batchCount = 0;

            foreach ($events as $event) {
                $batchCount++;
                $scanned++;
                $lastId = $event->getId();

                $code = (string) $event->get('code');

                if ($code === '') {
                    continue;
                }

                $tenantId = (string) ($event->get('tenantId') ?? '');

                $typeId = $event->get('trackingEventTypeId');

                if (is_string($typeId) && !array_key_exists($typeId, $typeNameById)) {
                    $type = $this->entityManager
                        ->getEntityById(TrackingEventType::ENTITY_TYPE, $typeId);

                    $typeNameById[$typeId] = $type?->get('name');
                }

                $typeName = is_string($typeId) ? ($typeNameById[$typeId] ?? null) : null;

                $newName = $this->nameBuilder->build(
                    $code,
                    $tenantId,
                    $typeName,
                    $this->detailFor($code, $event),
                );

                if ($newName === $event->get('name')) {
                    continue;
                }

                $count++;

                if (!$dryRun) {
                    $this->updateName(TrackingEvent::ENTITY_TYPE, $event->getId(), $newName);
                }
            }

            $io->writeLine("... scanned {$scanned} events, renamed {$count}");

            if ($batchCount < self::BATCH_SIZE) {
                break;
            }
        }

        return $count;
    }

    /**
     * Mirrors the per-code detail choices of the live persist sites.
     */
    private function detailFor(string $code, TrackingEvent $event): ?string
    {
        $payload = $this->payload($event);

        if ($code === 'whatsapp_conversation_linked') {
            $waPhone = $payload['waPhone'] ?? null;

            if (is_string($waPhone) && $waPhone !== '') {
                return '+' . $waPhone;
            }

            return $this->strOrNull($payload['slug'] ?? null);
        }

        // Short-link clicks (link_clicked or custom codes recorded by the
        // redirector) carry linkName/slug in the payload.
        $linkName = $this->strOrNull($payload['linkName'] ?? null);

        if ($linkName !== null || $event->get('trackingLinkId')) {
            return $linkName ?? $this->strOrNull($payload['slug'] ?? null);
        }

        if (str_starts_with($code, 'opportunity_')) {
            return $this->strOrNull($payload['opportunityName'] ?? null);
        }

        // Generic / page_view: title, else URL path.
        $title = $this->strOrNull($payload['title'] ?? null);

        if ($title !== null) {
            return $title;
        }

        $url = $event->get('url');

        if (is_string($url) && $url !== '') {
            $path = parse_url($url, PHP_URL_PATH);

            if (is_string($path) && $path !== '' && $path !== '/') {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(TrackingEvent $event): array
    {
        $payload = $event->get('payload');

        if (is_object($payload)) {
            return get_object_vars($payload);
        }

        return is_array($payload) ? $payload : [];
    }

    private function strOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function updateName(string $entityType, string $id, string $name): void
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->update()
            ->in($entityType)
            ->set(['name' => $name])
            ->where(['id' => $id])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($query);
    }
}
