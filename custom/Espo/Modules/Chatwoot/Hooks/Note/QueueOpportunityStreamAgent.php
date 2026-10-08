<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\Note;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Job\QueueName;
use Espo\Entities\Note;
use Espo\Modules\Chatwoot\Jobs\DispatchOpportunityStreamAgent;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\Chatwoot\Services\StreamAgentProgress;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

class QueueOpportunityStreamAgent implements AfterSave
{
    public static int $order = 99;

    public function __construct(private EntityManager $entityManager, private StreamAgentProgress $progress) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isNew() || $entity->get('type') !== Note::TYPE_POST ||
            !in_array($entity->get('parentType'), ['Opportunity', ...ActivityDiscussion::PARENT_TYPES], true) ||
            !(getenv('CRM_STREAM_AGENT_WEBHOOK_URL') ?: getenv('CRM_OPPORTUNITY_STREAM_AGENT_WEBHOOK_URL'))) {
            return;
        }
        assert($entity instanceof Note);
        foreach ($entity->getData()->opportunityAiMentionTargets ?? [] as $target) {
            $this->progress->queue($entity, $target);
            // Like PublishOpportunityUpdate: consumed only after commit, discarded on rollback.
            $this->entityManager->createEntity('Job', [
                'name' => DispatchOpportunityStreamAgent::class,
                'className' => DispatchOpportunityStreamAgent::class,
                'queue' => QueueName::Q0,
                'attempts' => 3,
                'data' => (object) [
                    'noteId' => $entity->getId(),
                    'parentType' => $entity->getParentType(),
                    'parentId' => $entity->getParentId(),
                    'postHash' => hash('sha256', $entity->getPost() ?? ''),
                    'aiAgentMembershipId' => $target->aiAgentMembershipId,
                    'chatwootAccountCrmId' => $target->chatwootAccountCrmId,
                    'crmTenantId' => $target->crmTenantId,
                ],
            ]);
        }
    }
}
