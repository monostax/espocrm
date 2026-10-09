<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\AclManager;
use Espo\Core\Job\QueueName;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Jobs\ExpireStreamAgentReply;
use Espo\ORM\EntityManager;

/** Durable acknowledgement, created in the same transaction as the mention dispatch job. */
class StreamAgentProgress
{
    public function __construct(private EntityManager $em, private AclManager $acl, private \Espo\Modules\FeatureAiSession\Services\Execution $sessions) {}

    public static function isPending(?Note $reply): bool
    {
        return $reply && !$reply->get('deleted') && !$reply->get('opportunityPostDeleted') &&
            in_array($reply->getData()->opportunityStreamAgent->status ?? null, ['queued', 'running'], true);
    }

    public function queue(Note $source, object $target): void
    {
        $membership = $this->em->getEntityById('ChatwootAccountUserMembership', $target->aiAgentMembershipId);
        $identity = $membership ? $this->em->getEntityById('ChatwootUser', $membership->get('chatwootUserId')) : null;
        $user = $identity?->get('assignedUserId') ? $this->em->getEntityById('User', $identity->get('assignedUserId')) : null;
        if (!$user instanceof User || !$user->isActive()) return;

        $reply = $this->em->getNewEntity('Note');
        assert($reply instanceof Note);
        $reply->set([
            'type' => Note::TYPE_POST,
            'parentType' => $source->getParentType(),
            'parentId' => $source->getParentId(),
            'opportunityThreadRootId' => $source->get('opportunityThreadRootId'),
            'opportunityChatwootAccountId' => $source->get('opportunityChatwootAccountId'),
            'opportunityStreamEventKey' => hash('sha256', "opportunity-stream-agent:{$source->getId()}:{$target->aiAgentMembershipId}"),
            'createdById' => $user->getId(),
            'isInternal' => true,
            'post' => 'Waiting to start…',
            'data' => (object) ['opportunityStreamAgent' => (object) [
                'sourceNoteId' => $source->getId(),
                'aiAgentMembershipId' => $target->aiAgentMembershipId,
                'status' => 'queued',
                'queuedAt' => (new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
                'workflowRunId' => null,
            ]],
        ]);
        if ($source->getParentType() === 'AiSession') {
            if (!$this->sessions->authorized($source, $target->aiAgentMembershipId, $user)) return;
        } elseif (!$this->acl->check($user, $reply, 'create')) return;
        // This hook runs as the human author. The ORM replaces the entity's
        // createdById unless the intended author is also supplied as a save option.
        $this->em->saveEntity($reply, [SaveOption::CREATED_BY_ID => $user->getId()]);
        // A real reaction under the AI's linked user; ordinary reaction hooks broadcast it.
        $reaction = ['parentType' => 'Note', 'parentId' => $source->getId(), 'userId' => $user->getId(), 'type' => '👀'];
        if (!$this->em->getRDBRepository('UserReaction')->where($reaction)->findOne()) {
            $this->em->createEntity('UserReaction', $reaction);
        }
        $this->scheduleExpiry($reply);
    }

    public function scheduleExpiry(Note $reply): void
    {
        // Also clears abandoned placeholders if dispatch fails or a worker is killed outright.
        $this->em->createEntity('Job', [
            'name' => ExpireStreamAgentReply::class,
            'className' => ExpireStreamAgentReply::class,
            'queue' => QueueName::Q0,
            'executeTime' => gmdate('Y-m-d H:i:s', time() + ($reply->getParentType() === 'AiSession' &&
                ($reply->getData()->opportunityStreamAgent->status ?? null) === 'queued' ? 86400 : 900)),
            'attempts' => 3,
            'data' => (object) [
                'noteId' => $reply->getId(),
                'sourceNoteId' => $reply->getData()->opportunityStreamAgent->sourceNoteId,
                'workflowRunId' => $reply->getData()->opportunityStreamAgent->workflowRunId ?? null,
            ],
        ]);
    }
}
