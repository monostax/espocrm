<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Classes\MassAction\MetaLeadgenEvent;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\MassAction\Data;
use Espo\Core\MassAction\MassAction;
use Espo\Core\MassAction\Params;
use Espo\Core\MassAction\QueryBuilder;
use Espo\Core\MassAction\Result;
use Espo\Core\ORM\EntityManager;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadgenEvent;
use Espo\Modules\FeatureMetaLeadAds\Jobs\IngestLeadgen;
use Throwable;

/**
 * Bulk re-enqueue IngestLeadgen for selected MetaLeadgenEvent rows.
 *
 * Behavior:
 *  - Only rows with status=Failed are reset and re-queued. Others are
 *    silently skipped (counted into $ids only if actually re-queued).
 *  - The per-row reset is identical to the single-record retryIngest action:
 *      status=Received, errorMessage=null, processedAt=null.
 *  - Each reset triggers a fresh IngestLeadgen job grouped by leadgenId so
 *    duplicate redeliveries serialize.
 *
 * Acl: caller needs EDIT on MetaLeadgenEvent (gated by `acl: "edit"` on the
 * client-side mass action declaration AND by the per-entity ACL check below).
 */
class MassRetryIngest implements MassAction
{
    public function __construct(
        private QueryBuilder $queryBuilder,
        private Acl $acl,
        private EntityManager $entityManager,
        private JobSchedulerFactory $jobSchedulerFactory,
    ) {}

    public function process(Params $params, Data $data): Result
    {
        $entityType = $params->getEntityType();

        if (!$this->acl->check($entityType, Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("No edit access for '{$entityType}'.");
        }

        $query = $this->queryBuilder->build($params);

        $collection = $this->entityManager
            ->getRDBRepository($entityType)
            ->clone($query)
            ->sth()
            ->find();

        $ids = [];
        $count = 0;

        foreach ($collection as $entity) {
            if (!$entity instanceof MetaLeadgenEvent) {
                continue;
            }

            if (!$this->acl->checkEntityEdit($entity)) {
                continue;
            }

            if ($entity->get('status') !== MetaLeadgenEvent::STATUS_FAILED) {
                continue;
            }

            try {
                $entity->set('status',       MetaLeadgenEvent::STATUS_RECEIVED);
                $entity->set('errorMessage', null);
                $entity->set('processedAt',  null);

                $this->entityManager->saveEntity($entity, ['skipHooks' => true, 'silent' => true]);

                $this->jobSchedulerFactory
                    ->create()
                    ->setClassName(IngestLeadgen::class)
                    ->setData(['eventId' => $entity->getId()])
                    ->setGroup('meta-leadgen-' . ((string) $entity->get('leadgenId') ?: $entity->getId()))
                    ->schedule();

                $ids[] = $entity->getId();
                $count++;
            } catch (Throwable $e) {
                // Skip rows that fail to save/schedule; the rest still proceed.
                continue;
            }
        }

        return new Result($count, $ids);
    }
}
