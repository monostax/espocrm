<?php
declare(strict_types=1);
namespace Espo\Modules\FeatureAiSession\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Select\SearchParams;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Hooks\Note\QueueOpportunityStreamAgent;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\Chatwoot\Services\OpportunityBulkPostAccess;
use Espo\Modules\FeatureAiSession\Services\Access;
use Espo\Modules\FeatureAiSession\Services\Recipient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\Stream\MassNotePreparator;

class AiSessionInbox
{
    public function __construct(
        private EntityManager $em, private User $user, private Acl $acl, private ServiceContainer $records,
        private OpportunityBulkPostAccess $workspaces, private Access $access, private Recipient $recipient,
        private ActivityDiscussion $discussion, private MassNotePreparator $preparator, private QueueOpportunityStreamAgent $queue,
    ) {}

    private function workspace(Request $request): Entity
    {
        $id = filter_var($request->getQueryParam('accountId'), FILTER_VALIDATE_INT);
        if (!$id || $id < 1) throw new BadRequest('A workspace is required.');
        return $this->workspaces->workspace($id);
    }

    private function record(Request $request): Entity
    {
        $account = $this->workspace($request);
        $session = $this->em->getEntityById('AiSession', (string) $request->getRouteParam('id'));
        if (!$session || $session->get('chatwootAccountId') !== $account->getId() ||
            !$this->access->owner($this->user, $session) || !$this->acl->checkEntityRead($session) ||
            !$this->acl->checkEntityStream($session)) throw new NotFound();
        return $session;
    }

    public function getActionList(Request $request): object
    {
        $account = $this->workspace($request);
        $result = $this->records->get('AiSession')->find(SearchParams::fromRaw([
            'maxSize' => 100, 'offset' => max(0, (int) $request->getQueryParam('offset')), 'orderBy' => 'modifiedAt', 'order' => 'desc',
            'where' => [
                ['type' => 'equals', 'attribute' => 'assignedUserId', 'value' => $this->user->getId()],
                ['type' => 'equals', 'attribute' => 'chatwootAccountId', 'value' => $account->getId()],
            ],
        ]));
        $list = array_map(fn ($item) => $this->present($item), [...$result->getCollection()]);
        return (object) ['list' => $list, 'hasMore' => count($list) === 100, 'total' => -1];
    }

    public function getActionAgents(Request $request): object
    {
        $account = $this->workspace($request);
        $session = $this->em->getNewEntity('AiSession');
        $session->set(['assignedUserId' => $this->user->getId(), 'tenantId' => $account->get('tenantId'), 'chatwootAccountId' => $account->getId()]);
        $list = [];
        foreach ($this->em->getRDBRepository('ChatwootAccountUserMembership')->where(['chatwootAccountId' => $account->getId(), 'isAI' => true])->find() as $agent) {
            try { $this->access->agent($this->user, $session, $agent->getId()); }
            catch (\Espo\Core\Exceptions\Forbidden) { continue; }
            $list[] = (object) ['id' => $agent->getId(), 'name' => $agent->get('name'), 'avatarUrl' => $agent->get('avatarUrl')];
        }
        return (object) ['list' => $list];
    }

    public function postActionCreate(Request $request): object
    {
        $account = $this->workspace($request);
        return $this->present($this->records->get('AiSession')->create((object) [
            'name' => 'New chat', 'chatwootAccountId' => $account->getId(),
            'aiAgentMembershipId' => $request->getParsedBody()->aiAgentMembershipId ?? null,
        ])->getEntity());
    }

    public function getActionRead(Request $request): object { return $this->present($this->record($request)); }

    public function putActionUpdate(Request $request): object
    {
        $session = $this->record($request);
        $patch = (object) array_intersect_key((array) $request->getParsedBody(), array_flip(['name', 'aiAgentMembershipId', 'isPinned']));
        return $this->em->getTransactionManager()->run(function () use ($session, $patch): object {
            $this->em->getRDBRepository('AiSession')->where(['id' => $session->getId()])->forUpdate()->findOne();
            return $this->present($this->records->get('AiSession')->update($session->getId(), $patch)->getEntity());
        });
    }

    public function deleteActionDelete(Request $request): bool
    {
        $session = $this->record($request);
        $this->records->get('AiSession')->delete($session->getId());
        return true;
    }

    public function getActionStream(Request $request): object
    {
        if (!$this->acl->checkScope('Note', 'read')) throw new Forbidden();
        $before = $request->getQueryParam('beforeNumber');
        if ($before !== null && (!ctype_digit((string) $before) || (int) $before < 1)) throw new BadRequest('Invalid cursor.');
        $result = $this->discussion->stream($this->record($request), $this->preparator, $request->getQueryParam('rootId'), $before === null ? null : (int) $before);
        foreach ([...$result->list, ...($result->root ? [$result->root] : [])] as $post) {
            foreach ($this->acl->getScopeForbiddenAttributeList('Note') as $attribute) unset($post->$attribute);
            if (!$this->acl->checkField('Note', 'post')) unset($post->post, $post->postEditorState);
            if (!$this->acl->checkField('Note', 'attachments')) unset($post->attachments, $post->attachmentsIds, $post->attachmentsNames, $post->attachmentsTypes);
        }
        return $result;
    }

    public function postActionRecipient(Request $request): object
    {
        $agent = $this->recipient->resolve($this->user, $this->record($request), (string) ($request->getParsedBody()->post ?? ''));
        return (object) ['id' => $agent->getId(), 'name' => $agent->get('name')];
    }

    public function postActionPost(Request $request): object
    {
        $session = $this->record($request);
        $body = $request->getParsedBody();
        if (!$this->acl->checkScope('Note', 'read') || !$this->acl->checkField('Note', 'post')) throw new Forbidden();
        $fields = ['post', ...(!empty($body->postEditorState) ? ['postEditorState'] : []), ...(!empty($body->attachmentsIds) ? ['attachments'] : [])];
        foreach ($fields as $field) {
            if (!$this->acl->checkField('Note', $field, 'edit')) throw new Forbidden('No permission to compose this post.');
        }
        if (!is_array($body->attachmentsIds ?? []) || !is_string($body->post ?? null) ||
            (trim($body->post) === '' && empty($body->attachmentsIds))) throw new BadRequest('A post or attachment is required.');
        return $this->em->getTransactionManager()->run(function () use ($session, $body): object {
            $session = $this->em->getRDBRepository('AiSession')->where(['id' => $session->getId()])->forUpdate()->findOne();
            $agent = $this->recipient->resolve($this->user, $session, $body->post);
            if (!$session->get('titleInitialized')) {
                $title = preg_replace('/\s+/u', ' ', trim(strip_tags(preg_replace('~\[([^\]]+)\]\([^)]*\)~', '$1', $body->post))));
                if ($this->acl->checkEntityEdit($session) && $this->acl->checkField('AiSession', 'name', 'edit')) {
                    $session->set('name', mb_substr($title ?: 'New chat', 0, 100));
                }
                $session->set('titleInitialized', true);
            }
            $this->em->saveEntity($session);
            if (!empty($body->rootId)) $this->discussion->root($session, $body->rootId);
            $account = $this->em->getEntityById('ChatwootAccount', $session->get('chatwootAccountId'));
            $note = $this->records->get('Note')->create((object) [
                'type' => 'Post', 'parentType' => 'AiSession', 'parentId' => $session->getId(), 'isInternal' => true,
                'post' => $body->post, 'postEditorState' => $body->postEditorState ?? null,
                'attachmentsIds' => $body->attachmentsIds ?? [], 'opportunityThreadRootId' => $body->rootId ?? null,
                'opportunityChatwootAccountId' => (int) $account->get('chatwootAccountId'),
            ])->getEntity();
            // Record services strip forbidden output attributes. Reload server-owned
            // execution inputs instead of using that presentation as trusted state.
            $note = $this->em->getRDBRepository('Note')->where(['id' => $note->getId()])->forUpdate()->findOne();
            assert($note instanceof Note);
            $data = $note->getData();
            $data->opportunityAiInitiatorUserId = $this->user->getId();
            $data->opportunityAiMentionTargets = [(object) [
                'aiAgentMembershipId' => $agent->getId(), 'chatwootAccountCrmId' => $account->getId(), 'crmTenantId' => $session->get('tenantId'),
            ]];
            $data->aiSessionSubmission = true;
            $note->setData($data);
            $this->em->saveEntity($note);
            $this->queue->enqueue($note);
            $this->records->get('Note')->prepareEntityForOutput($note);
            return $note->getValueMap();
        });
    }

    public function postActionReadState(Request $request): object
    {
        return (object) $this->discussion->mark($this->record($request), $request->getParsedBody());
    }

    private function present(Entity $session): object
    {
        $canStream = $this->acl->checkEntityStream($session) && $this->acl->checkScope('Note', 'read');
        $canEdit = $this->acl->checkEntityEdit($session);
        $output = clone $session;
        $this->records->get('AiSession')->prepareEntityForOutput($output);
        $data = $output->getValueMap();
        $data->entityType = 'AiSession';
        $data->canStream = $canStream;
        $data->canPost = $canStream && $this->acl->checkScope('Note', 'create') &&
            $this->acl->checkField('Note', 'post') && $this->acl->checkField('Note', 'post', 'edit') &&
            $this->acl->checkField('AiSession', 'aiAgentMembership');
        $data->canEdit = $canEdit && $this->acl->checkField('AiSession', 'name', 'edit');
        $data->canDelete = $this->acl->checkEntityDelete($session);
        $data->canPin = $canEdit && $this->acl->checkField('AiSession', 'isPinned') &&
            $this->acl->checkField('AiSession', 'isPinned', 'edit');
        $data->canChangeAgent = $canEdit && $this->acl->checkField('AiSession', 'aiAgentMembership') &&
            $this->acl->checkField('AiSession', 'aiAgentMembership', 'edit');
        return $data;
    }
}
