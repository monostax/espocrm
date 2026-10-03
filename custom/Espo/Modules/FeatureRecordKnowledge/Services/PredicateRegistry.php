<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;
use Espo\Modules\FeatureRecordKnowledge\Tools\Predicates;
use Espo\Modules\FeatureRecordKnowledge\Tools\QualifierSchema;

class PredicateRegistry
{
    private array $cache = [];
    public function __construct(private EntityManager $em, private Metadata $metadata, private Tenancy $tenancy) {}

    public function builtins(): array
    {
        $list = [];
        foreach ($this->metadata->get('app.recordKnowledgePredicates', []) as $code => $def) {
            $list['builtin:' . $code] = [...$def, 'key' => 'builtin:' . $code, 'code' => $code, 'builtin' => true, 'active' => true,
                'qualifierSchema' => QualifierSchema::shorthand($def['qualifiers'] ?? [])];
        }
        return $list;
    }

    /** Raw definitions are cached only by tenant/version. Authorization is never cached. */
    public function schema(?string $tenantId, bool $activeOnly = true): array
    {
        $list = $this->builtins();
        if ($tenantId === null) return $list;
        $this->tenancy->assert($tenantId);
        $version = (int) $this->em->getEntityById('Tenant', $tenantId)->get('knowledgePredicateVersion');
        if (($this->cache[$tenantId]['version'] ?? null) !== $version) {
            $definitions = [];
            foreach ($this->em->getRDBRepository('RecordPredicate')->where(['tenantId' => $tenantId])->find() as $entity) {
                $def = $this->data($entity);
                $definitions[$def['key']] = $def;
            }
            $this->cache[$tenantId] = ['version' => $version, 'definitions' => $definitions];
        }
        foreach ($this->cache[$tenantId]['definitions'] as $key => $def) if (!$activeOnly || $def['active']) $list[$key] = $def;
        return $list;
    }

    public function resolve(string $input, string $tenantId, bool $active = true): array
    {
        $list = $this->schema($tenantId, false);
        $matches = [];
        foreach ($list as $key => $def) {
            if ($input === $key || $input === $def['code'] || $input === $def['label'] || in_array($input, $def['aliases'], true)) $matches[$key] = $def;
        }
        if (count($matches) !== 1) throw new BadRequest('Unknown, ambiguous or foreign-tenant predicate.');
        $definition = reset($matches);
        if ($active && !$definition['active']) throw new BadRequest('Predicate is inactive.');
        return $definition;
    }

    public function validate(string $input, string $tenantId, string $subjectType, string $objectType, mixed $qualifiers): array
    {
        $definition = $this->resolve($input, $tenantId);
        return [$definition['key'], Predicates::values($definition, $subjectType, $objectType, $qualifiers)];
    }

    public function data(Entity $entity): array
    {
        $referenced = (bool) $this->em->getRDBRepository('RecordRelation')->where([
            'tenantId' => $entity->get('tenantId'), 'predicate' => 'tenant:' . $entity->get('tenantId') . ':' . $entity->get('code'),
        ])->findOne();
        return [
            'id' => $entity->getId(), 'key' => 'tenant:' . $entity->get('tenantId') . ':' . $entity->get('code'),
            'code' => $entity->get('code'), 'tenantId' => $entity->get('tenantId'), 'label' => $entity->get('name'),
            'inverse' => $entity->get('inverseLabel'), 'description' => $entity->get('description'),
            'subjects' => $entity->get('subjectTypes'), 'objects' => $entity->get('objectTypes'),
            'qualifierSchema' => $entity->get('qualifierSchema'), 'aliases' => $entity->get('aliases') ?: [],
            'active' => (bool) $entity->get('isActive'), 'builtin' => false, 'referenced' => $referenced, 'versionNumber' => $entity->get('versionNumber'),
        ];
    }

    public function lock(string $tenantId): Entity
    {
        $row = $this->em->getRDBRepository('Tenant')->select(['id', 'knowledgePredicateVersion'])->where(['id' => $tenantId])->forUpdate()->findOne();
        if (!$row) throw new BadRequest('Tenant no longer exists.');
        // A writer/first-use check must never use a pre-transaction cache snapshot.
        unset($this->cache[$tenantId]);
        return $row;
    }

    public function changed(string $tenantId): void
    {
        $row = $this->lock($tenantId);
        $query = $this->em->getQueryBuilder()->update()->in('Tenant')->set([
            'knowledgePredicateVersion' => (int) $row->get('knowledgePredicateVersion') + 1,
        ])->where(['id' => $tenantId])->build();
        $this->em->getQueryExecutor()->execute($query);
        unset($this->cache[$tenantId]);
    }

    public function reserved(string $tenantId, ?string $exceptId): array
    {
        $definitions = $this->builtins();
        $query = SelectBuilder::create()->from('RecordPredicate')->withDeleted()->where(['tenantId' => $tenantId]);
        if ($exceptId) $query->where(['id!=' => $exceptId]);
        foreach ($this->em->getRDBRepository('RecordPredicate')->clone($query->build())->find() as $entity) $definitions[] = $this->data($entity);
        $reserved = [];
        foreach ($definitions as $def) foreach ([$def['code'], $def['label'], ...$def['aliases']] as $name) $reserved[mb_strtolower($name)] = true;
        return $reserved;
    }

    public function used(Entity $entity): bool
    {
        return (bool) $this->em->getRDBRepository('RecordRelation')->select(['id'])->where([
            'tenantId' => $entity->get('tenantId'), 'predicate' => 'tenant:' . $entity->get('tenantId') . ':' . $entity->get('code'),
        ])->forUpdate()->findOne();
    }
}
