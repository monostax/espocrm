<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Security;

use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\AclManager;
use Espo\Core\Field\LinkParent;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Portal\Acl\DefaultAccessChecker as PortalDefaultAccessChecker;
use Espo\Core\Portal\AclManager as PortalAclManager;
use Espo\Core\Select\AccessControl\FilterFactory;
use Espo\Core\Utils\Config;
use Espo\Entities\Attachment;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Classes\Acl\Attachment\AccessChecker as AttachmentChecker;
use Espo\Modules\Chatwoot\Classes\Acl\Note\AccessChecker as NoteChecker;
use Espo\Modules\Chatwoot\Classes\Acl\Opportunity\AccessChecker as OpportunityChecker;
use Espo\Modules\Chatwoot\Classes\AclPortal\Attachment\AccessChecker as PortalAttachmentChecker;
use Espo\Modules\Chatwoot\Classes\AclPortal\Note\AccessChecker as PortalNoteChecker;
use Espo\Modules\Chatwoot\Classes\AclPortal\Opportunity\AccessChecker as PortalOpportunityChecker;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAccess;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAttachmentAccess;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Value\ValueAccessor;
use Espo\ORM\Value\ValueAccessorFactory;
use PHPUnit\Framework\TestCase;

/** Real security wrappers and membership resolution; only storage and stock role decisions are doubled. */
abstract class SecurityTestCase extends TestCase
{
    protected EntityManager $em;
    protected AclManager $acl;
    protected User $user;
    protected UserTenantResolver $tenants;
    protected InjectableFactory $factory;
    protected FilterFactory $filters;
    protected OpportunityAccess $parents;
    protected OpportunityAttachmentAccess $attachments;
    private ?NoteChecker $internalNoteChecker = null;
    private \Closure $createNoteChecker;
    protected PortalNoteChecker $portalNoteChecker;
    protected OpportunityChecker $opportunityChecker;
    protected PortalOpportunityChecker $portalOpportunityChecker;
    protected AttachmentChecker $attachmentChecker;
    protected PortalAttachmentChecker $portalAttachmentChecker;
    protected ScopeData $scope;
    protected array $entities = [];
    protected array $explicitTenants = ['tenant-a'];
    protected array $teamTenants = [];
    protected bool $admin = false;
    protected bool $portal = false;
    protected bool $readAllowed = true;
    protected bool $streamAllowed = true;
    protected bool $editAllowed = true;
    protected bool $deleteAllowed = true;
    protected bool $relatedReadAllowed = true;
    protected bool $fieldAllowed = true;
    protected bool $membershipQueryFails = false;

    protected function setUp(): void
    {
        $this->scope = ScopeData::fromRaw((object) array_fill_keys(['read', 'stream', 'create', 'edit', 'delete'], 'all'));
        $this->user = $this->createMock(User::class);
        $this->user->method('getId')->willReturn('agent');
        $this->user->method('isAdmin')->willReturnCallback(fn () => $this->admin);
        $this->user->method('isPortal')->willReturnCallback(fn () => $this->portal);
        $this->user->method('isRegular')->willReturnCallback(fn () => !$this->portal && !$this->admin);
        $this->user->method('getTeamIdList')->willReturn(['team-a']);
        $this->user->method('getLinkMultipleIdList')->willReturn(['team-a', 'portal-a']);

        $this->em = $this->createMock(EntityManager::class);
        $this->em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $this->entities["$type:$id"] ?? null);
        $relation = $this->createMock(RDBRelation::class);
        $relation->method('find')->willReturnCallback(function () {
            if ($this->membershipQueryFails) {
                throw new \RuntimeException('Membership storage unavailable');
            }
            return new EntityCollection(array_map(fn ($id) => $this->entity('Tenant', ['id' => $id]), $this->explicitTenants));
        });
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('getRelation')->with($this->user, 'tenants')->willReturn($relation);
        $this->em->method('getRDBRepository')->with('User')->willReturn($repository);
        $teamResolver = $this->createMock(TenantResolver::class);
        $teamResolver->method('resolveAllFromTeamIds')->with(['team-a'])->willReturnCallback(fn () => $this->teamTenants);
        $this->tenants = new UserTenantResolver($this->em, $teamResolver);

        $this->acl = $this->createMock(AclManager::class);
        $portalAcl = $this->createMock(PortalAclManager::class);
        foreach ([$this->acl, $portalAcl] as $acl) {
            $acl->method('checkEntityRead')->willReturnCallback(fn ($user, $entity) => match ($entity->getEntityType()) {
                'Opportunity' => ($this->portal ? $this->portalOpportunityChecker : $this->opportunityChecker)
                    ->checkEntityRead($user, $entity, $this->scope),
                'Note' => ($this->portal ? $this->portalNoteChecker : $this->noteChecker())
                    ->checkEntityRead($user, $entity, $this->scope),
                default => $this->relatedReadAllowed,
            });
            $acl->method('checkEntityStream')->willReturnCallback(fn ($user, $entity) => $entity->getEntityType() === 'Opportunity'
                ? ($this->portal ? $this->portalOpportunityChecker : $this->opportunityChecker)
                    ->checkEntityStream($user, $entity, $this->scope)
                : $this->streamAllowed);
            $acl->method('checkEntity')->willReturnCallback(fn ($user, $entity) => $acl->checkEntityRead($user, $entity));
            $acl->method('checkField')->willReturnCallback(fn () => $this->fieldAllowed);
            $acl->method('checkOwnershipOwn')->willReturnCallback(fn ($user, $entity) => $entity->get('createdById') === $user->getId());
        }
        $default = $this->createMock(DefaultAccessChecker::class);
        $portalDefault = $this->createMock(PortalDefaultAccessChecker::class);
        foreach ([$default, $portalDefault] as $checker) {
            $checker->method('checkEntityRead')->willReturnCallback(fn () => $this->readAllowed);
            $checker->method('checkEntityStream')->willReturnCallback(fn () => $this->streamAllowed);
            $checker->method('checkEntityEdit')->willReturnCallback(fn () => $this->editAllowed);
            $checker->method('checkEntityDelete')->willReturnCallback(fn () => $this->deleteAllowed);
            $checker->method('checkEntityCreate')->willReturn(true);
        }
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(fn ($key, $default = null) => $default);
        $this->factory = $this->createMock(InjectableFactory::class);
        $this->filters = $this->createMock(FilterFactory::class);
        $this->parents = new OpportunityAccess($this->em, $this->acl, $this->tenants, $this->factory, $this->filters);
        $this->attachments = new OpportunityAttachmentAccess($this->em, $this->acl, $this->parents, $this->factory);
        $this->opportunityChecker = new OpportunityChecker($default, $this->tenants);
        $this->portalOpportunityChecker = new PortalOpportunityChecker($portalDefault, $this->tenants);
        $baseNote = new \Espo\Classes\Acl\Note\AccessChecker($default, $this->acl, $this->em, $config);
        $this->createNoteChecker = fn () => new NoteChecker($baseNote, $this->acl, $this->em, $this->parents);
        $this->portalNoteChecker = new PortalNoteChecker($portalDefault, $portalAcl, $this->em, $config, $this->parents);
        $this->attachmentChecker = new AttachmentChecker($default, $this->acl, $this->em, $this->attachments);
        $this->portalAttachmentChecker = new PortalAttachmentChecker($portalDefault, $portalAcl, $this->em, $this->attachments);

        foreach (['a', 'b', 'c'] as $tenant) {
            $this->entity('Opportunity', ['id' => "opp-$tenant", 'tenantId' => "tenant-$tenant", 'createdById' => 'agent']);
        }
    }

    protected function noteChecker(): NoteChecker
    {
        return $this->internalNoteChecker ??= ($this->createNoteChecker)();
    }

    protected function entity(string $type, array $values): Entity
    {
        $class = match ($type) {
            'Note' => Note::class,
            'Attachment' => Attachment::class,
            default => BaseEntity::class,
        };
        $defs = ['attributes' => array_fill_keys(array_keys($values), ['type' => 'varchar'])];
        $accessorFactory = $this->createMock(ValueAccessorFactory::class);
        $accessorFactory->method('create')->willReturnCallback(function ($entity) {
            $accessor = $this->createMock(ValueAccessor::class);
            $accessor->method('get')->willReturnCallback(fn ($field) => $entity->get($field . 'Id') && $entity->get($field . 'Type')
                ? LinkParent::create($entity->get($field . 'Type'), $entity->get($field . 'Id')) : null);

            return $accessor;
        });
        $entity = new $class($type, $defs, null, $accessorFactory);
        $entity->set($values);
        $this->entities[$type . ':' . $entity->getId()] = $entity;

        return $entity;
    }

    protected function note(array $values = []): Note
    {
        return $this->entity('Note', $values + [
            'id' => 'note', 'parentType' => 'Opportunity', 'parentId' => 'opp-a', 'type' => 'Post',
            'createdById' => 'agent', 'createdAt' => gmdate('Y-m-d H:i:s'), 'isInternal' => false,
            'targetType' => 'all', 'teamsIds' => ['team-a'], 'portalsIds' => ['portal-a'],
            'relatedType' => null, 'relatedId' => null,
        ]);
    }

    protected function attachment(array $values = []): Attachment
    {
        return $this->entity('Attachment', $values + [
            'id' => 'attachment', 'parentType' => 'Note', 'parentId' => 'note',
            'relatedType' => null, 'relatedId' => null, 'createdById' => 'agent', 'field' => null,
        ]);
    }
}
