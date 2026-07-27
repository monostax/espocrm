<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyEffectDepth;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\EntityManager;

/**
 * Allow-listed field updates on the journey target (tenant-safe power write).
 * Params: { "fields": { "status": "..." } }
 */
class UpdateTarget implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantGuard $tenantGuard,
    ) {}

    public function run(ActionContext $context): void
    {
        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('UpdateTarget: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        $fields = $context->params['fields'] ?? $context->params;

        if ($fields instanceof \stdClass) {
            $fields = (array) $fields;
        }

        if (!is_array($fields)) {
            return;
        }

        // Allow nested {fields:{...}} or flat params minus reserved keys.
        if (isset($fields['fields']) && is_array($fields['fields'])) {
            $fields = $fields['fields'];
        } else {
            unset(
                $fields['fields'],
                $fields['type'],
                $fields['formula'],
                $fields['script'],
                $fields['paramFormulas'],
            );
        }

        $filtered = $this->tenantGuard->filterTargetUpdateFields(
            $context->target->getEntityType(),
            $fields
        );

        if ($filtered === []) {
            return;
        }

        $written = $this->tenantGuard->applyTargetUpdateFields($context->target, $filtered);

        if ($written === []) {
            return;
        }

        // Inside nested journey effects, do not re-fire entity-change journey hooks.
        $skipDispatch = JourneyEffectDepth::current() > 0
            || !empty($context->params['skipJourneyDispatch']);

        $saveOpts = [
            SaveOption::SILENT => false,
            'skipJourneyDispatch' => $skipDispatch,
        ];
        if ($context->actor !== null) {
            $saveOpts[SaveOption::MODIFIED_BY_ID] = $context->actor->getId();
        }

        $this->entityManager->saveEntity($context->target, $saveOpts);
    }
}
