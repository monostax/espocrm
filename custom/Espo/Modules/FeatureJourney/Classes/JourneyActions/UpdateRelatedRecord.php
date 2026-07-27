<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyEffectDepth;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\EntityManager;

/**
 * Allow-listed field updates on related records of the journey target (same-tenant only).
 * Params: link, fields{}, optional parentEntityType, optional relatedId (single).
 */
class UpdateRelatedRecord implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantGuard $tenantGuard,
        private Metadata $metadata,
    ) {}

    public function run(ActionContext $context): void
    {
        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('UpdateRelatedRecord: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        $link = (string) ($context->params['link'] ?? '');
        if ($link === '') {
            throw new Error('UpdateRelatedRecord: link is required.');
        }

        $fields = $context->params['fields'] ?? [];
        if ($fields instanceof \stdClass) {
            $fields = (array) $fields;
        }
        if (!is_array($fields) || $fields === []) {
            return;
        }

        $maxBatch = (int) ($this->metadata->get(['app', 'journeyCreateRecord', 'maxRelatedBatch']) ?? 50);
        $maxBatch = max(1, min(200, $maxBatch));

        $parentEntityType = isset($context->params['parentEntityType'])
            ? (string) $context->params['parentEntityType']
            : null;
        if ($parentEntityType === '') {
            $parentEntityType = null;
        }

        $relatedId = isset($context->params['relatedId']) ? (string) $context->params['relatedId'] : '';

        if ($relatedId !== '') {
            $foreignType = $this->tenantGuard->resolveLinkForeignEntityType($context->target, $link);
            $related = $this->tenantGuard->loadEntityInTenant($foreignType, $relatedId, $tenantId, 'related');
            $this->assertIsRelated($context, $link, $relatedId, $maxBatch);
            $relatedList = [$related];
        } else {
            $relatedList = $this->tenantGuard->getRelatedEntitiesInTenant(
                $context->target,
                $link,
                $tenantId,
                $parentEntityType,
                $maxBatch,
            );
        }

        $skipDispatch = JourneyEffectDepth::current() > 0
            || !empty($context->params['skipJourneyDispatch']);

        foreach ($relatedList as $related) {
            $filtered = $this->tenantGuard->filterMutationFields(
                $related->getEntityType(),
                $fields,
                $tenantId,
                false,
            );

            if ($filtered === []) {
                continue;
            }

            $written = $this->tenantGuard->applyTargetUpdateFields($related, $filtered);
            if ($written === []) {
                continue;
            }

            $this->entityManager->saveEntity($related, [
                SaveOption::SILENT => false,
                'skipJourneyDispatch' => $skipDispatch,
                'modifiedById' => 'system',
            ]);
        }
    }

    private function assertIsRelated(
        ActionContext $context,
        string $link,
        string $relatedId,
        int $maxBatch,
    ): void {
        $found = $this->tenantGuard->getRelatedEntitiesInTenant(
            $context->target,
            $link,
            (string) $context->tenantId,
            null,
            $maxBatch,
        );

        foreach ($found as $e) {
            if ($e->hasId() && $e->getId() === $relatedId) {
                return;
            }
        }

        throw new Error('UpdateRelatedRecord: relatedId is not linked to target.');
    }
}
