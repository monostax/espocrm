<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Hooks\AutomationActionReceipt;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureAutomation\Entities\Automation;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Cascades tenantId from parent Automation onto AutomationActionReceipt.
 *
 * Receipts are tenant-scoped (no teams field); ActionReceiptStore usually sets
 * tenantId explicitly with SKIP_ALL — this covers non-SKIP_ALL saves.
 *
 * @implements BeforeSave<Entity>
 */
class CascadeTenantFromAutomation implements BeforeSave
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get('silent') && $entity->get('tenantId')) {
            return;
        }

        if ($entity->get('tenantId')) {
            return;
        }

        $automationId = $entity->get('automationId');
        if (!$automationId) {
            return;
        }

        $automation = $this->entityManager->getEntityById(
            Automation::ENTITY_TYPE,
            (string) $automationId,
        );
        if (!$automation || !$automation->get('tenantId')) {
            return;
        }

        $entity->set('tenantId', $automation->get('tenantId'));
    }
}
