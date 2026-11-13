<?php
declare(strict_types=1);
namespace Espo\Modules\FeatureAiSession\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\ServiceContainer;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Hooks\Note\QueueOpportunityStreamAgent;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * One human turn in a session, as the current user: the post, its execution grant (the exact agent and model of this
 * turn) and the queued run. The app's composer and WhatsApp (as the verified person, through ActorScope) both submit
 * here, so the same ACL, ownership and agent checks apply to either.
 */
class Submissions
{
    public function __construct(
        private EntityManager $em, private User $user, private Acl $acl, private ServiceContainer $records,
        private Recipient $recipient, private ActivityDiscussion $discussion, private QueueOpportunityStreamAgent $queue,
    ) {}

    /** @param array<string, scalar> $origin server-owned facts about where the turn came from (e.g. its channel) */
    public function post(Entity $session, object $body, array $origin = []): object
    {
        if (!$this->acl->checkScope('Note', 'read') || !$this->acl->checkField('Note', 'post')) throw new Forbidden();
        $fields = ['post', ...(!empty($body->postEditorState) ? ['postEditorState'] : []), ...(!empty($body->attachmentsIds) ? ['attachments'] : [])];
        foreach ($fields as $field) {
            if (!$this->acl->checkField('Note', $field, 'edit')) throw new Forbidden('No permission to compose this post.');
        }
        if (!is_array($body->attachmentsIds ?? []) || !is_string($body->post ?? null) ||
            (trim($body->post) === '' && empty($body->attachmentsIds))) throw new BadRequest('A post or attachment is required.');
        return $this->em->getTransactionManager()->run(function () use ($session, $body, $origin): object {
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
            // The model chosen when this turn was sent; a later switch never reroutes it.
            if (is_string($session->get('aiModel')) && $session->get('aiModel') !== '') $data->aiSessionModel = $session->get('aiModel');
            foreach ($origin as $key => $value) $data->$key = $value;
            $note->setData($data);
            $this->em->saveEntity($note);
            $this->queue->enqueue($note);
            $this->records->get('Note')->prepareEntityForOutput($note);
            return $note->getValueMap();
        });
    }
}
