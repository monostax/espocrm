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
 * Platform-tier only. Asserts target tenancy; loads Workflow by id and runs actions.
 */
class TriggerWorkflow implements Action
{
    private const MANAGER = 'Espo\\Modules\\Advanced\\Core\\WorkflowManager';
    private const WORKFLOW_ENTITY = 'Workflow';

    public function __construct(
        private EntityManager $entityManager,
        private InjectableFactory $injectableFactory,
        private TenantGuard $tenantGuard,
        private Log $log,
    ) {}

    public function run(ActionContext $context): void
    {
        if (!class_exists(self::MANAGER)) {
            $this->log->warning('TriggerWorkflow: Advanced WorkflowManager not available');

            return;
        }

        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('TriggerWorkflow: missing tenantId.');
        }

        $this->tenantGuard->assertEntityTenant($context->target, $tenantId, 'target');

        $workflowId = $context->params['workflowId'] ?? null;
        if (!$workflowId) {
            $this->log->warning('TriggerWorkflow: missing workflowId');

            return;
        }

        $workflow = $this->entityManager->getEntityById(self::WORKFLOW_ENTITY, (string) $workflowId);
        if (!$workflow) {
            $this->log->warning("TriggerWorkflow: workflow {$workflowId} not found");

            return;
        }

        try {
            $manager = $this->injectableFactory->create(self::MANAGER);
            if (method_exists($manager, 'runActions')) {
                $manager->runActions($workflow, $context->target, []);
            } else {
                $this->log->warning('TriggerWorkflow: WorkflowManager::runActions missing');
            }
        } catch (\Throwable $e) {
            $this->log->error('TriggerWorkflow: ' . $e->getMessage());
            throw $e;
        }
    }
}
