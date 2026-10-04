<?php

declare(strict_types=1);

namespace Espo\Modules\FeaturePlaybook\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\ServiceContainer;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

class Playbooks
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private ServiceContainer $records,
        private TeamsAccess $teamsAccess,
        private Progress $progress,
    ) {}

    private function opportunity(string $id, bool $edit = false): Entity
    {
        $query = $this->entityManager->getRDBRepository('Opportunity')->where(['id' => $id]);
        $entity = ($edit ? $query->forUpdate() : $query)->findOne();
        if (!$entity) {
            throw new NotFound();
        }
        if ((!$this->user->isRegular() && !$this->user->isAdmin()) ||
            !$this->acl->checkEntityRead($entity) || ($edit && !$this->acl->checkEntityEdit($entity))) {
            throw new Forbidden();
        }
        return $entity;
    }

    private function templateAllowed(Entity $template, Entity $opportunity, string $action = 'read'): bool
    {
        return $template->get('tenantId') === $opportunity->get('tenantId') &&
            $this->acl->checkEntity($template, $action) &&
            ($this->user->isAdmin() || $this->teamsAccess->userSharesTeam($this->user, $template));
    }

    public function overview(string $id): object
    {
        $opportunity = $this->opportunity($id);
        $templates = [];
        foreach ($this->entityManager->getRDBRepository('Playbook')
            ->where(['tenantId' => $opportunity->get('tenantId')])->order('name')->find() as $template) {
            if (!$this->templateAllowed($template, $opportunity)) {
                continue;
            }
            $canEdit = $this->templateAllowed($template, $opportunity, 'edit') &&
                $this->acl->checkEntityEdit($opportunity);
            if ($template->get('status') !== 'Published' && !$canEdit) {
                continue;
            }
            $templates[] = (object) [
                'id' => $template->getId(), 'name' => $template->get('name'),
                'status' => $template->get('status'), 'revision' => $template->get('revision'),
                'canEdit' => $canEdit,
                'steps' => $this->definitions($template->getId()),
            ];
        }
        $runs = [];
        foreach ($this->entityManager->getRDBRepository('PlaybookRun')
            ->where(['opportunityId' => $id])->order('createdAt', 'DESC')->find() as $run) {
            $runs[] = $this->runData($run);
        }
        return (object) [
            'runs' => $runs, 'templates' => $templates,
            'canEdit' => $this->acl->checkEntityEdit($opportunity),
            'canCreateTemplate' => $this->acl->checkEntityEdit($opportunity) && $this->acl->checkScope('Playbook', 'create'),
        ];
    }

    public function definitions(string $id): array
    {
        $steps = [];
        foreach ($this->entityManager->getRDBRepository('PlaybookStep')->where(['playbookId' => $id])
            ->order('position')->find() as $step) {
            $steps[] = (object) $this->definition($step);
        }
        return $steps;
    }

    private function definition(Entity $step): array
    {
        return [
            'name' => $step->get('name'), 'kind' => $step->get('kind'),
            'instructions' => $step->get('instructions'), 'references' => $step->get('references') ?? [],
            'position' => $step->get('position'),
        ];
    }

    private function runData(Entity $run): object
    {
        $steps = [];
        $completed = $skipped = 0;
        foreach ($this->entityManager->getRDBRepository('PlaybookRunStep')->where(['runId' => $run->getId()])
            ->order('position')->find() as $step) {
            $task = $step->get('taskId') ? $this->entityManager->getEntityById('Task', $step->get('taskId')) : null;
            $taskReadable = $task && $this->acl->checkEntityRead($task);
            $completed += $step->get('status') === 'Completed' ? 1 : 0;
            $skipped += $step->get('status') === 'Skipped' ? 1 : 0;
            $steps[] = (object) ($this->definition($step) + [
                'id' => $step->getId(), 'status' => $step->get('status'),
                'completedAt' => $step->get('completedAt'), 'completedById' => $step->get('completedById'),
                'completedByName' => $step->get('completedByName'),
                'taskId' => $taskReadable ? $task->getId() : null,
                'hasTask' => (bool) $step->get('taskId'),
                'canEditTask' => $taskReadable && $this->acl->checkEntityEdit($task) &&
                    $this->acl->checkField('Task', 'status', 'edit'),
                'taskStatus' => $taskReadable && $this->acl->checkField('Task', 'status') ? $task->get('status') : null,
            ]);
        }
        return (object) [
            'id' => $run->getId(), 'name' => $run->get('name'), 'status' => $run->get('status'),
            'stopReason' => $run->get('stopReason'), 'playbookId' => $run->get('playbookId'),
            'sourceRevision' => $run->get('sourceRevision'),
            'assignedUserId' => $run->get('assignedUserId'), 'assignedUserName' => $run->get('assignedUserName'),
            'createdAt' => $run->get('createdAt'), 'createdByName' => $run->get('createdByName'),
            'steps' => $steps, 'completed' => $completed, 'skipped' => $skipped, 'total' => count($steps),
        ];
    }

    public function saveTemplate(string $id, stdClass $body): object
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($id, $body): object {
            $opportunity = $this->opportunity($id, true);
            return $this->saveTemplateInContext($opportunity, $body);
        });
    }

    /** The caller must resolve and authorize the workspace before supplying its account record. */
    public function saveWorkspaceTemplate(Entity $account, stdClass $body): object
    {
        return $this->entityManager->getTransactionManager()->run(
            fn () => $this->saveTemplateInContext($account, $body)
        );
    }

    private function saveTemplateInContext(Entity $context, stdClass $body): object
    {
        if (!$context->get('tenantId')) {
            throw new BadRequest('An owning tenant is required for reusable templates.');
        }
        $template = !empty($body->id)
            ? $this->entityManager->getRDBRepository('Playbook')->where(['id' => $body->id])->forUpdate()->findOne()
            : $this->entityManager->getNewEntity('Playbook');
        if (!$template) {
            throw new NotFound();
        }
        if ($template->isNew()) {
            if (!$this->acl->checkScope('Playbook', 'create')) {
                throw new Forbidden();
            }
            $template->set('tenantId', $context->get('tenantId'));
            $template->set('teamsIds', $this->teamsAccess->entityTeamIds($context));
        } elseif (!$this->templateAllowed($template, $context, 'edit')) {
            throw new Forbidden();
        } elseif (($body->expectedRevision ?? null) !== $template->get('revision')) {
            throw new Conflict('The template changed. Refresh before saving.');
        }
        $status = $body->status ?? 'Draft';
        $steps = Definitions::steps($body->steps ?? []);
        if (!in_array($status, ['Draft', 'Published', 'Archived'], true) || ($status === 'Published' && !$steps)) {
            throw new BadRequest('Published templates require at least one step.');
        }
        $template->set('name', Definitions::name($body->name ?? null));
        $template->set('status', $status);
        $template->set('revision', $template->isNew() ? 1 : $template->get('revision') + 1);
        $this->entityManager->saveEntity($template);
        foreach ($this->entityManager->getRDBRepository('PlaybookStep')->where(['playbookId' => $template->getId()])->find() as $old) {
            $this->entityManager->removeEntity($old);
        }
        foreach ($steps as $step) {
            $this->entityManager->createEntity('PlaybookStep', $step + ['playbookId' => $template->getId()]);
        }
        return (object) ['id' => $template->getId(), 'revision' => $template->get('revision')];
    }

    public function apply(string $id, stdClass $body): object
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($id, $body): object {
            $opportunity = $this->opportunity($id, true);
            if (!is_string($body->requestKey ?? null) || !preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $body->requestKey)) {
                throw new BadRequest('A unique application request key is required.');
            }
            $hash = hash('sha256', json_encode([$body->templateId ?? null, $body->name ?? null, $body->steps ?? []], JSON_THROW_ON_ERROR));
            $existing = $this->entityManager->getRDBRepository('PlaybookRun')
                ->where(['opportunityId' => $id, 'requestKey' => $body->requestKey])->findOne();
            if ($existing) {
                if ($existing->get('requestHash') !== $hash) {
                    throw new Conflict('This request key belongs to a different application.');
                }
                return $this->runData($existing);
            }
            $template = null;
            if (!empty($body->templateId)) {
                $template = $this->entityManager->getRDBRepository('Playbook')->where(['id' => $body->templateId])->forUpdate()->findOne();
                if (!$template || !$this->templateAllowed($template, $opportunity)) {
                    throw new Forbidden();
                }
                if ($template->get('status') !== 'Published') {
                    throw new Conflict('Only published templates can start new runs.');
                }
            }
            $steps = $template ? $this->definitions($template->getId()) : Definitions::steps($body->steps ?? []);
            $run = $this->entityManager->createEntity('PlaybookRun', [
                'name' => $template ? $template->get('name') : Definitions::name($body->name ?? null),
                'opportunityId' => $id, 'status' => 'Active', 'requestKey' => $body->requestKey, 'requestHash' => $hash,
                'playbookId' => $template?->getId(), 'sourceRevision' => $template?->get('revision'),
                'assignedUserId' => $opportunity->get('assignedUserId') ?: $this->user->getId(),
            ]);
            foreach ($steps as $step) {
                $this->entityManager->createEntity('PlaybookRunStep', (array) $step + ['runId' => $run->getId(), 'status' => 'Pending']);
            }
            return $this->runData($run);
        });
    }

    public function mutate(string $id, string $runId, stdClass $body): object
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($id, $runId, $body): object {
            $opportunity = $this->opportunity($id, true);
            $run = $this->entityManager->getRDBRepository('PlaybookRun')
                ->where(['id' => $runId, 'opportunityId' => $id])->forUpdate()->findOne();
            if (!$run) {
                throw new NotFound();
            }
            $action = $body->action ?? '';
            if (in_array($action, ['stop', 'cancel', 'resume'], true)) {
                $this->transition($run, $action, $body);
            } else {
                if (!in_array($run->get('status'), ['Active', 'Completed'], true)) {
                    throw new Conflict('Resume the run before changing its steps.');
                }
                if ($action === 'addStep') {
                    if ($run->get('playbookId')) {
                        throw new BadRequest('Only blank checklists can add steps after application.');
                    }
                    $count = $this->entityManager->getRDBRepository('PlaybookRunStep')->where(['runId' => $runId])->count();
                    if ($count >= 100) {
                        throw new BadRequest('At most 100 steps are supported.');
                    }
                    $this->entityManager->createEntity('PlaybookRunStep', Definitions::step($body->step ?? null, $count) + [
                        'runId' => $runId, 'status' => 'Pending',
                    ]);
                } else {
                    $step = $this->entityManager->getRDBRepository('PlaybookRunStep')
                        ->where(['id' => $body->stepId ?? '', 'runId' => $runId])->forUpdate()->findOne();
                    if (!$step) {
                        throw new NotFound();
                    }
                    $this->mutateStep($opportunity, $run, $step, $body);
                }
                $this->progress->refresh($runId);
            }
            return $this->runData($this->entityManager->getEntityById('PlaybookRun', $runId));
        });
    }

    private function transition(Entity $run, string $action, stdClass $body): void
    {
        if ($action === 'resume') {
            if (!in_array($run->get('status'), ['Stopped', 'Cancelled'], true)) {
                throw new Conflict('Only stopped or cancelled runs can be resumed.');
            }
            // Cancelled Tasks stay cancelled. Reopen each explicitly; never schedule new work here.
            $run->set('status', 'Active');
            $this->entityManager->saveEntity($run);
            $this->progress->refresh($run->getId());
            return;
        }
        if (!in_array($run->get('status'), ['Active', 'Completed'], true)) {
            throw new Conflict('The run is already closed.');
        }
        if (!is_string($body->reason ?? null) || trim($body->reason) === '' || mb_strlen($body->reason) > 2000) {
            throw new BadRequest('A stop or cancellation reason is required.');
        }
        // Set terminal state first so Task hooks preserve it throughout cancellation.
        $run->set('status', $action === 'stop' ? 'Stopped' : 'Cancelled');
        $run->set('stopReason', trim($body->reason));
        $this->entityManager->saveEntity($run);
        foreach ($this->entityManager->getRDBRepository('PlaybookRunStep')->where(['runId' => $run->getId()])->find() as $step) {
            if (in_array($step->get('status'), ['Completed', 'Skipped'], true)) {
                continue;
            }
            if ($step->get('taskId')) {
                $task = $this->entityManager->getEntityById('Task', $step->get('taskId'));
                if ($task && !in_array($task->get('status'), ['Completed', 'Canceled'], true)) {
                    $this->updateTask($task, 'Canceled');
                }
            }
            $this->progress->setStep($step, 'Cancelled');
        }
    }

    private function mutateStep(Entity $opportunity, Entity $run, Entity $step, stdClass $body): void
    {
        $action = $body->action ?? '';
        if ($action === 'activate') {
            if ($step->get('kind') !== 'Task') {
                throw new BadRequest('Only task-backed steps can be activated.');
            }
            if ($step->get('taskId')) {
                return; // A deleted or cancelled Task is never silently replaced.
            }
            if ($step->get('status') !== 'Pending') {
                throw new Conflict('Reopen the step before activating it.');
            }
            $task = $this->records->get('Task')->create((object) [
                'name' => $step->get('name'), 'description' => $step->get('instructions'),
                'parentType' => 'Opportunity', 'parentId' => $opportunity->getId(), 'status' => 'Planned',
                'assignedUserId' => $body->assignedUserId ?? $run->get('assignedUserId'),
                'teamsIds' => $this->teamsAccess->entityTeamIds($opportunity),
                'dateEndDate' => $body->dateEndDate ?? null,
            ])->getEntity();
            $step->set('taskId', $task->getId());
            $this->entityManager->saveEntity($step);
            return;
        }
        $status = $body->status ?? null;
        if ($action !== 'step' || !in_array($status, ['Pending', 'Completed', 'Skipped'], true)) {
            throw new BadRequest('Unsupported step action.');
        }
        if ($step->get('taskId')) {
            $task = $this->entityManager->getEntityById('Task', $step->get('taskId'));
            if ($status === 'Skipped') {
                if ($task && $task->get('status') !== 'Canceled') {
                    throw new BadRequest('Cancel the linked Task in Activities before skipping this step.');
                }
                $this->progress->setStep($step, 'Skipped');
                return;
            }
            if (!$task) {
                throw new Conflict('The linked Task was deleted; skip the deleted step explicitly.');
            }
            $this->updateTask($task, $status === 'Completed' ? 'Completed' : 'Planned');
        } else {
            if ($step->get('kind') === 'Task' && $status === 'Completed') {
                throw new Conflict('Activate and complete the linked Task first.');
            }
            $this->progress->setStep($step, $status);
        }
    }

    private function updateTask(Entity $task, string $status): void
    {
        if (!$this->acl->checkEntityRead($task) || !$this->acl->checkEntityEdit($task) ||
            !$this->acl->checkField('Task', 'status', 'edit')) {
            throw new Forbidden('The linked Task cannot be edited.');
        }
        $this->records->get('Task')->update($task->getId(), (object) ['status' => $status]);
    }
}
