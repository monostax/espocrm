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
 *  - All statuses are eligible — Failed (recover from errors), Skipped
 *    (recover from operator-corrected configs), and Processed (re-fetch
 *    the lead from Meta to surface newly-mapped fields or to create a
 *    missing Opportunity after a stage/funnel fix). Per-row ACL is still
 *    enforced via `checkEntityEdit` so cross-tenant retries are blocked.
 *  - The per-row reset is identical to the single-record retryIngest action:
 *      status=Received, errorMessage=null, processedAt=null.
 *      contactId/opportunityId are deliberately preserved so the ingester
 *      can detect re-ingest and reuse the existing Opportunity instead of
 *      creating a duplicate.
 *  - Each reset triggers a fresh IngestLeadgen job grouped by leadgenId so
 *    duplicate redeliveries serialise.
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
