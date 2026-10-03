<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTaskRecurrence\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Entities\User;
use Espo\Modules\FeatureTaskRecurrence\Tools\MutationContext;
use Espo\Modules\FeatureTaskRecurrence\Tools\Schedule;
use Espo\Modules\FeatureTaskRecurrence\Tools\Template;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Application boundary. Technical entities are never exposed through generic record services. */
class Recurrence
{
    public function __construct(
        private EntityManager $entityManager,
        private ServiceContainer $services,
        private Schedule $schedule,
        private Template $template,
        private TenantResolver $tenants,
        private Acl $acl,
        private User $user,
        private MutationContext $taskRecurrenceMutationContext,
    ) {}

    public function preview(object $input): object
    {
        $this->permission('read');
        $task = !empty($input->taskId) ? $this->task($input->taskId, 'read') : null;
        $definition = $this->normalize($input->definition ?? $input, $input->task ?? $task?->getValueMap());
        $head = null;
        $from = null;
        $sequence = 1;
        if ($task?->get('recurrenceSeriesId')) {
            $series = $this->series($task->get('recurrenceSeriesId'));
            if ($definition->basis === 'CompletedDate' && $series->get('basis') === 'CompletedDate') {
                $head = $this->entityManager->getEntityById('TaskRecurrenceOccurrence', $series->get('headId'));
                $sequence = (int) ($head?->get('sequence') ?? 1);
            } elseif ($definition->basis === 'ScheduledDate' && $definition->dateOnly === $series->get('definition')->dateOnly) {
                $from = $this->occurrence($task, false)->get('originalDeadline');
            }
        }
        $result = $this->schedule->preview($definition, $input->exampleCompletion ?? null, $sequence, $from);
        $headTask = $head?->get('taskId') ? $this->entityManager->getEntityById('Task', $head->get('taskId')) : null;
        if ($headTask && $this->acl->checkEntityRead($headTask) && $this->acl->checkField('Task', 'dateEnd')) {
            $result->head = (object) ['taskId' => $head->get('taskId'), 'sequence' => $head->get('sequence'), 'currentDeadline' => $headTask->get('dateEndDate') ?: $headTask->get('dateEnd'), 'eventAt' => $head->get('eventAt')];
        }
        return $result;
    }

    public function normalize(object $input, ?object $task = null): object
    {
        try { return $this->schedule->normalize($input, $task); }
        catch (\InvalidArgumentException $e) { throw new BadRequest($e->getMessage()); }
    }

    /** Serialize submission retries on the actor row, before reserving a tenant/actor request key. */
    public function create(object $data, object $input, \Closure $nativeCreate): mixed
    {
        $this->permission('create');
        if (empty($data->dateEnd) && empty($data->dateEndDate)) throw new BadRequest('recurrence.anchor: Choose a Task deadline first.');
        $key = $input->idempotencyKey ?? null;
        if (!is_string($key) || strlen($key) < 8 || strlen($key) > 128) throw new BadRequest('recurrence.idempotencyKey: Supply a stable submission key.');
        return $this->entityManager->getTransactionManager()->run(function () use ($data, $input, $nativeCreate, $key) {
            $this->entityManager->getRDBRepository('User')->select('id')->where(['id' => $this->user->getId()])->forUpdate()->findOne() ?? throw new Forbidden();
            $tenantId = $this->tenant($data->teamsIds ?? []);
            $requestKey = hash('sha256', $tenantId . ':' . $this->user->getId() . ':' . $key);
            $existing = $this->entityManager->getRDBRepository('TaskRecurrenceSeries')->where(['requestKey' => $requestKey])->findOne();
            if ($existing) {
                $occurrence = $this->entityManager->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $existing->getId()])->order('sequence')->findOne();
                if (!$occurrence || !$occurrence->get('taskId')) throw new Conflict('This submission is no longer actionable.');
                return new \Espo\Core\Record\CreateResult($this->services->get('Task')->read($occurrence->get('taskId'))->getEntity());
            }
            $definition = $this->normalize($input->definition ?? $input, $data);
            $dates = $this->template->dates($data, $definition->anchor, $definition);
            foreach ((array) $dates as $name => $value) $data->$name = $value;
            $result = $nativeCreate($data);
            $task = $result->getEntity();
            $this->bind($task, $definition, $requestKey);
            $task->set('recurrence', $this->read($task->getId()));
            return $result;
        });
    }

    public function convert(string $taskId, object $input): object
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($taskId, $input) {
            $task = $this->task($taskId, 'edit');
            if ($task->get('recurrenceSeriesId')) return $this->read($taskId);
            $this->entityManager->getRDBRepository('Task')->select('id')->where(['id' => $taskId])->forUpdate()->findOne() ?? throw new NotFound();
            $task = $this->entityManager->getEntityById('Task', $taskId) ?? throw new NotFound();
            if ($task->get('recurrenceSeriesId')) return $this->read($taskId);
            $this->services->get('Task')->loadAdditionalFields($task);
            if (isset($input->patch)) $task = $this->services->get('Task')->update($taskId, clone $input->patch)->getEntity();
            $definition = $this->normalize($input->definition ?? $input, $task->getValueMap());
            $current = $definition->dateOnly ? $task->get('dateEndDate') : $task->get('dateEnd');
            if ($current !== $definition->anchor) {
                $task = $this->services->get('Task')->update($taskId, $this->template->dates($task->getValueMap(), $definition->anchor, $definition))->getEntity();
            }
            $this->bind($task, $definition, null);
            return $this->read($taskId);
        });
    }

    private function bind(Entity $task, object $definition, ?string $requestKey): void
    {
        if (in_array($task->get('status'), ['Completed', 'Canceled'], true)) throw new BadRequest('Enable recurrence on an open Task.');
        $snapshot = $this->template->snapshot($task);
        $tenantId = $this->tenant($snapshot->teamsIds ?? []);
        $this->validateTemplate($snapshot, $tenantId);
        $series = $this->entityManager->createEntity('TaskRecurrenceSeries', [
            'segmentOrder' => 1,
            'name' => $task->get('name'), 'tenantId' => $tenantId, 'actorId' => $this->user->getId(),
            'definition' => $definition, 'template' => $snapshot, 'basis' => $definition->basis,
            'state' => 'Active', 'version' => 1, 'sequence' => 1, 'requestKey' => $requestKey,
            'createdAt' => gmdate('Y-m-d H:i:s'),
        ]);
        $series->set('lineageId', $series->getId());
        $deadline = $definition->anchor;
        $identity = $definition->basis === 'CompletedDate' ? 'C:0000000001' : $this->schedule->identity($deadline, $definition);
        $occurrence = $this->reserve($series, $identity, $deadline, 1, null);
        $occurrence->set('taskId', $task->getId());
        $this->entityManager->saveEntity($occurrence);
        $series->set(['headId' => $occurrence->getId(), 'cursor' => $deadline]);
        $this->entityManager->saveEntity($series);
        $this->attach($task, $series, $identity);
    }

    public function read(string $taskId): ?object
    {
        $task = $this->task($taskId, 'read');
        if (!$task->get('recurrenceSeriesId')) return null;
        $series = $this->series($task->get('recurrenceSeriesId'));
        $definition = $series->get('definition');
        return (object) [
            'seriesId' => $series->getId(), 'lineageId' => $series->get('lineageId'), 'version' => $series->get('version'),
            'definition' => $definition, 'state' => $series->get('state'), 'originalId' => $task->get('recurrenceId'),
            'summary' => $this->schedule->summary($definition), 'lastError' => $series->get('lastError'),
            'pending' => $series->get('basis') === 'ScheduledDate' ? !$series->get('lastProcessedAt') : $this->pending($series),
            'canEdit' => $this->acl->checkEntityEdit($task) && $this->acl->checkField('Task', 'recurrence', 'edit'),
            'canDelete' => $this->acl->checkEntityDelete($task),
        ];
    }

    /** ORM hook runs inside transactionalSave, also covering mass updates and direct native deletion. */
    public function beforeSave(Entity $task): void
    {
        if ($this->taskRecurrenceMutationContext->coordinated()) return;
        foreach (['recurrenceSeriesId', 'recurrenceId', 'recurrenceOverrides'] as $attribute) {
            if ($task->isAttributeChanged($attribute)) throw new BadRequest('Recurrence identity is read-only.');
        }
        if (!$task->get('recurrenceSeriesId')) return;
        $series = $this->lock($task->get('recurrenceSeriesId'));
        if (in_array($series->get('state'), ['Active', 'Paused'], true) && !$task->get('dateEnd') && !$task->get('dateEndDate')) throw new BadRequest('End recurrence explicitly before clearing its deadline.');
        if ($task->isAttributeChanged('teamsIds') && $this->tenant($task->get('teamsIds') ?? []) !== $series->get('tenantId')) {
            throw new BadRequest('Recurring Tasks cannot move to a different workspace.');
        }
        $overrides = $task->get('recurrenceOverrides') ?? [];
        foreach ($this->template->attributes() as $attribute) {
            if ($task->isAttributeChanged($attribute) && !in_array($attribute, $overrides, true)) $overrides[] = $attribute;
        }
        $task->set('recurrenceOverrides', $overrides);
    }

    public function afterSave(Entity $task): void
    {
        if ($this->taskRecurrenceMutationContext->coordinated() || !$task->get('recurrenceSeriesId')) return;
        if ($task->isAttributeChanged('status') && $task->get('status') === 'Completed') {
            $this->receipt($task, 'Completed', $task->get('dateCompleted') ?: gmdate('Y-m-d H:i:s'));
        }
    }

    public function beforeRemove(Entity $task): void
    {
        if ($this->taskRecurrenceMutationContext->coordinated() || !$task->get('recurrenceSeriesId')) return;
        $this->lock($task->get('recurrenceSeriesId'));
        $this->receipt($task, 'Deleted', gmdate('Y-m-d H:i:s'));
        $occurrence = $this->occurrence($task);
        $occurrence->set('disposition', 'Deleted');
        $this->entityManager->saveEntity($occurrence);
        $this->suppressAliases($task, 'Deleted');
    }

    public function mutate(string $taskId, object $input): ?object
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($taskId, $input) {
            $action = $input->action ?? 'edit';
            if (!in_array($action, ['edit', 'delete', 'skip', 'pause', 'resume', 'end'], true)) throw new BadRequest('Invalid recurrence action.');
            $scope = $input->scope ?? 'ThisOccurrence';
            if (!in_array($scope, ['ThisOccurrence', 'ThisAndFollowing', 'WholeSeries'], true)) throw new BadRequest('Invalid recurrence scope.');
            $task = $this->task($taskId, $action === 'delete' ? 'delete' : 'edit');
            if (!$task->get('recurrenceSeriesId')) throw new BadRequest('This Task does not recur.');
            $selected = $this->series($task->get('recurrenceSeriesId'));
            $segments = [];
            foreach ($this->entityManager->getRDBRepository('TaskRecurrenceSeries')->select('id')->where(['lineageId' => $selected->get('lineageId')])->order('id')->forUpdate()->find() as $locked) {
                $segment = $this->series($locked->getId());
                $segments[$segment->getId()] = $segment;
            }
            $selected = $segments[$selected->getId()];
            if (!is_int($input->version ?? null) || $input->version !== (int) $selected->get('version')) throw new Conflict('Recurrence changed. Reload the Task before applying this operation.');
            $boundary = $task->get('recurrenceId');
            $patch = $this->template->patch($input->patch ?? new \stdClass());
            $forbidden = $this->acl->getScopeForbiddenAttributeList('Task', 'edit');
            foreach ((array) $patch as $name => $value) {
                if (in_array($name, $forbidden, true)) throw new Forbidden();
                if (json_encode($task->get($name)) === json_encode($value)) unset($patch->$name);
            }
            if ($scope === 'ThisOccurrence' && in_array($action, ['edit', 'delete', 'skip'], true)) {
                if (isset($input->definition)) throw new BadRequest('Select a series scope to change its definition.');
                if (!empty($input->preview)) return (object) ['affectedCount' => 1, 'retainedExceptions' => 0];
                if ($action === 'delete') { $this->services->get('Task')->delete($taskId); return null; }
                if ($action === 'skip') {
                    $this->receipt($task, 'Skipped', gmdate('Y-m-d H:i:s'));
                    $occurrence = $this->occurrence($task);
                    $occurrence->set('disposition', 'Skipped');
                    $this->entityManager->saveEntity($occurrence);
                    $this->suppressAliases($task, 'Skipped');
                    $this->taskRecurrenceMutationContext->run(fn () => $this->services->get('Task')->update($taskId, (object) ['status' => 'Canceled']));
                } else {
                    $this->services->get('Task')->update($taskId, clone ($input->patch ?? new \stdClass()));
                }
                return $this->read($taskId);
            }
            if ($action === 'skip') throw new BadRequest('Skip applies to this occurrence only.');
            if ($action === 'end' && $scope === 'ThisOccurrence') throw new BadRequest('Choose This and following or Whole series to end recurrence.');
            if ($action === 'edit' && $scope === 'ThisOccurrence') throw new BadRequest('Select a series scope.');
            if (in_array($action, ['pause', 'resume'], true) && $scope !== 'WholeSeries') throw new BadRequest('Pause/resume applies to the whole series.');
            $rows = [];
            $exceptions = 0;
            foreach ($segments as $segment) {
                if ($scope === 'ThisAndFollowing' && (int) $segment->get('segmentOrder') < (int) $selected->get('segmentOrder')) continue;
                $occurrences = $this->entityManager->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $segment->getId()])->order('recurrenceId')->limit(0, 2001)->find();
                foreach ($occurrences as $occurrence) {
                    if ($scope === 'ThisAndFollowing' && $segment->getId() === $selected->getId() && strcmp($occurrence->get('recurrenceId'), $boundary) < 0) continue;
                    if (!$occurrence->get('taskId')) continue;
                    $item = $this->entityManager->getEntityById('Task', $occurrence->get('taskId'));
                    if (!$item || !$this->eligible($item, $occurrence, $segment)) continue;
                    if (!$this->acl->checkEntity($item, in_array($action, ['delete', 'end'], true) ? 'delete' : 'edit')) throw new Forbidden('One or more affected Tasks cannot be changed.');
                    if ($item->get('recurrenceOverrides')) $exceptions++;
                    $rows[] = [$item, $occurrence, $segment];
                    if (count($rows) > 2000) throw new BadRequest('The coordinated operation exceeds 2000 open Tasks. End older segments first.');
                }
            }
            $definition = isset($input->definition) ? $this->normalize($input->definition, $task->getValueMap()) : null;
            if (!empty($input->preview)) return (object) [
                'affectedCount' => count($rows), 'retainedExceptions' => $exceptions, 'boundary' => $boundary,
                'schedule' => $definition ? $this->schedule->preview($definition) : null,
            ];
            if (in_array($action, ['pause', 'resume'], true)) {
                foreach ($segments as $segment) {
                    if ($action === 'pause' && $segment->get('state') === 'Active') {
                        $segment->set(['state' => 'Paused', 'pausedAt' => gmdate('Y-m-d H:i:s')]);
                    } elseif ($action === 'resume' && $segment->get('state') === 'Paused') {
                        $intervals = $segment->get('pauseIntervals') ?? [];
                        $intervals[] = (object) ['from' => $segment->get('pausedAt'), 'through' => gmdate('Y-m-d H:i:s')];
                        $segment->set(['state' => 'Active', 'pauseIntervals' => $intervals, 'pausedAt' => null]);
                    }
                    $this->revise($segment);
                }
                return $this->read($taskId);
            }
            if (in_array($action, ['delete', 'end'], true)) {
                foreach ($segments as $segment) {
                    if ($scope === 'ThisAndFollowing') {
                        if ($segment->getId() === $selected->getId()) {
                            $segment->set('boundaryEnd', $boundary);
                            if ($segment->get('basis') === 'CompletedDate') $segment->set('state', 'Ended');
                        } elseif ((int) $segment->get('segmentOrder') > (int) $selected->get('segmentOrder')) $segment->set('state', 'Ended');
                    } else $segment->set('state', 'Ended');
                    $this->revise($segment);
                }
                foreach ($rows as [$item, $occurrence]) {
                    if ($action === 'end' && $item->getId() === $taskId) continue;
                    $this->retire($item, $occurrence);
                }
                if ($action === 'end' && isset($input->patch)) $this->services->get('Task')->update($taskId, clone $input->patch);
                return $action === 'delete' ? null : (object) ['state' => 'Ended', 'affectedCount' => count($rows)];
            }
            if ($definition && ($definition->basis !== $selected->get('basis') || $definition->dateOnly !== $selected->get('definition')->dateOnly || $definition->timezone !== $selected->get('definition')->timezone)) {
                return $this->migrate($task, $selected, $segments, $rows, $definition, $patch, $scope);
            }
            if ($scope === 'ThisAndFollowing') {
                $successor = $this->split($task, $selected, $segments, $definition, $patch, $rows);
                foreach ($rows as [$item, $occurrence, $segment]) {
                    if ($definition === null && $segment->getId() !== $selected->getId()) {
                        $this->reconcile($item, $occurrence, $segment, $patch, false, false);
                        continue;
                    }
                    $occurrence->set('seriesSegmentId', $successor->getId());
                    $this->entityManager->saveEntity($occurrence);
                    $item->set('recurrenceSeriesId', $successor->getId());
                    $this->entityManager->saveEntity($item, [SaveOption::SKIP_ALL => true]);
                    $this->reconcile($item, $occurrence, $successor, $patch, $definition !== null, $item->getId() === $taskId);
                }
            } else {
                foreach ($segments as $segment) {
                    $snapshot = (object) array_merge((array) $segment->get('template'), (array) $patch);
                    $this->validateTemplate($snapshot, $segment->get('tenantId'));
                    $segment->set('template', $snapshot);
                    if ($definition) {
                        $segment->set(['definition' => $definition, 'cursor' => $this->beforeToday($definition), 'lastProcessedAt' => null]);
                        if ($segment->get('state') === 'Exhausted') $segment->set('state', 'Active');
                    }
                    $this->revise($segment);
                }
                foreach ($rows as [$item, $occurrence, $segment]) $this->reconcile($item, $occurrence, $segment, $patch, $definition !== null, $item->getId() === $taskId);
            }
            return $this->read($taskId);
        });
    }

    private function eligible(Entity $task, Entity $occurrence, Entity $series): bool
    {
        if (in_array($task->get('status'), ['Completed', 'Canceled'], true)) return false;
        if ($series->get('basis') === 'CompletedDate') return $series->get('headId') === $occurrence->getId() && !$occurrence->get('advanced');
        $definition = $series->get('definition');
        return $this->schedule->localDate($occurrence->get('originalDeadline'), $definition) >= (new DateTimeImmutable('now', new DateTimeZone($definition->timezone)))->format('Y-m-d');
    }

    private function revise(Entity $series): void
    {
        $series->set('version', (int) $series->get('version') + 1);
        $this->entityManager->saveEntity($series);
    }

    private function retire(Entity $task, Entity $occurrence): void
    {
        $occurrence->set('disposition', 'Retired');
        $this->entityManager->saveEntity($occurrence);
        $this->suppressAliases($task, 'Retired');
        $this->taskRecurrenceMutationContext->run(fn () => $this->services->get('Task')->delete($task->getId()));
    }

    private function reconcile(Entity $task, Entity $occurrence, Entity $series, object $patch, bool $changed, bool $selected): void
    {
        $definition = $series->get('definition');
        $overrides = $task->get('recurrenceOverrides') ?? [];
        if ($changed && $definition->basis === 'ScheduledDate' && !$this->schedule->contains($occurrence->get('originalDeadline'), $definition)) {
            if (!$overrides && !$selected) { $this->retire($task, $occurrence); return; }
            // Explicit exceptions retain their Task ID and original reservation outside the new rule.
        }
        $values = clone $patch;
        if ($changed && $selected && $definition->basis === 'ScheduledDate' && !$this->schedule->contains($occurrence->get('originalDeadline'), $definition)) {
            $identity = $this->schedule->identity($definition->anchor, $definition);
            $alias = $this->entityManager->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $series->getId(), 'recurrenceId' => $identity])->findOne();
            if (!$alias) {
                $alias = $this->reserve($series, $identity, $definition->anchor, (int) $occurrence->get('sequence'), null);
                $alias->set('aliasTaskId', $task->getId());
                $this->entityManager->saveEntity($alias);
            }
            foreach ((array) $this->template->dates($series->get('template'), $definition->anchor, $definition) as $name => $value) $values->$name = $value;
        }
        if (array_intersect(['dateStart', 'dateStartDate', 'dateEnd', 'dateEndDate'], array_keys((array) $patch))) {
            foreach ((array) $this->template->dates($series->get('template'), $occurrence->get('originalDeadline'), $definition) as $name => $value) $values->$name = $value;
        }
        foreach ($overrides as $name) unset($values->$name);
        if ((array) $values) $this->taskRecurrenceMutationContext->run(fn () => $this->services->get('Task')->update($task->getId(), $values));
        $occurrence->set('templateVersion', $series->get('version'));
        $this->entityManager->saveEntity($occurrence);
    }

    private function suppressAliases(Entity $task, string $disposition): void
    {
        foreach ($this->entityManager->getRDBRepository('TaskRecurrenceOccurrence')->where(['aliasTaskId' => $task->getId()])->find() as $alias) {
            $alias->set('disposition', $disposition);
            $this->entityManager->saveEntity($alias);
        }
    }

    private function split(Entity $task, Entity $selected, array $segments, ?object $definition, object $patch, array $rows): Entity
    {
        $boundary = $task->get('recurrenceId');
        $original = $selected->get('definition');
        $order = (int) $selected->get('segmentOrder');
        $snapshot = (object) array_merge((array) $selected->get('template'), (array) $patch);
        $this->validateTemplate($snapshot, $selected->get('tenantId'));
        if ($definition && $definition->basis === 'CompletedDate' && $definition->count !== null) {
            // A scoped count is a remaining-cycle count, including the selected cycle.
            $definition->count += (int) $this->occurrence($task)->get('sequence') - 1;
        }
        $successor = $this->entityManager->createEntity('TaskRecurrenceSeries', [
            'segmentOrder' => $order + 1,
            'name' => $snapshot->name, 'tenantId' => $selected->get('tenantId'), 'actorId' => $this->user->getId(),
            'lineageId' => $selected->get('lineageId'), 'definition' => $definition ?? $original,
            'template' => $snapshot, 'basis' => $original->basis, 'state' => $definition ? ($selected->get('state') === 'Paused' ? 'Paused' : 'Active') : $selected->get('state'),
            'version' => (int) $selected->get('version') + 1, 'sequence' => $selected->get('sequence'),
            'headId' => $selected->get('headId'), 'boundaryStart' => $definition && $definition->basis === 'ScheduledDate' ? $this->schedule->identity($definition->anchor, $definition) : $boundary,
            'boundaryEnd' => $definition ? null : $selected->get('boundaryEnd'),
            'cursor' => $definition ? $this->beforeToday($definition) : $this->beforeDeadline($this->occurrence($task)->get('originalDeadline'), $original),
            'pauseIntervals' => $selected->get('pauseIntervals'), 'pausedAt' => $selected->get('pausedAt'), 'createdAt' => gmdate('Y-m-d H:i:s'),
        ]);
        foreach ($segments as $segment) {
            if ((int) $segment->get('segmentOrder') > $order) {
                $segment->set('segmentOrder', (int) $segment->get('segmentOrder') + 1);
                if ($definition) $segment->set('state', 'Ended');
                else {
                    $template = (object) array_merge((array) $segment->get('template'), (array) $patch);
                    $this->validateTemplate($template, $segment->get('tenantId'));
                    $segment->set('template', $template);
                    $this->revise($segment);
                    continue;
                }
            } elseif ($segment->getId() !== $selected->getId()) { $this->revise($segment); continue; }
            if ($segment->getId() === $selected->getId()) {
                if (!$segment->get('boundaryEnd') || strcmp($segment->get('boundaryEnd'), $boundary) > 0) $segment->set('boundaryEnd', $boundary);
                if ($segment->get('basis') === 'CompletedDate') $segment->set('state', 'Ended');
            }
            // Preserve skipped/deleted/completed reservations in the successor segment as suppression.
            $where = ['seriesSegmentId' => $segment->getId()];
            if ($segment->getId() === $selected->getId()) $where['recurrenceId>='] = $boundary;
            foreach ($this->entityManager->getRDBRepository('TaskRecurrenceOccurrence')->where($where)->find() as $old) {
                if (in_array($old->getId(), array_map(fn ($row) => $row[1]->getId(), $rows), true)) continue;
                if ($this->entityManager->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $successor->getId(), 'recurrenceId' => $old->get('recurrenceId')])->findOne()) continue;
                $copy = $this->reserve($successor, $old->get('recurrenceId'), $old->get('originalDeadline'), (int) $old->get('sequence'), null);
                $copy->set('disposition', 'Retired');
                $this->entityManager->saveEntity($copy);
            }
            $this->revise($segment);
        }
        // Eligible records move their existing reservation; historical rows remain on their segment.
        return $successor;
    }

    private function migrate(Entity $task, Entity $selected, array $segments, array $rows, object $definition, object $patch, string $scope): object
    {
        $head = $this->occurrence($task);
        if (!$this->eligible($task, $head, $selected)) throw new BadRequest('Switch scheduling basis/timezone/date type from an open active occurrence.');
        if ($scope === 'ThisOccurrence') throw new BadRequest('Choose a series scope for a scheduling migration.');
        $snapshot = (object) array_merge((array) $selected->get('template'), (array) $patch);
        $this->validateTemplate($snapshot, $selected->get('tenantId'));
        foreach ($segments as $segment) {
            if ($scope === 'WholeSeries') $segment->set('state', 'Ended');
            elseif ($segment->getId() === $selected->getId()) {
                $segment->set('boundaryEnd', $task->get('recurrenceId'));
                if ($segment->get('basis') === 'CompletedDate') $segment->set('state', 'Ended');
            } elseif ((int) $segment->get('segmentOrder') > (int) $selected->get('segmentOrder')) $segment->set('state', 'Ended');
            $this->revise($segment);
        }
        foreach ($rows as [$item, $occurrence]) {
            if ($item->getId() !== $task->getId() && !$item->get('recurrenceOverrides')) $this->retire($item, $occurrence);
        }
        // Retain the old identity as durable provenance while explicitly migrating the selected head.
        $head->set(['taskId' => null, 'disposition' => 'Retired']);
        $this->entityManager->saveEntity($head);
        $new = $this->entityManager->createEntity('TaskRecurrenceSeries', [
            'segmentOrder' => max(array_map(fn ($segment) => (int) $segment->get('segmentOrder'), $segments)) + 1,
            'name' => $snapshot->name, 'tenantId' => $selected->get('tenantId'), 'actorId' => $this->user->getId(),
            'lineageId' => $selected->get('lineageId'), 'definition' => $definition, 'template' => $snapshot,
            'basis' => $definition->basis, 'state' => 'Active', 'version' => (int) $selected->get('version'), 'sequence' => 1,
            'cursor' => $definition->anchor, 'createdAt' => gmdate('Y-m-d H:i:s'),
        ]);
        $id = $definition->basis === 'CompletedDate' ? 'C:0000000001' : $this->schedule->identity($definition->anchor, $definition);
        $occurrence = $this->reserve($new, $id, $definition->anchor, 1, $head->getId());
        $occurrence->set('taskId', $task->getId());
        $this->entityManager->saveEntity($occurrence);
        $new->set('headId', $occurrence->getId());
        $this->entityManager->saveEntity($new);
        $values = (object) array_merge((array) $patch, (array) $this->template->dates($snapshot, $definition->anchor, $definition));
        $this->taskRecurrenceMutationContext->run(fn () => $this->services->get('Task')->update($task->getId(), $values));
        $overrides = $task->get('recurrenceOverrides') ?? [];
        $this->attach($task, $new, $id);
        $task->set('recurrenceOverrides', $overrides);
        $this->entityManager->saveEntity($task, [SaveOption::SKIP_ALL => true]);
        return $this->read($task->getId());
    }

    private function beforeDeadline(string $deadline, object $definition): string
    {
        return $this->schedule->slotDate($deadline, $definition)->modify($definition->dateOnly ? '-1 day' : '-1 second')->format($definition->dateOnly ? 'Y-m-d' : 'Y-m-d H:i:s');
    }

    private function beforeToday(object $definition): string
    {
        $today = new DateTimeImmutable('today', new DateTimeZone($definition->timezone));
        $point = $definition->dateOnly ? new DateTimeImmutable($today->format('Y-m-d'), new DateTimeZone('UTC')) : $today;
        return $point->modify($definition->dateOnly ? '-1 day' : '-1 second')->setTimezone(new DateTimeZone('UTC'))->format($definition->dateOnly ? 'Y-m-d' : 'Y-m-d H:i:s');
    }

    private function receipt(Entity $task, string $type, string $at): void
    {
        $series = $this->lock($task->get('recurrenceSeriesId'));
        $occurrence = $this->occurrence($task);
        if ($series->get('basis') !== 'CompletedDate' || $series->get('headId') !== $occurrence->getId() ||
            !in_array($series->get('state'), ['Active', 'Paused'], true) || $occurrence->get('eventAt')) return;
        $occurrence->set(['eventAt' => $at, 'eventType' => $type, 'advanced' => false]);
        $this->entityManager->saveEntity($occurrence);
    }

    /** Each generated Task and reservation commits together; earlier successful chunks survive retry. */
    public function process(string $seriesId, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $definition = $this->series($seriesId)->get('definition');
        if ($definition->basis === 'ScheduledDate') {
            $today = $now->setTimezone(new DateTimeZone($definition->timezone))->format('Y-m-d');
            $this->schedule->assertDensity($definition, $definition->dateOnly ? $today : $now->format('Y-m-d H:i:s'));
        }
        for ($i = 0; $i < Schedule::CHUNK_SIZE; $i++) {
            $more = $this->entityManager->getTransactionManager()->run(function () use ($seriesId, $now) {
                $series = $this->lock($seriesId);
                if ($series->get('state') !== 'Active') return false;
                if ($series->get('actorId') !== $this->user->getId() || !$this->user->get('isActive')) throw new Forbidden('The recurrence execution user is inactive.');
                $this->permission('create');
                $this->validateTemplate($series->get('template'), $series->get('tenantId'));
                return $series->get('basis') === 'CompletedDate' ? $this->advance($series) : $this->calendar($series, $now);
            });
            if (!$more) break;
        }
        $this->entityManager->getTransactionManager()->run(function () use ($seriesId, $now) {
            $series = $this->lock($seriesId);
            $series->set(['lastProcessedAt' => $now->format('Y-m-d H:i:s'), 'lastError' => null]);
            $this->entityManager->saveEntity($series);
        });
    }

    private function calendar(Entity $series, DateTimeImmutable $now): bool
    {
        $definition = $series->get('definition');
        $deadline = $this->schedule->next($definition, $series->get('cursor'), 1)[0] ?? null;
        if ($deadline === null) { $series->set('state', 'Exhausted'); $this->entityManager->saveEntity($series); return false; }
        $identity = $this->schedule->identity($deadline, $definition);
        if ($series->get('boundaryEnd') && strcmp($identity, $series->get('boundaryEnd')) >= 0) {
            $series->set('state', 'Exhausted'); $this->entityManager->saveEntity($series); return false;
        }
        $today = $now->setTimezone(new DateTimeZone($definition->timezone));
        $local = $this->schedule->localDate($deadline, $definition);
        $end = $today->modify('+' . Schedule::WINDOW_DAYS . ' days')->format('Y-m-d');
        // A future cursor proves the next sparse slot is already visible. Do not add another.
        $cursor = $series->get('cursor');
        $upcomingFrom = $definition->dateOnly ? $today->format('Y-m-d') : $now->format('Y-m-d H:i:s');
        if ($local > $end && $cursor && $this->entityManager->getRDBRepository('TaskRecurrenceOccurrence')->where([
            'seriesSegmentId' => $series->getId(), 'originalDeadline>=' => $upcomingFrom,
            'disposition' => 'Materialized', 'OR' => [['taskId!=' => null], ['aliasTaskId!=' => null]],
        ])->findOne()) return false;
        $paused = false;
        foreach ($series->get('pauseIntervals') ?? [] as $interval) {
            $from = $this->eventDate($interval->from, $definition);
            $through = $this->eventDate($interval->through, $definition);
            $slot = $this->schedule->slotDate($deadline, $definition);
            if ($slot >= $from && $slot < $through) $paused = true;
        }
        if ($series->get('boundaryStart') && strcmp($identity, $series->get('boundaryStart')) < 0) $paused = true;
        $existing = $this->entityManager->getRDBRepository('TaskRecurrenceOccurrence')->where(['seriesSegmentId' => $series->getId(), 'recurrenceId' => $identity])->findOne();
        if (!$existing) {
            $occurrence = $this->reserve($series, $identity, $deadline, (int) $series->get('sequence') + 1, null);
            if ($paused) { $occurrence->set('disposition', 'Skipped'); $this->entityManager->saveEntity($occurrence); }
            else $this->materialize($series, $occurrence);
            $series->set('sequence', $occurrence->get('sequence'));
        }
        $series->set('cursor', $deadline);
        $this->entityManager->saveEntity($series);
        return $paused || $local <= $end;
    }

    private function advance(Entity $series): bool
    {
        $locked = $this->entityManager->getRDBRepository('TaskRecurrenceOccurrence')->select('id')->where(['id' => $series->get('headId')])->forUpdate()->findOne();
        $head = $locked ? $this->entityManager->getEntityById('TaskRecurrenceOccurrence', $locked->getId()) : null;
        if (!$head || !$head->get('eventAt') || $head->get('advanced')) return false;
        $definition = $series->get('definition');
        $sequence = (int) $head->get('sequence') + 1;
        $deadline = $this->schedule->completedDeadline($definition, new DateTimeImmutable($head->get('eventAt'), new DateTimeZone('UTC')));
        if (($definition->count !== null && $sequence > $definition->count) ||
            ($definition->until !== null && $this->schedule->localDate($deadline, $definition) > $definition->until)) {
            $series->set('state', 'Exhausted');
        } else {
            $occurrence = $this->reserve($series, 'C:' . sprintf('%010d', $sequence), $deadline, $sequence, $head->getId());
            $this->materialize($series, $occurrence);
            $series->set(['headId' => $occurrence->getId(), 'sequence' => $sequence]);
        }
        $head->set('advanced', true);
        $this->entityManager->saveEntity($head);
        $this->entityManager->saveEntity($series);
        return false;
    }

    private function reserve(Entity $series, string $identity, string $deadline, int $sequence, ?string $predecessorId): Entity
    {
        return $this->entityManager->createEntity('TaskRecurrenceOccurrence', [
            'name' => $identity,
            'seriesSegmentId' => $series->getId(), 'recurrenceId' => $identity, 'originalDeadline' => $deadline,
            'sequence' => $sequence, 'predecessorId' => $predecessorId, 'templateVersion' => $series->get('version'),
            'disposition' => 'Materialized', 'advanced' => false,
        ]);
    }

    private function materialize(Entity $series, Entity $occurrence): void
    {
        $data = clone $series->get('template');
        foreach ((array) $this->template->dates($data, $occurrence->get('originalDeadline'), $series->get('definition')) as $name => $value) $data->$name = $value;
        $data->status = 'Not Started';
        $task = $this->taskRecurrenceMutationContext->run(fn () => $this->services->get('Task')->create($data, CreateParams::create()->withSkipDuplicateCheck())->getEntity());
        $occurrence->set('taskId', $task->getId());
        $this->entityManager->saveEntity($occurrence);
        $this->attach($task, $series, $occurrence->get('recurrenceId'));
    }

    private function attach(Entity $task, Entity $series, string $identity): void
    {
        $task->set(['recurrenceSeriesId' => $series->getId(), 'recurrenceId' => $identity, 'recurrenceOverrides' => []]);
        // Technical attachment only; creation has already run all native field savers and hooks.
        $this->entityManager->saveEntity($task, [SaveOption::SKIP_ALL => true]);
    }

    public function task(string $id, string $action): Entity
    {
        $this->permission($action);
        $task = $this->entityManager->getEntityById('Task', $id) ?? throw new NotFound();
        if (!$this->acl->checkEntity($task, $action)) throw new Forbidden();
        return $task;
    }

    private function permission(string $action): void
    {
        if (!$this->acl->checkScope('Task', $action) || !$this->acl->checkField('Task', 'recurrence', $action === 'read' ? 'read' : 'edit')) throw new Forbidden();
    }

    private function tenant(array $teams): string
    {
        return $this->tenants->resolveUniqueFromTeamIds($teams) ?? throw new BadRequest('recurrence.teams: Select teams belonging to one workspace.');
    }

    private function validateTemplate(object $template, string $tenantId): void
    {
        if ($this->tenant($template->teamsIds ?? []) !== $tenantId) throw new Forbidden('Invalid recurrence workspace.');
        $prototype = $this->entityManager->getNewEntity('Task');
        $prototype->setMultiple($template);
        if (!$this->services->get('Task')->checkAssignment($prototype)) throw new Forbidden('Recurrence assignment is no longer permitted.');
        foreach (['tags' => 'CrmTag', 'chatwootConversations' => 'ChatwootConversation'] as $field => $type) {
            foreach ($template->{$field . 'Ids'} ?? [] as $id) {
                $linked = $this->entityManager->getEntityById($type, $id) ?? throw new Forbidden('A recurrence relationship is unavailable.');
                if (!$this->acl->checkEntityRead($linked)) throw new Forbidden();
                $owner = $linked->get('tenantId');
                if ($type === 'ChatwootConversation') {
                    $account = $this->entityManager->getEntityById('ChatwootAccount', $linked->get('chatwootAccountId'));
                    $owner = $account?->get('tenantId');
                }
                if ($owner !== $tenantId) throw new Forbidden('A recurrence relationship belongs to another workspace.');
            }
        }
        if (!empty($template->parentId)) {
            $parent = $this->entityManager->getEntityById($template->parentType, $template->parentId) ?? throw new Forbidden();
            if (!$this->acl->checkEntityRead($parent)) throw new Forbidden();
            $owner = $parent->hasAttribute('tenantId') ? $parent->get('tenantId') : $this->tenant($parent->get('teamsIds') ?? []);
            if ($owner !== $tenantId) throw new Forbidden('The recurrence parent belongs to another workspace.');
        }
    }

    private function eventDate(string $event, object $definition): DateTimeImmutable
    {
        $date = (new DateTimeImmutable($event, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($definition->timezone));
        return $definition->dateOnly ? new DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC')) : $date;
    }

    private function series(string $id): Entity
    {
        return $this->entityManager->getEntityById('TaskRecurrenceSeries', $id) ?? throw new Conflict('The recurrence series is unavailable.');
    }

    private function lock(string $id): Entity
    {
        $this->entityManager->getRDBRepository('TaskRecurrenceSeries')->select('id')->where(['id' => $id])->forUpdate()->findOne() ?? throw new Conflict('The recurrence series is unavailable.');
        return $this->series($id);
    }

    private function occurrence(Entity $task, bool $lock = true): Entity
    {
        $query = $this->entityManager->getRDBRepository('TaskRecurrenceOccurrence')->select('id')->where(['taskId' => $task->getId()]);
        if ($lock) $query->forUpdate();
        $locked = $query->findOne() ?? throw new Conflict('The occurrence reservation is unavailable.');
        return $this->entityManager->getEntityById('TaskRecurrenceOccurrence', $locked->getId()) ?? throw new Conflict();
    }

    private function pending(Entity $series): bool
    {
        $head = $this->entityManager->getEntityById('TaskRecurrenceOccurrence', $series->get('headId'));
        return $head && $head->get('eventAt') && !$head->get('advanced');
    }
}
