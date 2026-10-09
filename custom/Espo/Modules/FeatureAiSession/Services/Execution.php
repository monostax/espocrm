<?php
declare(strict_types=1);
namespace Espo\Modules\FeatureAiSession\Services;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\ORM\EntityManager;

/** An execution grant is tied to the saved human submission, never the session's current agent. */
class Execution
{
    public function __construct(private EntityManager $em, private Access $access, private AclManager $acl, private \Espo\Core\FileStorage\Manager $files) {}

    public function authorized(Note $source, string $membershipId, User $actor): bool
    {
        $data = $source->getData();
        if ($source->getParentType() !== 'AiSession' || !($data->aiSessionSubmission ?? false) ||
            $source->get('opportunityPostDeleted') || $source->getType() !== 'Post' ||
            ($data->opportunityAiInitiatorUserId ?? null) !== $source->getCreatedById()) return false;
        $session = $this->em->getEntityById('AiSession', $source->getParentId());
        $owner = $this->em->getEntityById('User', $source->getCreatedById());
        $targets = $data->opportunityAiMentionTargets ?? [];
        if (!$session || !$owner instanceof User || count($targets) !== 1 ||
            $targets[0]->aiAgentMembershipId !== $membershipId ||
            $targets[0]->chatwootAccountCrmId !== $session->get('chatwootAccountId') ||
            $targets[0]->crmTenantId !== $session->get('tenantId') ||
            !$this->acl->checkEntityRead($owner, $session) || !$this->acl->checkEntityStream($owner, $session) ||
            !$this->acl->checkEntityRead($owner, $source) || !$this->acl->checkScope($owner, 'Note', 'create') ||
            !$this->acl->checkField($owner, 'Note', 'post')) return false;
        try { $membership = $this->access->agent($owner, $session, $membershipId); }
        catch (Forbidden) { return false; }
        $identity = $this->em->getEntityById('ChatwootUser', $membership->get('chatwootUserId'));
        return $actor->isActive() && $identity?->get('assignedUserId') === $actor->getId();
    }

    public function generated(Note $reply): bool
    {
        $data = $reply->getData()->opportunityStreamAgent ?? null;
        if (!$data) return false;
        $source = $this->em->getEntityById('Note', (string) ($data->sourceNoteId ?? ''));
        $actor = $this->em->getEntityById('User', (string) $reply->getCreatedById());
        return $source instanceof Note && $actor instanceof User &&
            $source->getParentType() === $reply->getParentType() && $source->getParentId() === $reply->getParentId() &&
            $this->authorized($source, (string) ($data->aiAgentMembershipId ?? ''), $actor);
    }

    public function context(Note $source): array
    {
        $session = $this->em->getEntityById('AiSession', $source->getParentId());
        $owner = $this->em->getEntityById('User', $session->get('assignedUserId'));
        assert($owner instanceof User);
        $posts = [];
        foreach ($this->em->getRDBRepository('Note')->where([
            'parentType' => 'AiSession', 'parentId' => $source->getParentId(), 'type' => 'Post',
            'opportunityPostDeleted' => false, 'number<' => $source->get('number'),
        ])->order('number', 'DESC')->limit(0, 40)->find() as $post) {
            assert($post instanceof Note);
            if (!$this->acl->checkEntityRead($owner, $post)) continue;
            if (isset($post->getData()->opportunityStreamAgent) &&
                ($post->getData()->opportunityStreamAgent->status ?? null) !== 'completed') continue;
            $posts[] = $this->post($post);
        }
        return ['sessionRecord' => (object) ['id' => $session->getId(),
            'name' => $this->acl->checkField($owner, 'AiSession', 'name') ? $session->get('name') : null], 'sessionPosts' => $posts];
    }

    public function post(Note $post): object
    {
        $author = $this->em->getEntityById('User', (string) $post->getCreatedById());
        $attachments = [];
        $remaining = 16000;
        $session = $this->em->getEntityById('AiSession', $post->getParentId());
        $owner = $session ? $this->em->getEntityById('User', $session->get('assignedUserId')) : null;
        foreach ($this->em->getRDBRepository('Note')->getRelation($post, 'attachments')->find() as $attachment) {
            if (!$owner instanceof User || !$this->acl->checkField($owner, 'Note', 'attachments') || !$this->acl->checkEntityRead($owner, $attachment)) continue;
            $item = ['id' => $attachment->getId(), 'name' => $attachment->get('name'), 'type' => $attachment->get('type'), 'contentAvailable' => false];
            // First-release model context supports bounded UTF-8 text attachments. Other
            // formats remain downloadable/renderable, but are explicitly not model content.
            if ($remaining > 0 && in_array($attachment->get('type'), ['text/plain', 'text/markdown', 'text/csv', 'application/json'], true)) {
                assert($attachment instanceof \Espo\Entities\Attachment);
                $text = $this->files->getStream($attachment)->read($remaining);
                if (mb_check_encoding($text, 'UTF-8')) {
                    $item['text'] = $text;
                    $item['contentAvailable'] = true;
                    $item['truncated'] = (int) $attachment->get('size') > strlen($text);
                    $remaining -= strlen($text);
                }
            }
            foreach (['name', 'type'] as $field) {
                if (!$this->acl->checkField($owner, 'Attachment', $field)) $item[$field] = null;
            }
            $attachments[] = $item;
            if (count($attachments) >= 20) break;
        }
        return (object) ['id' => $post->getId(), 'post' => $post->getPost() ?? '', 'number' => $post->get('number'),
            'createdAt' => $owner instanceof User && $this->acl->checkField($owner, 'Note', 'createdAt') ? $post->get('createdAt') : '',
            'createdById' => $owner instanceof User && $this->acl->checkField($owner, 'Note', 'createdBy') ? $post->getCreatedById() : null,
            'createdByName' => $owner instanceof User && $this->acl->checkField($owner, 'Note', 'createdBy') ? $author?->get('name') : null,
            'attachments' => $attachments];
    }
}
