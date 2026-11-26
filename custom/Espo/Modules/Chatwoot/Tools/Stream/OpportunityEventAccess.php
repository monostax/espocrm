<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Stream;

use Espo\Core\AclManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Query\Part\Condition;
use Espo\ORM\Query\Select;

/** The same row-level access predicate for the timeline, previews, and read cursors. */
class OpportunityEventAccess
{
    private array $conditions = [];
    private array $messageLinks = [];

    public function __construct(
        private InjectableFactory $factory,
        private AclManager $aclManager,
        private UserTenantResolver $tenants,
    ) {}

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

    /** A viewer must also be able to see the relationship that delivered the message. */
    public function messageLinkWhere(User $user, string $noteAlias = 'note', ?string $parentType = null): array
    {
        $key = implode(':', [$user->getId(), $noteAlias, $parentType]);
        if (isset($this->messageLinks[$key])) return $this->messageLinks[$key];
        $types = $parentType ? [$parentType] : array_keys(OpportunityStreamEvents::MESSAGE_ROLLOUT);
        $conditions = [['type!=' => OpportunityStreamEvents::MESSAGE_RECEIVED], ['parentType!=' => $types]];
        foreach ($types as $type) {
            if (!$this->aclManager->checkScope($user, $type, 'read')) continue;
            $pairs = [];
            foreach (MuralConversationLinks::paths($type) as $path => $link) {
                $access = $this->linkAccess($user, $type, $path);
                if ($access === null) continue;
                $pair = $link->where($access)->where($this->viewerTenant($user, $type))->where([
                    'muralParent.id:' => "$noteAlias.parentId", 'muralConversation.id:' => "$noteAlias.relatedId",
                ])->build();
                $pairs[] = Condition::exists($pair)->getRaw();
            }
            if ($pairs) $conditions[] = ['parentType' => $type, 'OR' => $pairs];
        }
        return $this->messageLinks[$key] = ['OR' => $conditions];
    }

    /**
     * A tenantless parent (Task) cannot bind the conversation's workspace to its own tenant, so it is
     * bound to the viewer's tenants, as the Tenant filter does for records.
     */
    private function viewerTenant(User $user, string $type): array
    {
        if (MuralConversationLinks::tenanted($type) || $user->isEffectiveAdmin()) return [];
        return ['muralWorkspace.tenantId' => $this->tenants->resolveTenantIds($user)];
    }

    /** null denies this path; row predicates are applied inside its EXISTS. */
    private function linkAccess(User $user, string $type, string $path): ?array
    {
        if ($path === 'manual' || $path === 'manualReverse') {
            return $this->aclManager->checkScope($user, 'RecordPredicate', 'read') &&
                $this->aclManager->checkField($user, $type, 'name') &&
                $this->aclManager->checkField($user, 'ChatwootConversation', 'name') ? [] : null;
        }
        if ($path === MuralConversationLinks::TASK_CONVERSATIONS) {
            // The note's parent is the task and its related record the conversation; both are checked elsewhere.
            return $this->readLink($user, 'Task', $path) && $this->readLink($user, 'ChatwootConversation', 'tasks') ? [] : null;
        }
        if (!$this->aclManager->checkScope($user, $path, 'read')) return null;
        if ($path === 'Contact') {
            return $this->readLink($user, 'Account', 'contacts') && $this->readLink($user, 'Contact', 'chatwootConversations')
                ? ['muralContact.id=s' => $this->records($user, $path)] : null;
        }
        if ($path === 'InitiativeRelation') {
            return $this->readLink($user, $type, 'relations') && $this->readLink($user, $path, 'parent') &&
                $this->readLink($user, $path, 'initiative') ? ['muralLink.id=s' => $this->records($user, $path)] : null;
        }
        $link = strtolower($path) . 's';
        return $this->readLink($user, $path, 'parent') && $this->readLink($user, 'ChatwootConversation', $link)
            ? ['muralActivity.id=s' => $this->records($user, $path)] : null;
    }

    private function readLink(User $user, string $type, string $link): bool
    {
        return $this->aclManager->checkLink($user, $type, $link) && $this->aclManager->checkField($user, $type, $link);
    }

    private function records(User $user, string $scope): Select
    {
        $factory = $this->factory->createWith(SelectBuilderFactory::class, ['user' => $user]);
        return $factory->create()->from($scope)
            ->withStrictAccessControl()->buildQueryBuilder()->select(['id'])->order([])->build();
    }
}
