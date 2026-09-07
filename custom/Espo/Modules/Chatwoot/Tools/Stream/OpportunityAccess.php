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
        if ($note->get('parentType') !== 'Opportunity' || $user->isAdmin()) {
            return true;
        }

        $id = $note->get('parentId');
        $parent = $id ? $this->entityManager->getEntityById('Opportunity', $id) : null;

        return $parent &&
            $this->tenants->canActForTenant($user, (string) $parent->get('tenantId')) &&
            $this->aclManager->checkEntityRead($user, $parent) &&
            $this->aclManager->checkEntityStream($user, $parent);
    }

    public function readableOpportunities(User $user): ?Select
    {
        if (!$this->aclManager->checkScope($user, 'Opportunity', 'read') ||
            !$this->aclManager->checkScope($user, 'Opportunity', 'stream')) {
            return null;
        }

        $tenantIds = $this->tenants->resolveTenantIds($user);
        if (!$tenantIds) {
            return null;
        }

        $factory = $this->factory->createWith(SelectBuilderFactory::class, ['user' => $user]);
        $builder = $factory->create()->from('Opportunity')->withStrictAccessControl()
            ->buildQueryBuilder()->select('id')->order([])->where(['tenantId' => $tenantIds]);

        // Strict selection applies read ACL; stream may have a narrower scope.
        $level = $this->aclManager->getLevel($user, 'Opportunity', 'stream');
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
            $this->filters->create('Opportunity', $user, $filter)->apply($builder);
        }

        return $builder->build();
    }

    public function where(User $user): array
    {
        if ($user->isAdmin()) {
            return [];
        }

        $conditions = [['parentType!=' => 'Opportunity'], ['parentType' => null]];
        $parents = $this->readableOpportunities($user);
        if ($parents) {
            $conditions[] = ['parentType' => 'Opportunity', 'parentId=s' => $parents];
        }

        return ['OR' => $conditions];
    }
}
