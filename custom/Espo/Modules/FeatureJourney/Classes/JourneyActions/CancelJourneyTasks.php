<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\EntityManager;

/** Close only open activities saved by this enrollment; preserve completed work. */
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
            $entityType = $ref['entityType'] ?? '';
            if (!in_array($entityType, ['Task', 'Call', 'Email'], true) ||
                (int) ($ref['cycleCount'] ?? -1) !== (int) $context->record->get('cycleCount')) {
                continue;
            }
            $task = $this->entityManager->getEntityById($entityType, (string) ($ref['id'] ?? ''));
            if (!$task) {
                continue;
            }
            $this->tenantGuard->assertEntityTenant($task, $context->tenantId, 'cadence task');
            $openStatuses = match ($entityType) {
                'Call' => ['Planned'],
                'Email' => ['Draft'],
                default => ['Planned', 'Started', 'Deferred'],
            };
            if (!in_array($task->get('status'), $openStatuses, true)) {
                continue;
            }
            if ($entityType === 'Email') {
                if (!$acl->check($task, 'delete')) {
                    throw new Error('Run-as user cannot remove cadence email drafts.');
                }
                $this->entityManager->removeEntity($task, [SaveOption::SILENT => true]);
                continue;
            }
            if (!$acl->check($task, 'edit') ||
                in_array('status', $acl->getScopeForbiddenAttributeList($entityType, 'edit'), true)) {
                throw new Error('Run-as user cannot cancel cadence tasks.');
            }
            $task->set('status', $entityType === 'Call' ? 'Not Held' : 'Canceled');
            $this->entityManager->saveEntity($task, [
                SaveOption::SILENT => true,
                SaveOption::MODIFIED_BY_ID => $context->actor->getId(),
                'skipJourneyDispatch' => true,
            ]);
        }
    }
}
