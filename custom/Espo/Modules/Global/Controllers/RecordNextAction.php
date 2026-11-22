<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\NextAction\NextActionWriter;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;

/** The people's next-action picker. The rules live in NextActionWriter, which the opportunity watcher shares. */
abstract class RecordNextAction
{
    private NextActionWriter $writer;

    public function __construct(
        protected EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        ServiceContainer $records,
        Metadata $metadata,
    ) {
        $this->writer = new NextActionWriter($entityManager, $acl, $records, $metadata);
    }

    abstract protected function entityType(): string;

    protected function checkMutable(Entity $record): void
    {}

    protected function taskTeamIds(Entity $record): array
    {
        /** @var \Espo\Core\ORM\Entity $record */
        return $record->getLinkMultipleIdList('teams');
    }

    public function postActionSelect(Request $request): object
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($request): object {
            $record = $this->record($request);
            $body = $request->getParsedBody();
            $this->checkExpected($record, $body);

            if ($body->createTask ?? false) {
                if (!is_string($body->name ?? null) || trim($body->name) === '') {
                    throw new BadRequest('An actionable task name is required.');
                }
                $activity = $this->writer->createTask($record, (object) [
                    'name' => trim($body->name),
                    'assignedUserId' => $record->get('assignedUserId') ?: $this->user->getId(),
                    'teamsIds' => $this->taskTeamIds($record),
                    'dateEndDate' => $body->dateEndDate ?? null,
                ]);
            } elseif ($body->activityId ?? null) {
                $activity = $this->writer->activity($record, $body->activityType ?? null, $body->activityId, true);
                $this->writer->checkPending($activity);
                if (!$this->writer->belongsTo($activity, $record)) {
                    // Unlinked or elsewhere in the workspace (e.g. a conversation's task).
                    $this->writer->moveHere($activity, $record);
                }
            } else {
                $activity = null;
            }

            return $this->saveSelection($request, $activity);
        });
    }

    public function postActionComplete(Request $request): object
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($request): object {
            $record = $this->record($request);
            $this->checkExpected($record, $request->getParsedBody());
            $activity = $this->writer->activity(
                $record, $record->get('nextActionType'), $record->get('nextActionId'),
            );
            $this->writer->checkPending($activity);
            $this->writer->complete($activity);

            // Completion and clearing the reference either both succeed or both roll back.
            return $this->saveSelection($request, null);
        });
    }

    private function record(Request $request): Entity
    {
        $record = $this->entityManager->getRDBRepository($this->entityType())
            ->where(['id' => (string) $request->getRouteParam('id')])->forUpdate()->findOne();
        if (!$record) {
            throw new NotFound();
        }
        if ((!$this->user->isRegular() && !$this->user->isAdmin()) ||
            !$this->acl->checkEntityRead($record) || !$this->acl->checkEntityEdit($record)) {
            throw new Forbidden();
        }
        $this->checkMutable($record);
        return $record;
    }

    private function checkExpected(Entity $record, stdClass $body): void
    {
        if (!property_exists($body, 'expectedId') || !property_exists($body, 'expectedType')) {
            throw new BadRequest('The displayed next-action reference is required.');
        }
        $this->writer->checkExpected($record, $body->expectedId, $body->expectedType);
    }

    private function saveSelection(Request $request, ?Entity $activity): object
    {
        // Activity hooks can update the parent's stream timestamp and advance its
        // version in this same transaction. Reload under the lock we still hold
        // instead of saving the pre-hook entity with a stale version baseline.
        $record = $this->record($request);
        $this->checkExpected($record, $request->getParsedBody());
        return $this->writer->save($record, $activity);
    }
}
