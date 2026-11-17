<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAiSession\Hooks\ChatwootAccountUserMembership;

use Espo\Entities\User;
use Espo\Modules\FeatureAiSession\Services\AgentInstructions;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Keeps what an AI agent's instructions said before each change (in the agent's settings, or by the AI for a tenant
 * admin from an AI chat), so a change can be undone. The newest AgentInstructions::KEEP are kept.
 */
class KeepInstructionsRevision
{
    public static int $order = 5;

    public function __construct(private EntityManager $em, private User $user) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        if ($entity->isNew() || !$entity->isAttributeChanged('aiPrompt')) return;
        $this->em->createEntity('AiAgentInstructionsRevision', [
            'membershipId' => $entity->getId(), 'aiPrompt' => $entity->getFetched('aiPrompt'),
            'createdAt' => date('Y-m-d H:i:s'), 'createdById' => $this->user->getId(),
        ]);
        $stale = $this->em->getRDBRepository('AiAgentInstructionsRevision')->select(['id'])
            ->where(['membershipId' => $entity->getId()])->order('number', 'DESC')->limit(AgentInstructions::KEEP, 100)->find();
        foreach ($stale as $revision) $this->em->getRDBRepository('AiAgentInstructionsRevision')->deleteFromDb($revision->getId());
    }
}
