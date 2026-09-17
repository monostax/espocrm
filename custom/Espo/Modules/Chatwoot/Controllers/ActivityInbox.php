<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\ServiceContainer;
use Espo\Modules\Chatwoot\Services\ActivityInbox as Inbox;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\Chatwoot\Tools\Activities\Access;
use Espo\ORM\Entity;
use Espo\Tools\Stream\MassNotePreparator;

class ActivityInbox
{
    public function __construct(private Inbox $inbox, private Access $access, private ActivityDiscussion $discussion, private ServiceContainer $services, private MassNotePreparator $preparator) {}

    private function tenant(Request $request): Entity
    {
        return $this->access->workspace((int) $request->getQueryParam('accountId'));
    }

    private function record(Request $request, bool $stream = false): Entity
    {
        return $this->access->record((string) $request->getRouteParam('type'), (string) $request->getRouteParam('id'), $this->tenant($request), $stream);
    }

    private function filters(Request $request): array
    {
        $filters = [];
        foreach (['type', 'assignee_tab', 'assigned_user', 'status', 'search', 'due', 'timeZone', 'read_status', 'view', 'sort', 'order', 'offset', 'maxSize', 'railOnly'] as $key) {
            $value = $request->getQueryParam($key);
            if ($value !== null) {
                if (!is_scalar($value)) throw new BadRequest('Invalid filter.');
                $filters[$key] = $value;
            }
        }
        return $filters;
    }

    public function getActionList(Request $request): object { return $this->inbox->list($this->tenant($request), $this->filters($request)); }
    public function getActionCounts(Request $request): object { return $this->inbox->counts($this->tenant($request), $this->filters($request)); }
    public function getActionMetadata(Request $request): object { $this->tenant($request); return $this->inbox->metadata(); }
    public function getActionOptions(Request $request): object { return $this->inbox->options($this->tenant($request), (string) $request->getQueryParam('entity'), (string) $request->getQueryParam('search')); }

    public function getActionRead(Request $request): object
    {
        $record = $this->record($request);
        $data = $this->inbox->present($this->services->get($record->getEntityType())->read($record->getId())->getEntity());
        $data->conversations = $this->inbox->conversations($record);
        return $data;
    }

    public function postActionCreate(Request $request): object
    {
        $type = $this->access->type((string) $request->getRouteParam('type'));
        $data = clone $request->getParsedBody();
        $this->access->validateTeams($data, $this->tenant($request), true);
        return $this->inbox->present($this->services->get($type)->create($data)->getEntity());
    }

    public function putActionUpdate(Request $request): object
    {
        $record = $this->record($request);
        $data = clone $request->getParsedBody();
        $this->access->validateTeams($data, $this->tenant($request), false);
        return $this->inbox->present($this->services->get($record->getEntityType())->update($record->getId(), $data)->getEntity());
    }

    public function deleteActionDelete(Request $request): bool
    {
        $record = $this->record($request);
        $this->services->get($record->getEntityType())->delete($record->getId());
        return true;
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
        if (!is_string($body->post ?? null) || trim($body->post) === '') throw new BadRequest('Post is required.');
        if (!empty($body->rootId)) $this->discussion->root($parent, $body->rootId);
        $note = $this->services->get('Note')->create((object) [
            'parentType' => $parent->getEntityType(), 'parentId' => $parent->getId(), 'type' => 'Post', 'post' => $body->post,
            'isInternal' => true, 'opportunityThreadRootId' => $body->rootId ?? null,
            'opportunityChatwootAccountId' => (int) $request->getQueryParam('accountId'),
        ])->getEntity();
        return $note->getValueMap();
    }

    public function postActionReadState(Request $request): object
    {
        return (object) $this->discussion->mark($this->record($request, true), $request->getParsedBody());
    }
}
