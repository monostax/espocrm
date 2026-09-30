<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Tools\Opportunity;

use DateTimeImmutable;
use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\ConflictSilent;
use Espo\Core\Exceptions\Conflict;
use Espo\Modules\Global\Tools\CustomField\MetaProvider;
use Espo\Modules\Global\Tools\CustomField\Conditions;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Entry-time requirements use the tenant field's immutable, flat valueKey. */
class StageRequirements
{
    public function __construct(private EntityManager $entityManager, private MetaProvider $metaProvider, private Acl $acl) {}

    /** @return list<array<string, mixed>> */
    public function getFields(Entity $stage): array
    {
        $keys = $stage->get('requiredCustomFieldKeys') ?? [];
        if (!is_array($keys) || !array_is_list($keys) ||
            array_filter($keys, fn ($key) => !is_string($key) || trim($key) === '')) {
            throw new BadRequest('Required custom field keys must be a list of field keys.');
        }
        if ($keys === []) {
            return [];
        }

        $funnel = $this->entityManager->getEntityById('Funnel', $stage->get('funnelId'));
        if (!$funnel || !$funnel->get('tenantId')) {
            throw new BadRequest('Select a funnel with a tenant before configuring required fields.');
        }
        $meta = $this->metaProvider->getGroupedMeta('Opportunity', $funnel->get('tenantId'));
        $available = [];
        foreach ($meta['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                $available[$field['valueKey']] = $field + ['groupLabel' => $group['label']];
            }
        }
        $fields = [];
        foreach (array_unique($keys) as $key) {
            if (!isset($available[$key]) || Conditions::evaluate($available[$key]['appliesWhen'] ?? null, [
                'funnelId' => $stage->get('funnelId'), 'opportunityStageId' => $stage->getId(),
            ], true) === false) {
                throw ConflictSilent::createWithBody('opportunityStageConfiguration', json_encode([
                    'code' => 'opportunityStageConfiguration',
                    'stageId' => $stage->get('id'),
                    'stageName' => $stage->get('name'),
                    'fieldKey' => $key,
                ], JSON_THROW_ON_ERROR));
            }
            $fields[] = $available[$key];
        }
        return $fields;
    }

    public function validate(Entity $opportunity): void
    {
        $isTransition = $opportunity->isNew() ||
            $opportunity->isAttributeChanged('opportunityStageId') ||
            $opportunity->isAttributeChanged('funnelId') ||
            $opportunity->isAttributeChanged('status');
        $stageId = $opportunity->get('opportunityStageId');
        if (!$stageId) {
            return; // The existing required/stage-funnel validators handle this.
        }
        $stage = $this->entityManager->getEntityById('OpportunityStage', $stageId);
        if (!$stage) {
            throw new BadRequest('The selected stage does not exist.');
        }
        $fields = $isTransition ? $this->getFields($stage) : [];
        $funnel = $this->entityManager->getEntityById('Funnel', $stage->get('funnelId'));
        if (!$funnel || $funnel->get('tenantId') !== $opportunity->get('tenantId')) {
            throw new BadRequest('The opportunity and required fields must belong to the same tenant.');
        }

        $meta = $this->metaProvider->getGroupedMeta('Opportunity', $opportunity->get('tenantId'));
        $allFields = $fields;
        foreach ($meta['groups'] as $group) {
            array_push($allFields, ...$group['fields']);
        }
        $snapshot = $opportunity->get('stageRequirementsSnapshot');
        if ($snapshot !== null && !$opportunity->isNew()) {
            $current = $this->entityManager->getRDBRepository('Opportunity')
                ->where(['id' => $opportunity->getId()])->forUpdate()->findOne();
            if (!$current || (array) $snapshot !== self::snapshot($current, $allFields)) {
                throw new Conflict('The opportunity changed while completing required fields. Reload and try again.');
            }
            $opportunity->set('stageRequirementsSnapshot', null);
        }
        $context = Conditions::context($opportunity, $allFields);
        $fieldsByKey = [];
        foreach ($fields as $field) {
            if (Conditions::evaluate($field['appliesWhen'] ?? null, $context) === true) {
                $fieldsByKey[$field['valueKey']] = $field;
            }
        }
        foreach ($meta['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if (Conditions::required($field, $context)) {
                    $fieldsByKey[$field['valueKey']] = $field + ['groupLabel' => $group['label']];
                }
            }
        }
        if ($fieldsByKey === []) {
            return;
        }

        // Lock before inspecting stored values, just as stage timing does. An unchanged
        // bag on an older ORM entity is not evidence of the currently persisted values.
        $stored = $opportunity->isNew() ? null : $this->entityManager->getRDBRepository('Opportunity')
            ->where(['id' => $opportunity->getId()])->forUpdate()->findOne();
        $bag = (array) ($stored && !$opportunity->isAttributeChanged('customFields')
            ? $stored->get('customFields') : $opportunity->get('customFields'));
        $missing = [];
        foreach ($fieldsByKey as $field) {
            $reason = self::invalidReason($field, $bag[$field['valueKey']] ?? null);
            if ($reason !== null) {
                $missing[] = array_merge($field, ['isRequired' => true, 'reason' => $reason]);
            }
        }
        if ($missing !== []) {
            throw ConflictSilent::createWithBody('opportunityStageRequirements', json_encode([
                'code' => 'opportunityStageRequirements',
                'requirementMode' => $isTransition ? 'stageEntry' : 'save',
                'stageId' => $stageId,
                'stageName' => $stage->get('name'),
                'funnelId' => $stage->get('funnelId'),
                'canEdit' => $this->acl->checkField('Opportunity', 'customFields', 'edit') &&
                    $this->acl->checkField('Opportunity', 'customFields', 'read'),
                'snapshot' => $stored ? self::snapshot($stored, $allFields) : null,
                'fields' => $missing,
            ], JSON_THROW_ON_ERROR));
        }
    }

    private static function snapshot(Entity $entity, array $fields): array
    {
        $bag = (array) $entity->get('customFields');
        ksort($bag);
        $context = Conditions::context($entity, $fields);
        ksort($context);
        return [
            'stageId' => $entity->get('opportunityStageId'),
            'funnelId' => $entity->get('funnelId'),
            'status' => $entity->get('status'),
            'visitId' => $entity->get('currentStageVisitId'),
            'valuesHash' => hash('sha256', json_encode($bag, JSON_THROW_ON_ERROR)),
            'conditionsHash' => hash('sha256', json_encode($context, JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param array<string, mixed> $field */
    public static function invalidReason(array $field, mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '') || $value === []) {
            return 'required';
        }
        $type = $field['type'];
        $valid = match ($type) {
            'varchar', 'text' => is_string($value),
            'bool' => is_bool($value),
            'int' => is_int($value),
            'float' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'enum' => is_string($value) && in_array($value, $field['options'] ?? [], true),
            'multiEnum' => is_array($value) && array_is_list($value) &&
                count(array_filter($value, fn ($item) => is_string($item) && trim($item) !== '' &&
                    in_array($item, $field['options'] ?? [], true))) === count($value),
            'date' => self::validDate($value, 'Y-m-d'),
            'datetime' => self::validDate($value, 'Y-m-d H:i:s'),
            default => false,
        };
        if (!$valid) {
            return 'valid';
        }
        if (in_array($type, ['int', 'float'], true) &&
            ((isset($field['min']) && $value < $field['min']) ||
             (isset($field['max']) && $value > $field['max']))) {
            return 'range';
        }
        if (in_array($type, ['varchar', 'text'], true) && isset($field['maxLength']) &&
            mb_strlen($value) > $field['maxLength']) {
            return 'maxLength';
        }
        return null;
    }

    private static function validDate(mixed $value, string $format): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        return $date && $date->format($format) === $value;
    }
}
