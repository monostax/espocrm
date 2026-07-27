<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Unlink target from another same-tenant record.
 * Params: link, entityId (foreign id; required for multi links).
 */
class UnlinkRecord implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantGuard $tenantGuard,
    ) {}

    public function run(ActionContext $context): void
    {
        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('UnlinkRecord: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        $link = (string) ($context->params['link'] ?? '');
        if ($link === '') {
            throw new Error('UnlinkRecord: link is required.');
        }

        $target = $context->target;
        if (!$target->hasRelation($link)) {
            throw new Error("UnlinkRecord: link '{$link}' missing on {$target->getEntityType()}.");
        }

        $linkType = $target->getRelationType($link);
        $entityId = (string) ($context->params['entityId'] ?? $context->params['foreignId'] ?? '');

        if ($linkType === Entity::BELONGS_TO) {
            $currentId = (string) ($target->get($link . 'Id') ?? '');
            if ($entityId !== '' && $currentId !== '' && $currentId !== $entityId) {
                throw new Error('UnlinkRecord: entityId does not match current belongsTo.');
            }
            if ($currentId !== '') {
                $foreignType = $this->tenantGuard->resolveLinkForeignEntityType($target, $link);
                $this->tenantGuard->loadEntityInTenant($foreignType, $currentId, $tenantId, 'unlink-foreign');
            }
            $target->set($link . 'Id', null);
            $this->entityManager->saveEntity($target, [
                'skipJourneyDispatch' => true,
                'modifiedById' => 'system',
            ]);

            return;
        }

        if ($linkType === Entity::BELONGS_TO_PARENT) {
            $currentId = (string) ($target->get($link . 'Id') ?? '');
            $currentType = (string) ($target->get($link . 'Type') ?? '');
            if ($entityId !== '' && $currentId !== '' && $currentId !== $entityId) {
                throw new Error('UnlinkRecord: entityId does not match current parent.');
            }
            if ($currentId !== '' && $currentType !== '') {
                $this->tenantGuard->loadEntityInTenant($currentType, $currentId, $tenantId, 'unlink-parent');
            }
            $target->set($link . 'Id', null);
            $target->set($link . 'Type', null);
            $this->entityManager->saveEntity($target, [
                'skipJourneyDispatch' => true,
                'modifiedById' => 'system',
            ]);

            return;
        }

        if ($entityId === '') {
            throw new Error('UnlinkRecord: entityId is required for multi links.');
        }

        $foreignType = $this->tenantGuard->resolveLinkForeignEntityType($target, $link);
        $foreign = $this->tenantGuard->loadEntityInTenant($foreignType, $entityId, $tenantId, 'unlink-foreign');

        $this->entityManager
            ->getRDBRepository($target->getEntityType())
            ->getRelation($target, $link)
            ->unrelate($foreign);
    }
}
