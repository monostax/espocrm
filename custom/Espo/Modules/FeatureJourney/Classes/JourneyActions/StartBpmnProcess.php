<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\EntityManager;

/**
 * Platform-tier only. Asserts target tenancy before starting process.
 */
class StartBpmnProcess implements Action
{
    private const BPMN_MANAGER = 'Espo\\Modules\\Advanced\\Core\\Bpmn\\BpmnManager';
    private const FLOWCHART = 'Espo\\Modules\\Advanced\\Entities\\BpmnFlowchart';

    public function __construct(
        private EntityManager $entityManager,
        private InjectableFactory $injectableFactory,
        private TenantGuard $tenantGuard,
        private Log $log,
    ) {}

    public function run(ActionContext $context): void
    {
        if (!class_exists(self::BPMN_MANAGER) || !class_exists(self::FLOWCHART)) {
            $this->log->warning('StartBpmnProcess: Advanced BPMN not available');

            return;
        }

        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('StartBpmnProcess: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        $flowchartId = $context->params['flowchartId'] ?? null;
        if (!$flowchartId) {
            $this->log->warning('StartBpmnProcess: missing flowchartId');

            return;
        }

        $flowchart = $this->entityManager->getEntityById('BpmnFlowchart', (string) $flowchartId);
        if (!$flowchart) {
            $this->log->warning("StartBpmnProcess: flowchart {$flowchartId} not found");

            return;
        }

        try {
            $manager = $this->injectableFactory->create(self::BPMN_MANAGER);
            if (method_exists($manager, 'startProcess')) {
                $manager->startProcess($context->target, $flowchart, $context->params['elementId'] ?? null);
            }
        } catch (\Throwable $e) {
            $this->log->error('StartBpmnProcess: ' . $e->getMessage());
            throw $e;
        }
    }
}
