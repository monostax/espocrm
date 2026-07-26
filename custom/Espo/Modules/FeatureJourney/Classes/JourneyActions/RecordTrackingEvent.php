<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\JourneyLifecycleEmitter;
use Espo\Modules\FeatureJourney\Services\TenantGuard;

class RecordTrackingEvent implements Action
{
    public function __construct(
        private JourneyLifecycleEmitter $emitter,
        private TenantGuard $tenantGuard,
    ) {}

    public function run(ActionContext $context): void
    {
        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('RecordTrackingEvent: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        $code = (string) ($context->params['code'] ?? 'journey_action');
        $properties = $context->params['properties'] ?? [];
        if (!is_array($properties)) {
            $properties = [];
        }

        // Force tenant from journey context; never trust params.tenantId.
        $this->emitter->emit($tenantId, $code, [
            'contactId' => $context->target->getEntityType() === 'Contact'
                ? $context->target->getId()
                : null,
            'parentType' => $context->target->getEntityType(),
            'parentId' => $context->target->getId(),
            'properties' => $properties + [
                'journeyId' => $context->journey->getId(),
                'recordId' => $context->record->getId(),
                'stageId' => $context->stage->getId(),
            ],
            'detail' => $context->journey->get('name'),
        ]);
    }
}
