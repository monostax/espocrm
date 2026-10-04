<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\EntityManager;

/** Cancel only open tasks saved by this enrollment, never unrelated Opportunity tasks. */
class CancelJourneyTasks implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantGuard $tenantGuard,
        private AclManager $aclManager,
    ) {}

    public function run(ActionContext $context): void
    {
        if (!$context->actor || !$context->tenantId) {
            throw new Error('CancelJourneyTasks requires a tenant and run-as user.');
        }
        $this->tenantGuard->assertRecordMatchesJourney($context->record, $context->journey);
        $this->tenantGuard->assertEntityTenant($context->record, $context->tenantId, 'enrollment');
        $acl = $this->aclManager->createUserAcl($context->actor);

        foreach ((array) ($context->record->get('recordReferences') ?? []) as $reference) {
            $ref = (array) $reference;
            if (($ref['entityType'] ?? '') !== 'Task' ||
                (int) ($ref['cycleCount'] ?? -1) !== (int) $context->record->get('cycleCount')) {
                continue;
            }
            $task = $this->entityManager->getEntityById('Task', (string) ($ref['id'] ?? ''));
            if (!$task) {
                continue;
            }
            $this->tenantGuard->assertEntityTenant($task, $context->tenantId, 'cadence task');
            if (!$acl->check($task, 'edit') ||
                in_array('status', $acl->getScopeForbiddenAttributeList('Task', 'edit'), true)) {
                throw new Error('Run-as user cannot cancel cadence tasks.');
            }
            if (!in_array($task->get('status'), ['Planned', 'Started', 'Deferred'], true)) {
                continue;
            }
            $task->set('status', 'Canceled');
            $this->entityManager->saveEntity($task, [
                SaveOption::SILENT => true,
                SaveOption::MODIFIED_BY_ID => $context->actor->getId(),
                'skipJourneyDispatch' => true,
            ]);
        }
    }
}
