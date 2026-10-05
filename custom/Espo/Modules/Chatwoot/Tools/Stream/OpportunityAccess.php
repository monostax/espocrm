<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Stream;

use Espo\Core\AclManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\AccessControl\FilterFactory;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Select;

/** Tenant membership is required in addition to, never instead of, record ACL. */
class OpportunityAccess
{
    public function __construct(
        private EntityManager $entityManager,
        private AclManager $aclManager,
        private UserTenantResolver $tenants,
        private InjectableFactory $factory,
        private FilterFactory $filters,
    ) {}

    public function canReadNote(User $user, Entity $note): bool
    {
        if (!in_array($note->get('parentType'), ['Opportunity', 'Initiative', 'Contact', 'Account'], true) || $user->isAdmin()) {
            return true;
        }

        $id = $note->get('parentId');
        $parent = $id ? $this->entityManager->getEntityById($note->get('parentType'), $id) : null;

        return $parent &&
            $this->tenants->canActForTenant($user, (string) $parent->get('tenantId')) &&
            $this->aclManager->checkEntityRead($user, $parent) &&
            $this->aclManager->checkEntityStream($user, $parent);
    }

    public function readableOpportunities(User $user): ?Select
    {
        return $this->readableParents($user, 'Opportunity');
    }

    public function readableInitiatives(User $user): ?Select
    {
        return $this->readableParents($user, 'Initiative');
    }

    public function readableContacts(User $user): ?Select
    {
        return $this->readableParents($user, 'Contact');
    }

    public function readableParents(User $user, string $type): ?Select
    {
        if (!$this->aclManager->checkScope($user, $type, 'read') ||
            !$this->aclManager->checkScope($user, $type, 'stream')) {
            return null;
        }

        $tenantIds = $this->tenants->resolveTenantIds($user);
        if (!$tenantIds && !$user->isAdmin()) {
            return null;
        }

        $factory = $this->factory->createWith(SelectBuilderFactory::class, ['user' => $user]);
        $builder = $factory->create()->from($type)->withStrictAccessControl()
            ->buildQueryBuilder()->select(['id'])->order([]);
        if (!$user->isAdmin()) {
            $builder->where(['tenantId' => $tenantIds]);
        }

        // Strict selection applies read ACL; stream may have a narrower scope.
        $level = $this->aclManager->getLevel($user, $type, 'stream');
        $filter = match ($level) {
            'own' => $user->isPortal() ? 'portalOnlyOwn' : 'onlyOwn',
            'team' => 'onlyTeam',
            'account' => 'portalOnlyAccount',
            'contact' => 'portalOnlyContact',
            'all', 'yes', true => null,
            default => false,
        };
        if ($filter === false) {
            return null;
        }
        if ($filter) {
            $this->filters->create($type, $user, $filter)->apply($builder);
        }

        return $builder->build();
    }

    public function where(User $user): array
    {
        if ($user->isAdmin()) {
            return [];
        }

        $conditions = [['parentType!=' => ['Opportunity', 'Initiative', 'Contact', 'Account']], ['parentType' => null]];
        foreach (['Opportunity', 'Initiative', 'Contact', 'Account'] as $type) {
            $parents = $this->readableParents($user, $type);
            if ($parents) {
                $conditions[] = ['parentType' => $type, 'parentId=s' => $parents];
            }
        }

        return ['OR' => $conditions];
    }
}
