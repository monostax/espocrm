<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hooks\CustomFieldDef;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Acl;
use Espo\Modules\Global\Tools\CustomField\ConditionSchema;
use Espo\Modules\Global\Tools\CustomField\Conditions;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateConditions implements BeforeSave
{
    public static int $order = 20;

    public function __construct(private EntityManager $entityManager, private ConditionSchema $schema, private Acl $acl) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $attributes = $this->schema->getAttributes((string) $entity->get('entityType'));
        foreach (['appliesWhen', 'requiredWhen'] as $attribute) {
            $condition = $entity->get($attribute);
            Conditions::validate($condition);
            foreach (Conditions::leaves($condition) as $leaf) {
                $descriptor = $this->schema->validateLeaf($leaf, $attributes);
                if (!$this->acl->checkField((string) $entity->get('entityType'), $descriptor['field'], 'read')) {
                    throw new BadRequest('Condition references an unavailable field.');
                }
                if ($descriptor['type'] !== 'link' || !isset($leaf['value'])) {
                    continue;
                }
                $values = $leaf['operator'] === 'in' ? $leaf['value'] : [$leaf['value']];
                foreach ($values as $id) {
                    $type = $descriptor['entity'];
                    $record = $this->entityManager->getEntityById($type, $id);
                    if (!$record || !$this->acl->check($record, 'read')) {
                        throw new BadRequest('Condition references an unavailable record.');
                    }
                    $tenantRecord = $type === 'OpportunityStage'
                        ? $this->entityManager->getEntityById('Funnel', $record->get('funnelId')) : $record;
                    if ($descriptor['tenantScoped'] && (!$tenantRecord || !$entity->get('tenantId') ||
                        $tenantRecord->get('tenantId') !== $entity->get('tenantId'))) {
                        throw new BadRequest('Condition references must belong to the custom field tenant.');
                    }
                }
            }
        }

        if ($entity->isNew() || (!$entity->isAttributeChanged('appliesWhen') &&
            !$entity->isAttributeChanged('isActive') && !$entity->isAttributeChanged('tenantId') &&
            !$entity->isAttributeChanged('entityType'))) {
            return;
        }
        // Scope edits must not invalidate an existing transition gate.
        $oldTenant = $entity->getFetched('tenantId') ?? $entity->get('tenantId');
        if (!$oldTenant || ($entity->getFetched('entityType') ?? $entity->get('entityType')) !== 'Opportunity') {
            return;
        }
        $key = $entity->get('valueKey');
        $stages = $this->entityManager->getRDBRepository('OpportunityStage')
            ->join('funnel')->where(['funnel.tenantId' => $oldTenant])->find();
        foreach ($stages as $stage) {
            if (!in_array($key, $stage->get('requiredCustomFieldKeys') ?? [], true)) {
                continue;
            }
            $funnel = $this->entityManager->getEntityById('Funnel', $stage->get('funnelId'));
            if (!$funnel || $funnel->get('tenantId') !== ($entity->getFetched('tenantId') ?? $entity->get('tenantId')) ||
                ($entity->getFetched('entityType') ?? $entity->get('entityType')) !== 'Opportunity') {
                continue;
            }
            if ($entity->get('entityType') !== 'Opportunity' || !$entity->get('isActive') ||
                $entity->get('tenantId') !== $funnel->get('tenantId') ||
                Conditions::evaluate($entity->get('appliesWhen'), [
                    'funnelId' => $stage->get('funnelId'), 'opportunityStageId' => $stage->getId(),
                ], true) === false) {
                throw new BadRequest('Remove this field from incompatible stage requirements before changing its scope.');
            }
        }
    }
}
