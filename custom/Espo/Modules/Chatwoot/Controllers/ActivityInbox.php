<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\ServiceContainer;
use Espo\Modules\Chatwoot\Services\ActivityInbox as Inbox;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\Chatwoot\Tools\Activities\Access;
use Espo\Modules\Chatwoot\Tools\Activities\MeetingCalendarLink;
use Espo\Modules\Chatwoot\Tools\Inbox\BulkReadState;
use Espo\Modules\FeatureTaskRecurrence\Services\Recurrence;
use Espo\ORM\Entity;
use Espo\Tools\Stream\MassNotePreparator;

class ActivityInbox
{
    protected const INBOX_TYPES = ['Task'];
    protected const RECORD_TYPES = ['Task', 'Call', 'Meeting'];

    public function __construct(private Inbox $inbox, private Access $access, private ActivityDiscussion $discussion, private ServiceContainer $services, private MassNotePreparator $preparator, private Recurrence $recurrence, private MeetingCalendarLink $meetingCalendarLink) {}

    private function tenant(Request $request): Entity
    {
        return $this->access->workspace((int) $request->getQueryParam('accountId'));
    }

    private function record(Request $request, bool $stream = false): Entity
    {
        return $this->access->record($this->recordType($request), (string) $request->getRouteParam('id'), $this->tenant($request), $stream);
    }

    protected function recordType(Request $request): string
    {
        $type = (string) $request->getRouteParam('type');
        if (!in_array($type, static::RECORD_TYPES, true)) throw new BadRequest('Invalid inbox activity type.');
        return $this->access->type($type);
    }

    protected function filters(Request $request): array
    {
        $filters = [];
        foreach (['type', 'assignee_tab', 'assigned_user', 'status', 'stage', 'tag', 'search', 'due', 'timeZone', 'read_status', 'view', 'group', 'sort', 'order', 'offset', 'maxSize', 'railOnly', 'customerId'] as $key) {
            $value = $request->getQueryParam($key);
            if ($value !== null) {
                if (!is_scalar($value)) throw new BadRequest('Invalid filter.');
                $filters[$key] = $value;
            }
        }
        return $filters;
    }

    public function getActionList(Request $request): object { return $this->inbox->list($this->tenant($request), $this->filters($request), static::INBOX_TYPES); }
    public function getActionCounts(Request $request): object { return $this->inbox->counts($this->tenant($request), $this->filters($request), static::INBOX_TYPES); }
    public function getActionMetadata(Request $request): object { $this->tenant($request); return $this->inbox->metadata(static::INBOX_TYPES, $this->filters($request)); }
    public function getActionOptions(Request $request): object { return $this->inbox->options($this->tenant($request), (string) $request->getQueryParam('entity'), (string) $request->getQueryParam('search')); }

    public function getActionRead(Request $request): object
    {
        $record = $this->record($request);
        $detail = $this->services->get($record->getEntityType())->read($record->getId())->getEntity();
        $data = $this->inbox->present($detail);
        if ($record->getEntityType() === 'Meeting') {
            $data->calendarEventUrl = $this->meetingCalendarLink->resolve($detail);
        }
        $data->conversations = $this->inbox->conversations($record);
        return $data;
    }

    public function postActionCreate(Request $request): object
    {
        $type = $this->recordType($request);
        $data = clone $request->getParsedBody();
        if ($type === 'Appointment') $this->access->validateAppointment($data, $this->tenant($request));
        $this->access->validateTeams($data, $this->tenant($request), true);
        return $this->inbox->present($this->services->get($type)->create($data)->getEntity());
    }

    public function putActionUpdate(Request $request): object
    {
        $record = $this->record($request);
        $data = clone $request->getParsedBody();
        if ($record->getEntityType() === 'Appointment') $this->access->validateAppointment($data, $this->tenant($request));
        $this->access->validateTeams($data, $this->tenant($request), false);
        $params = (new \Espo\Core\Record\UpdateParamsFetcher())->fetch($request);
        return $this->inbox->present($this->services->get($record->getEntityType())->update($record->getId(), $data, $params)->getEntity());
    }

    public function deleteActionDelete(Request $request): bool
    {
        $record = $this->record($request);
        $this->services->get($record->getEntityType())->delete($record->getId());
        return true;
    }

    private function recurrenceTask(Request $request): Entity
    {
        $record = $this->record($request);
        if ($record->getEntityType() !== 'Task') throw new BadRequest('Only Tasks support recurrence.');
        return $record;
    }

    public function postActionRecurrencePreview(Request $request): object
    {
        $this->tenant($request);
        $body = clone $request->getParsedBody();
        if (!empty($body->taskId)) $this->access->record('Task', $body->taskId, $this->tenant($request));
        return $this->recurrence->preview($body);
    }

    public function getActionRecurrenceRead(Request $request): ?object { return $this->recurrence->read($this->recurrenceTask($request)->getId()); }
    public function postActionRecurrenceConvert(Request $request): object
    {
        $task = $this->recurrenceTask($request);
        $body = clone $request->getParsedBody();
        $body->taskVersionNumber = $this->taskVersion($request);
        if (isset($body->patch)) $this->access->validateTeams($body->patch, $this->tenant($request), false);
        return $this->recurrence->convert($task->getId(), $body);
    }
    public function postActionRecurrenceMutate(Request $request): ?object
    {
        $task = $this->recurrenceTask($request);
        $body = clone $request->getParsedBody();
        $body->taskVersionNumber = $this->taskVersion($request);
        if (isset($body->patch)) $this->access->validateTeams($body->patch, $this->tenant($request), false);
        return $this->recurrence->mutate($task->getId(), $body);
    }

    private function taskVersion(Request $request): int
    {
        $version = (new \Espo\Core\Record\UpdateParamsFetcher())->fetch($request)->getVersionNumber();
        if ($version === null) throw new \Espo\Core\Exceptions\Conflict('A Task version precondition is required.');
        return $version;
    }

    public function getActionStream(Request $request): object
    {
        $before = $request->getQueryParam('beforeNumber');
        if ($before !== null && (!ctype_digit((string) $before) || (int) $before < 1)) throw new BadRequest('Invalid cursor.');
        return $this->discussion->stream($this->record($request, true), $this->preparator, $request->getQueryParam('rootId'), $before === null ? null : (int) $before);
    }

    public function postActionPost(Request $request): object
    {
        $parent = $this->record($request, true);
        $body = $request->getParsedBody();
        $attachmentsIds = $body->attachmentsIds ?? [];
        if (!is_array($attachmentsIds)) throw new BadRequest('Invalid attachments.');
        if (!is_string($body->post ?? null) || (trim($body->post) === '' && !$attachmentsIds)) throw new BadRequest('Post is required.');
        if (!empty($body->rootId)) $this->discussion->root($parent, $body->rootId);
        $note = $this->services->get('Note')->create((object) [
            'parentType' => $parent->getEntityType(), 'parentId' => $parent->getId(), 'type' => 'Post', 'post' => $body->post,
            'isInternal' => true, 'opportunityThreadRootId' => $body->rootId ?? null,
            'opportunityChatwootAccountId' => (int) $request->getQueryParam('accountId'),
            'attachmentsIds' => $attachmentsIds,
            'postEditorState' => $body->postEditorState ?? null,
        ])->getEntity();
        return $note->getValueMap();
    }

    public function postActionReadState(Request $request): object
    {
        return (object) $this->discussion->mark($this->record($request, true), $request->getParsedBody());
    }

    public function postActionBulkReadState(Request $request): object
    {
        $tenant = $this->tenant($request);
        $body = $request->getParsedBody();
        return BulkReadState::run($body, function (string $key) use ($tenant, $body): array {
            $parts = explode(':', $key);
            if (count($parts) !== 2) throw new BadRequest('Expected an activity type and ID.');
            if (!in_array($parts[0], static::RECORD_TYPES, true)) throw new BadRequest('Invalid inbox activity type.');
            return $this->discussion->mark(
                $this->access->record($parts[0], $parts[1], $tenant, true),
                (object) ['unread' => $body->unread, 'allThreads' => !$body->unread],
            );
        });
    }
}
