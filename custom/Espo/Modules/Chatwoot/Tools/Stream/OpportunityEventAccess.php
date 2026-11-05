<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Stream;

use Espo\Core\AclManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\ORM\Query\Select;

/** The same row-level access predicate for the timeline, previews, and read cursors. */
class OpportunityEventAccess
{
    private array $conditions = [];

    public function __construct(private InjectableFactory $factory, private AclManager $aclManager) {}

    public function where(User $user): array
    {
        if (isset($this->conditions[$user->getId()])) {
            return $this->conditions[$user->getId()];
        }

        $otherTypes = ['type!=' => OpportunityStreamEvents::EVENT_TYPES];
        if (!$user->isRegular() && !$user->isAdmin()) {
            return $otherTypes;
        }

        $conditions = [$otherTypes];
        foreach ([
            'ChatwootConversation' => OpportunityStreamEvents::MESSAGE_RECEIVED,
            'Task' => OpportunityStreamEvents::ACTIVITY_OVERDUE,
            'Meeting' => OpportunityStreamEvents::ACTIVITY_OVERDUE,
            'Call' => OpportunityStreamEvents::ACTIVITY_OVERDUE,
        ] as $scope => $type) {
            if (!$this->aclManager->checkScope($user, $scope, 'read')) {
                continue;
            }
            $conditions[] = ['type' => $type, 'relatedType' => $scope, 'relatedId=s' => $this->records($user, $scope)];
        }
        return $this->conditions[$user->getId()] = count($conditions) === 1 ? $otherTypes : ['OR' => $conditions];
    }

    /** IDs of the conversations whose messages the user may see; null when none. */
    public function conversations(User $user): ?Select
    {
        if ((!$user->isRegular() && !$user->isAdmin()) ||
            !$this->aclManager->checkScope($user, 'ChatwootConversation', 'read')) {
            return null;
        }
        return $this->records($user, 'ChatwootConversation');
    }

    private function records(User $user, string $scope): Select
    {
        $factory = $this->factory->createWith(SelectBuilderFactory::class, ['user' => $user]);
        return $factory->create()->from($scope)
            ->withStrictAccessControl()->buildQueryBuilder()->select('id')->order([])->build();
    }
}
