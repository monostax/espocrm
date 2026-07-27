<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Link target with another same-tenant record.
 * Params: link, entityId (foreign id).
 */
class LinkRecord implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantGuard $tenantGuard,
    ) {}

    public function run(ActionContext $context): void
    {
        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('LinkRecord: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        $link = (string) ($context->params['link'] ?? '');
        $entityId = (string) ($context->params['entityId'] ?? $context->params['foreignId'] ?? '');

        if ($link === '' || $entityId === '') {
            throw new Error('LinkRecord: link and entityId are required.');
        }

        $target = $context->target;
        if (!$target->hasRelation($link)) {
            throw new Error("LinkRecord: link '{$link}' missing on {$target->getEntityType()}.");
        }

        $foreignType = $this->tenantGuard->resolveLinkForeignEntityType($target, $link);
        $foreign = $this->tenantGuard->loadEntityInTenant($foreignType, $entityId, $tenantId, 'link-foreign');

        $linkType = $target->getRelationType($link);

        if ($linkType === Entity::BELONGS_TO) {
            $target->set($link . 'Id', $foreign->getId());
            $this->entityManager->saveEntity($target, [
                'skipJourneyDispatch' => true,
                'modifiedById' => 'system',
            ]);

            return;
        }

        if ($linkType === Entity::BELONGS_TO_PARENT) {
            $target->set($link . 'Id', $foreign->getId());
            $target->set($link . 'Type', $foreign->getEntityType());
            $this->entityManager->saveEntity($target, [
                'skipJourneyDispatch' => true,
                'modifiedById' => 'system',
            ]);

            return;
        }

        $this->entityManager
            ->getRDBRepository($target->getEntityType())
            ->getRelation($target, $link)
            ->relate($foreign);
    }
}
