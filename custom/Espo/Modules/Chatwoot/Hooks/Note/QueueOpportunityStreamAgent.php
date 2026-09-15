<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\Note;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Job\QueueName;
use Espo\Entities\Note;
use Espo\Modules\Chatwoot\Jobs\DispatchOpportunityStreamAgent;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

class QueueOpportunityStreamAgent implements AfterSave
{
    public static int $order = 99;

    public function __construct(private EntityManager $entityManager) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isNew() || $entity->get('type') !== Note::TYPE_POST ||
            $entity->get('parentType') !== 'Opportunity' ||
            !getenv('CRM_OPPORTUNITY_STREAM_AGENT_WEBHOOK_URL')) {
            return;
        }
        assert($entity instanceof Note);
        foreach ($entity->getData()->opportunityAiMentionTargets ?? [] as $target) {
            // Like PublishOpportunityUpdate: consumed only after commit, discarded on rollback.
            $this->entityManager->createEntity('Job', [
                'name' => DispatchOpportunityStreamAgent::class,
                'className' => DispatchOpportunityStreamAgent::class,
                'queue' => QueueName::Q0,
                'attempts' => 3,
                'data' => (object) [
                    'noteId' => $entity->getId(),
                    'opportunityId' => $entity->getParentId(),
                    'postHash' => hash('sha256', $entity->getPost() ?? ''),
                    'aiAgentMembershipId' => $target->aiAgentMembershipId,
                    'chatwootAccountCrmId' => $target->chatwootAccountCrmId,
                    'crmTenantId' => $target->crmTenantId,
                ],
            ]);
        }
    }
}
