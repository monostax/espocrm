<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureRecordKnowledge\Hooks\RecordPredicate;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureRecordKnowledge\Services\Tenancy;
use Espo\Modules\FeatureRecordKnowledge\Services\PredicateRegistry;
use Espo\Modules\FeatureRecordKnowledge\Tools\QualifierSchema;
use Espo\Modules\FeatureRecordKnowledge\Tools\Scopes;

class Validate
{
    public static int $order = 5;
    public function __construct(private EntityManager $em, private Tenancy $tenancy, private PredicateRegistry $registry, private Scopes $scopes) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew() && ($entity->isAttributeChanged('tenantId') || $entity->isAttributeChanged('code'))) throw new Conflict('Predicate tenant and code are immutable.');
        $tenantId = $this->tenancy->select($entity->get('tenantId'), true);
        $this->registry->lock($tenantId);
        $entity->set('tenantId', $tenantId);
        $code = $entity->get('code');
        if (!is_string($code) || !preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $code)) throw new BadRequest('Invalid predicate code.');
        foreach (['name', 'inverseLabel'] as $field) {
            $value = $entity->get($field);
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 100) throw new BadRequest('Forward/inverse labels are required (max 100 characters).');
            $entity->set($field, trim($value));
        }
        foreach (['subjectTypes', 'objectTypes'] as $field) {
            $types = $entity->get($field);
            if (!is_array($types) || !array_is_list($types) || !$types || count($types) > 100) throw new BadRequest('Select allowed endpoint types.');
            foreach ($types as $type) if (!is_string($type) || !$this->scopes->supports($type)) throw new BadRequest('Unsupported predicate endpoint type.');
            $types = array_values(array_unique($types)); sort($types); $entity->set($field, $types);
        }
        $entity->set('qualifierSchema', QualifierSchema::definition($entity->get('qualifierSchema')));
        $aliases = $entity->get('aliases') ?: [];
        if (!is_array($aliases) || !array_is_list($aliases) || count($aliases) > 20) throw new BadRequest('Invalid aliases.');
        foreach ($aliases as $alias) if (!is_string($alias) || !preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $alias)) throw new BadRequest('Aliases use canonical code syntax.');
        $baseNames = array_unique(array_map(mb_strtolower(...), [$code, $entity->get('name')]));
        if (count($aliases) !== count(array_unique($aliases)) || array_intersect($baseNames, $aliases)) throw new BadRequest('Duplicate alias.');
        $names = [...$baseNames, ...$aliases];
        $reserved = $this->registry->reserved($tenantId, $entity->isNew() ? null : $entity->getId());
        foreach ($names as $name) if (isset($reserved[$name])) throw new Conflict('Predicate code/label/alias is already reserved.');
        $entity->set('aliases', array_values($aliases));
        if (!$entity->isNew()) {
            // Read the stored semantics under the same tenant lock as first use.
            $stored = $this->em->getEntityById('RecordPredicate', $entity->getId());
            if (!$stored) throw new Conflict();
            if ((int) $stored->get('versionNumber') !== (int) $entity->getFetched('versionNumber')) throw new Conflict('Predicate definition changed.');
            if ($this->registry->used($stored)) {
                foreach (['subjectTypes', 'objectTypes', 'qualifierSchema'] as $field) {
                    if (QualifierSchema::fingerprint($stored->get($field)) !== QualifierSchema::fingerprint($entity->get($field))) throw new Conflict('Referenced predicate semantics are immutable; create a new predicate.');
                }
            }
        }
    }

    public function beforeRemove(Entity $entity, array $options): void
    {
        $this->tenancy->assert($entity->get('tenantId'), true);
        $this->registry->lock($entity->get('tenantId'));
        if ($this->registry->used($entity)) throw new Conflict('Referenced predicates cannot be deleted; deactivate instead.');
    }

    public function afterSave(Entity $entity, array $options): void { $this->registry->changed($entity->get('tenantId')); }
    public function afterRemove(Entity $entity, array $options): void { $this->registry->changed($entity->get('tenantId')); }
}
