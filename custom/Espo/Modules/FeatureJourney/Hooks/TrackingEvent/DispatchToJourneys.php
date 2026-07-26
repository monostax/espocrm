<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Hooks\TrackingEvent;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Services\JourneySignalDispatcher;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/** @implements AfterSave<Entity> */
class DispatchToJourneys implements AfterSave
{
    public static int $order = 50;

    public function __construct(
        private EntityManager $entityManager,
        private JourneySignalDispatcher $dispatcher,
        private Log $log,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->getEntityType() !== 'TrackingEvent') {
            return;
        }

        if (!$entity->isNew()) {
            return;
        }

        try {
            $tenantId = $entity->get('tenantId');
            $code = $entity->get('code');

            if (!$tenantId || !$code) {
                return;
            }

            $target = null;
            $contactId = $entity->get('contactId');
            if ($contactId) {
                $target = $this->entityManager->getEntityById('Contact', (string) $contactId);
            }

            if (!$target) {
                $parentType = $entity->get('parentType');
                $parentId = $entity->get('parentId');
                if ($parentType && $parentId && in_array($parentType, ['Contact', 'Account', 'Lead'], true)) {
                    $target = $this->entityManager->getEntityById((string) $parentType, (string) $parentId);
                }
            }

            $payload = $entity->get('payload');
            if ($payload instanceof \stdClass) {
                $payload = json_decode(json_encode($payload) ?: '{}', true) ?: [];
            }
            if (!is_array($payload)) {
                $payload = [];
            }

            $this->dispatcher->dispatch(
                (string) $tenantId,
                (string) $code,
                $target,
                $payload,
                $entity->getId(),
            );
        } catch (Throwable $e) {
            $this->log->warning('DispatchToJourneys: ' . $e->getMessage());
        }
    }
}
