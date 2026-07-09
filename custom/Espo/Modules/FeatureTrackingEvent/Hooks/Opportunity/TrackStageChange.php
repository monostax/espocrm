<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Hooks\Opportunity;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Log;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\FeatureTrackingEvent\Services\InternalEventRecorder;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * Records TrackingEvents for Opportunity funnel motion, via the in-process
 * InternalEventRecorder. OPT-IN per tenant: events are only recorded when
 * the tenant has an active kind=CRM TrackingSource (user-created, one per
 * tenant); otherwise the recorder silently skips.
 *
 * Emitted codes:
 *   - opportunity_stage_changed — every opportunityStage transition,
 *     including the initial stage on create (fromStage=null);
 *   - opportunity_created — lifecycle milestone overlay emitted alongside
 *     the initial stage_changed on create. Redundant with
 *     stage_changed+isNew, but a distinct code makes creations filterable
 *     in the event list UI and mutable per-code via TrackingEventType;
 *   - opportunity_won / opportunity_lost — on the derived status
 *     transitioning to Won/Lost (status is synced from the stage's
 *     probability by Global\Hooks\Opportunity\SyncFromOpportunityStage in
 *     the same save, so afterSave sees both old and new values). Won events
 *     carry value/currency from the opportunity amount.
 *
 * Mirrors FeatureMetaConversionsApi\Hooks\Opportunity\SendCapiOnStageChange:
 * fires on silent saves too — system-driven stage moves (workflows,
 * integrations, conversions-created opportunities) are still funnel motion.
 * Stage-row re-syncs (SyncRelatedOpportunities) are naturally skipped
 * because opportunityStageId does not change there.
 *
 * The recorder never throws; a tracking failure can never break the save.
 *
 * Properties carry acquisition attribution (sourceChannel, sourceTargetListId/
 * Name from Global's Opportunity fields) so downstream analytics can segment
 * funnel conversion and won-value by channel and by outreach batch.
 *
 * @implements AfterSave<Opportunity>
 */
class TrackStageChange implements AfterSave
{
    public static int $order = 25;

    public const CODE_STAGE_CHANGED = 'opportunity_stage_changed';
    public const CODE_CREATED = 'opportunity_created';
    public const CODE_WON = 'opportunity_won';
    public const CODE_LOST = 'opportunity_lost';

    public function __construct(
        private EntityManager $entityManager,
        private InternalEventRecorder $recorder,
        private Log $log,
    ) {}

    /**
     * @param Opportunity $entity
     */
    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof Opportunity) {
            return;
        }

        $newStageId = $entity->get('opportunityStageId');
        $oldStageId = $entity->getFetched('opportunityStageId');

        $isNew = $entity->isNew();

        if (!$isNew && $oldStageId === $newStageId) {
            return;
        }

        if (!$newStageId) {
            return;
        }

        $tenantId = $this->recorder->resolveTenantIdForEntity($entity);

        if (!$tenantId) {
            $this->log->info(sprintf(
                'TrackStageChange: Opportunity %s has no resolvable tenant; skipping tracking.',
                (string) $entity->getId(),
            ));

            return;
        }

        $properties = $this->buildProperties($entity, $isNew ? null : $oldStageId, $newStageId, $isNew);

        $base = [
            'contactId' => $entity->get('contactId') ?: null,
            'parentType' => Opportunity::ENTITY_TYPE,
            'parentId' => $entity->getId(),
            'properties' => $properties,
            'detail' => $entity->get('name') ?: null,
        ];

        $this->recorder->record($tenantId, self::CODE_STAGE_CHANGED, $base);

        if ($isNew) {
            $this->recorder->record($tenantId, self::CODE_CREATED, $base);
        }

        $newStatus = $entity->get('status');
        $oldStatus = $entity->getFetched('status');

        if ($newStatus === $oldStatus && !$isNew) {
            return;
        }

        if ($newStatus === 'Won') {
            $this->recorder->record($tenantId, self::CODE_WON, $base + [
                'value' => $entity->get('amount'),
                'currency' => $entity->get('amountCurrency'),
            ]);
        } elseif ($newStatus === 'Lost') {
            $this->recorder->record($tenantId, self::CODE_LOST, $base);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProperties(
        Opportunity $entity,
        ?string $fromStageId,
        string $toStageId,
        bool $isNew,
    ): array {
        return [
            'opportunityName' => $entity->get('name'),
            'fromStageId' => $fromStageId,
            'fromStageName' => $this->stageName($fromStageId),
            'toStageId' => $toStageId,
            'toStageName' => $this->stageName($toStageId),
            'funnelId' => $entity->get('funnelId'),
            'funnelName' => $entity->get('funnelName'),
            'status' => $entity->get('status'),
            'amount' => $entity->get('amount'),
            'amountCurrency' => $entity->get('amountCurrency'),
            'assignedUserId' => $entity->get('assignedUserId'),
            'sourceChannel' => $entity->get('sourceChannel') ?: null,
            'sourceTargetListId' => $entity->get('sourceTargetListId') ?: null,
            'sourceTargetListName' => $this->sourceTargetListName($entity),
            'isNew' => $isNew,
        ];
    }

    /**
     * Resolves the source target list name for analytics segmentation by
     * outreach batch. Falls back to a fetch when the name attribute is not
     * populated on the entity (ORM-level saves).
     */
    private function sourceTargetListName(Opportunity $entity): ?string
    {
        $id = $entity->get('sourceTargetListId');

        if (!$id) {
            return null;
        }

        $name = $entity->get('sourceTargetListName');

        if ($name) {
            return $name;
        }

        try {
            $targetList = $this->entityManager->getEntityById('TargetList', $id);

            return $targetList?->get('name');
        } catch (Throwable) {
            return null;
        }
    }

    private function stageName(?string $stageId): ?string
    {
        if (!$stageId) {
            return null;
        }

        try {
            $stage = $this->entityManager->getEntityById('OpportunityStage', $stageId);

            return $stage?->get('name');
        } catch (Throwable) {
            return null;
        }
    }
}
