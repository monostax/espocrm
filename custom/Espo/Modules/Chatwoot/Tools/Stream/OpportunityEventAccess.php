<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Stream;

use Espo\Core\AclManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;

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
            $factory = $this->factory->createWith(SelectBuilderFactory::class, ['user' => $user]);
            $records = $factory->create()->from($scope)
                ->withStrictAccessControl()->buildQueryBuilder()->select('id')->order([])->build();
            $conditions[] = ['type' => $type, 'relatedType' => $scope, 'relatedId=s' => $records];
        }
        return $this->conditions[$user->getId()] = count($conditions) === 1 ? $otherTypes : ['OR' => $conditions];
    }
}
