<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityThreadState;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAccess;
use Espo\ORM\EntityManager;
use Espo\Tools\Stream\MassNotePreparator;

class OpportunityThread
{
    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private Acl $acl,
        private OpportunityAccess $access,
        private OpportunityThreadState $states,
        private MassNotePreparator $preparator,
    ) {}

    public function getActionThread(Request $request): object
    {
        $root = $this->root($request);
        $before = $request->getQueryParam('beforeNumber');
        if ($before !== null && (!ctype_digit((string) $before) || (int) $before < 1)) {
            throw new BadRequest('Invalid reply cursor.');
        }
        $where = ['parentType' => 'Opportunity', 'parentId' => $root->getParentId(),
            'opportunityThreadRootId' => $root->getId(), 'type' => Note::TYPE_POST];
        if ($before !== null) {
            $where['number<'] = (int) $before;
        }
        $notes = [];
        foreach ($this->entityManager->getRDBRepository('Note')->where($where)->order('number', 'DESC')->limit(0, 51)->find() as $note) {
            $notes[] = $note;
        }
        $hasMore = count($notes) > 50;
        $notes = array_slice($notes, 0, 50);
        $all = [$root, ...$notes];
        foreach ($all as $note) {
            $note->loadAdditionalFields();
        }
        $this->preparator->prepare($all);
        return (object) [
            'root' => $root->getValueMap(),
            'list' => array_map(fn (Note $note) => $note->getValueMap(), array_reverse($notes)),
            'hasMore' => $hasMore,
            'readState' => (object) $this->states->readState($root->getId()),
        ];
    }

    public function postActionMarkRead(Request $request): object
    {
        $root = $this->root($request);
        $body = $request->getParsedBody();
        $replyId = $body->lastReplyId ?? null;
        $version = $body->expectedVersion ?? null;
        if (!is_string($replyId) || !is_int($version) || $version < 0) {
            throw new BadRequest('A rendered reply ID and read-state version are required.');
        }
        $reply = $this->entityManager->getRDBRepository('Note')->where([
            'id' => $replyId, 'parentType' => 'Opportunity', 'parentId' => $root->getParentId(),
            'opportunityThreadRootId' => $root->getId(),
        ])->findOne();
        if (!$reply) {
            throw new BadRequest('The read cutoff must belong to this thread.');
        }
        return (object) $this->states->markRead($root, (int) $reply->get('number'), $version);
    }

    private function root(Request $request): Note
    {
        $root = $this->entityManager->getEntityById('Note', (string) $request->getRouteParam('rootId'));
        if (!$root instanceof Note || $root->getParentType() !== 'Opportunity' ||
            $root->getParentId() !== $request->getRouteParam('id') ||
            $root->getType() !== Note::TYPE_POST || $root->get('opportunityThreadRootId')) {
            throw new NotFound();
        }
        if ((!$this->user->isRegular() && !$this->user->isAdmin()) ||
            !$this->access->canReadNote($this->user, $root) || !$this->acl->checkEntityRead($root)) {
            throw new Forbidden();
        }
        return $root;
    }
}
