<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureKnowledgeBaseEditor\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureKnowledgeBaseEditor\Tools\References;
use Espo\Modules\FeatureRecordKnowledge\Tools\Scopes;

class EditorReferences
{
    public function __construct(
        private EntityManager $em,
        private SelectBuilderFactory $select,
        private Acl $acl,
        private Metadata $metadata,
    ) {}

    private function allowedType(string $type): bool
    {
        return (new Scopes($this->metadata))->supports($type) && $this->metadata->get(['scopes', $type, 'entity']) &&
            $this->acl->checkScope($type, 'read') && $this->acl->checkField($type, 'name') &&
            ($type !== 'User' || $this->acl->getPermissionLevel('mention') !== 'no');
    }

    private function allowedRecord(Entity $entity): bool
    {
        return $this->acl->checkEntityRead($entity) &&
            ($entity->getEntityType() !== 'User' ||
            ($entity->get('isActive') && $this->acl->checkUserPermission($entity->getId(), 'mention')));
    }

    public function search(string $query): array
    {
        if (strlen($query) > 240) throw new BadRequest('Query too long.');
        $results = [];
        foreach ((new Scopes($this->metadata))->all() as $type) {
            if (!$this->allowedType($type)) continue;
            try {
                $builder = $this->select->create()->from($type)->withStrictAccessControl()->withTextFilter(trim($query));
                if ($type === 'User') {
                    $builder->withPrimaryFilter('active');
                    if ($this->acl->getPermissionLevel('mention') === 'team') $builder->withBoolFilter('onlyMyTeam');
                }
                $queryBuilder = $builder->buildQueryBuilder()->order('name')->limit(0, 10);
                $sql = $queryBuilder->build();
                foreach ($this->em->getRDBRepository($type)->clone($sql)->find() as $entity) {
                    if (!$this->allowedRecord($entity)) continue;
                    $results[] = ['kind' => 'record', 'entityType' => $type, 'recordId' => $entity->getId(), 'label' => $entity->get('name')];
                }
            } catch (Forbidden) { continue; }
        }
        return $results;
    }

    /** One ACL-filtered select per entity group, never one lookup per chip. */
    public function resolve(array $references): array
    {
        if (count($references) > 200) throw new BadRequest('Too many references.');
        $groups = [];
        $results = [];
        foreach ($references as $reference) {
            $ref = (array) $reference;
            if (!References::valid($ref)) throw new BadRequest('Invalid reference.');
            if ($ref['kind'] !== 'record') continue;
            unset($ref['label']);
            $results[References::url($ref)] = [...$ref, 'available' => false, 'label' => 'Unavailable reference'];
            $groups[$ref['entityType']][] = $ref['recordId'];
        }
        foreach ($groups as $type => $ids) {
            if (!$this->allowedType($type)) continue;
            try {
                $sql = $this->select->create()->from($type)->withStrictAccessControl()->buildQueryBuilder()
                    ->where(['id' => array_values(array_unique($ids))])->build();
                foreach ($this->em->getRDBRepository($type)->clone($sql)->find() as $entity) {
                    if (!$this->allowedRecord($entity)) continue;
                    $ref = ['kind' => 'record', 'entityType' => $type, 'recordId' => $entity->getId()];
                    $results[References::url($ref)] = [...$ref, 'available' => true, 'label' => $entity->get('name')];
                }
            } catch (Forbidden) { continue; }
        }
        return array_values($results);
    }

    public function context(array $ref, array $bindings): ?array
    {
        $key = $ref['key'];
        $id = $key === 'primaryContact' ? ($bindings['primaryContactId'] ?? null) : ($bindings['opportunityId'] ?? null);
        $type = $key === 'primaryContact' ? 'Contact' : 'Opportunity';
        $record = ['kind' => 'record', 'entityType' => $type, 'recordId' => $id];
        if (!References::valid($record)) return null;
        $resolved = $this->resolve([$record])[0] ?? null;
        if (!$resolved || !$resolved['available']) return null;
        if ($key !== 'opportunityOwner') return $resolved;
        if (!$this->acl->checkField('Opportunity', 'assignedUser')) return null;
        $opportunity = $this->em->getEntityById('Opportunity', $id);
        $owner = $opportunity?->get('assignedUserId');
        if (!$owner) return null;
        return $this->resolve([['kind' => 'record', 'entityType' => 'User', 'recordId' => $owner]])[0] ?? null;
    }
}
