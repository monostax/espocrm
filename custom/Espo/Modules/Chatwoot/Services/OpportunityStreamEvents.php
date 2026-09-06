<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\SystemUser;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;
use Espo\Tools\Stream\Service as StreamService;

/** Shared immutable event writer. Producers supply facts; clients supply presentation. */
class OpportunityStreamEvents
{
    public const MESSAGE_RECEIVED = 'ChatwootMessageReceived';
    public const ACTIVITY_OVERDUE = 'ActivityOverdue';
    public const EVENT_TYPES = [self::MESSAGE_RECEIVED, self::ACTIVITY_OVERDUE];
    public const TYPES = ['Post', ...self::EVENT_TYPES];

    public function __construct(
        private EntityManager $entityManager,
        private SystemUser $systemUser,
        private StreamService $streamService,
    ) {}

    public function write(string $type, Entity $opportunity, Entity $related, array $data, string $key, array $teams): void
    {
        $this->entityManager->getTransactionManager()->run(function () use (
            $type, $opportunity, $related, $data, $key, $teams,
        ): void {
            // Serialize first insertion, retries, and reconciliation on the same parent.
            $parent = $this->entityManager->getRDBRepository('Opportunity')
                ->where(['id' => $opportunity->getId()])->forUpdate()->findOne();
            if (!$parent || !$parent->get('tenantId') || $parent->get('tenantId') !== $opportunity->get('tenantId')) {
                return;
            }
            $existing = SelectBuilder::create()->from('Note')->withDeleted()
                ->where(['opportunityStreamEventKey' => $key])->build();
            if ($this->entityManager->getRDBRepository('Note')->clone($existing)->findOne()) {
                return;
            }

            // Native Espo streams also enforce the Note's users/teams projection
            // for related records with own/team-level access. The dynamic event
            // predicate remains the authority for actual source-record access.
            $users = array_filter([$related->get('assignedUserId'), $related->get('createdById')]);
            if ($related instanceof CoreEntity) {
                foreach (['assignedUsers', 'collaborators'] as $field) {
                    if ($related->hasLinkMultipleField($field)) {
                        $users = array_merge($users, $related->getLinkMultipleIdList($field));
                    }
                }
            }
            $this->entityManager->createEntity('Note', [
                'type' => $type,
                'parentType' => 'Opportunity',
                'parentId' => $parent->getId(),
                'relatedType' => $related->getEntityType(),
                'relatedId' => $related->getId(),
                'createdById' => $this->systemUser->getId(),
                'isInternal' => true,
                'teamsIds' => $teams,
                'usersIds' => array_values(array_unique($users)),
                'opportunityStreamEventKey' => $key,
                'data' => (object) $data,
            ]);
            $this->streamService->updateStreamUpdatedAt($parent);
        });
    }
}
