<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\ServiceContainer;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\Chatwoot\Services\ContactInbox as Inbox;
use Espo\Tools\Stream\MassNotePreparator;

class ContactInbox
{
    public function __construct(
        private Inbox $inbox,
        private ActivityDiscussion $discussion,
        private ServiceContainer $records,
        private MassNotePreparator $preparator,
    ) {}

    public function getActionList(Request $request): object { return $this->inbox->list($request); }
    public function getActionCounts(Request $request): object { return $this->inbox->counts($request); }
    public function getActionGroups(Request $request): object { return $this->inbox->groups($request); }
    public function getActionMetadata(Request $request): object { return $this->inbox->metadata($request); }
    public function getActionOptions(Request $request): object { return $this->inbox->options($request); }
    public function putActionUpdate(Request $request): object { return $this->inbox->update($request); }
    public function deleteActionDelete(Request $request): bool { return $this->inbox->delete($request); }

    public function getActionRead(Request $request): object
    {
        $record = $this->inbox->record($request);
        $data = $this->inbox->present($this->records->get('Contact')->read($record->getId())->getEntity());
        $data->readState = $data->canStream ? $this->discussion->states('Contact', [$record->getId()])[$record->getId()] : null;
        return $data;
    }

    public function getActionStream(Request $request): object
    {
        $before = $request->getQueryParam('beforeNumber');
        if ($before !== null && (!ctype_digit($before) || (int) $before < 1)) throw new BadRequest('Invalid cursor.');
        return $this->discussion->stream(
            $this->inbox->record($request, true), $this->preparator, $request->getQueryParam('rootId'),
            $before === null ? null : (int) $before,
        );
    }

    public function postActionPost(Request $request): object
    {
        $parent = $this->inbox->record($request, true);
        $body = $request->getParsedBody();
        $attachments = $body->attachmentsIds ?? [];
        if (!is_array($attachments)) throw new BadRequest('Invalid attachments.');
        if (!is_string($body->post ?? null) || (trim($body->post) === '' && !$attachments)) throw new BadRequest('Post is required.');
        if (!empty($body->rootId)) $this->discussion->root($parent, $body->rootId);
        return $this->records->get('Note')->create((object) [
            'parentType' => 'Contact', 'parentId' => $parent->getId(), 'type' => 'Post', 'post' => $body->post,
            'isInternal' => true, 'opportunityThreadRootId' => $body->rootId ?? null,
            'opportunityChatwootAccountId' => (int) $request->getQueryParam('accountId'),
            'attachmentsIds' => $attachments, 'postEditorState' => $body->postEditorState ?? null,
        ])->getEntity()->getValueMap();
    }

    public function postActionReadState(Request $request): object
    {
        return (object) $this->discussion->mark($this->inbox->record($request, true), $request->getParsedBody());
    }
}
