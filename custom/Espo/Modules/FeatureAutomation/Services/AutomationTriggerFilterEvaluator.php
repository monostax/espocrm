<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeatureJourney\Services\CustomFieldsBag;
use Espo\Modules\FeatureJourney\Services\RestrictedFormulaRunner;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Evaluates the visual trigger condition tree or its formula override against
 * the record that caused an entity-change or signal trigger.
 *
 * Also materializes matching subjects for schedule / bulk Machine runs when
 * triggerPayload does not name a single entity.
 */
class AutomationTriggerFilterEvaluator
{
    private const SCAN_CAP = 2000;

    public function __construct(
        private EntityManager $entityManager,
        private RestrictedFormulaRunner $formulaRunner,
        private CustomFieldsBag $customFieldsBag,
        private SelectBuilderFactory $selectBuilderFactory,
        private TenantGuard $tenantGuard,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $triggerPayload
     */
    public function matches(
        Entity $automation,
        ?string $entityType,
        ?string $entityId,
        array $triggerPayload = [],
    ): bool {
        $formula = trim((string) ($automation->get('entityTypeFilterFormula') ?? ''));
        $tree = $this->normalizeTree($automation->get('entityTypeFilter'));

        if ($formula === '' && !$this->hasRules($tree)) {
            return true;
        }

        if (!$entityType || !$entityId) {
            return false;
        }

        try {
            $subject = $this->entityManager->getEntityById($entityType, $entityId);

            if (!$subject) {
                return false;
            }

            if ($formula !== '') {
                return (bool) $this->formulaRunner->run(
                    $formula,
                    $subject,
                    (object) [
                        'trigger' => (object) $triggerPayload,
                        'triggerType' => $automation->get('triggerType'),
                        'entityType' => $entityType,
                        'entityId' => $entityId,
                        'signalCode' => $triggerPayload['signal'] ?? null,
                        'event' => $triggerPayload['event'] ?? null,
                    ],
                    RestrictedFormulaRunner::MODE_CONDITION,
                );
            }

            return $tree !== null && $this->customFieldsBag->matchWhereItem($tree, $subject);
        } catch (Throwable $e) {
            $this->log->warning(
                'AutomationTriggerFilterEvaluator ' . $automation->getId() . ': ' . $e->getMessage()
            );

            return false;
        }
    }

    /**
     * Whether the automation has a subject filter usable without an explicit entityId.
     */
    public function hasSubjectFilter(Entity $automation): bool
    {
        $formula = trim((string) ($automation->get('entityTypeFilterFormula') ?? ''));

        if ($formula !== '') {
            return true;
        }

        return $this->hasRules($this->normalizeTree($automation->get('entityTypeFilter')));
    }

    /**
     * Resolve subject entity type for Machine / filtered schedule runs.
     */
    public function resolveSubjectEntityType(Entity $automation): string
    {
        $expected = trim((string) ($automation->get('subjectEntityType') ?? ''));

        if ($expected !== '') {
            return $expected;
        }

        $legacy = $automation->get('entityTypeFilter');

        if (is_string($legacy) && !str_starts_with(ltrim($legacy), '{') && !str_starts_with(ltrim($legacy), '[')) {
            return trim($legacy);
        }

        return '';
    }

    /**
     * Query subjects matching the automation filter (schedule Machine path).
     *
     * @param array<string, mixed> $triggerPayload
     * @return list<Entity>
     *
     * @throws Error
     */
    public function findMatchingSubjects(
        Entity $automation,
        string $entityType,
        ?string $tenantId,
        int $limit,
        array $triggerPayload = [],
        ?User $actor = null,
        bool $crossTenant = false,
    ): array {
        $limit = max(1, min(self::SCAN_CAP, $limit));
        $formula = trim((string) ($automation->get('entityTypeFilterFormula') ?? ''));
        $tree = $this->normalizeTree($automation->get('entityTypeFilter'));

        if ($formula === '' && !$this->hasRules($tree)) {
            throw new Error(
                'Machine schedule requires entityTypeFilter / entityTypeFilterFormula, '
                . 'or triggerPayload.entityType and entityId.'
            );
        }

        try {
            if ($formula !== '') {
                return $this->findByFormula(
                    $automation,
                    $entityType,
                    $tenantId,
                    $limit,
                    $formula,
                    $triggerPayload,
                    $actor,
                    $crossTenant,
                );
            }

            assert($tree !== null);

            return $this->findByVisualTree(
                $entityType,
                $tenantId,
                $limit,
                $tree,
                $actor,
                $crossTenant,
            );
        } catch (Error $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log->warning(
                'AutomationTriggerFilterEvaluator findMatchingSubjects '
                . $automation->getId() . ': ' . $e->getMessage()
            );

            throw new Error(
                'Failed to materialize Machine subjects from filter: ' . $e->getMessage()
            );
        }
    }

    /**
     * @param array<string, mixed> $triggerPayload
     * @return list<Entity>
     */
    private function findByFormula(
        Entity $automation,
        string $entityType,
        ?string $tenantId,
        int $limit,
        string $formula,
        array $triggerPayload,
        ?User $actor,
        bool $crossTenant = false,
    ): array {
        $candidates = $this->loadCandidates(
            $entityType,
            $tenantId,
            min(self::SCAN_CAP, max($limit * 20, 200)),
            $actor,
            $crossTenant,
        );
        $out = [];

        foreach ($candidates as $subject) {
            try {
                $ok = (bool) $this->formulaRunner->run(
                    $formula,
                    $subject,
                    (object) [
                        'trigger' => (object) $triggerPayload,
                        'triggerType' => $automation->get('triggerType'),
                        'entityType' => $entityType,
                        'entityId' => $subject->getId(),
                        'signalCode' => $triggerPayload['signal'] ?? null,
                        'event' => $triggerPayload['event'] ?? null,
                    ],
                    RestrictedFormulaRunner::MODE_CONDITION,
                );
            } catch (Throwable $e) {
                $this->log->warning(
                    'AutomationTriggerFilterEvaluator formula subject '
                    . $subject->getId() . ': ' . $e->getMessage()
                );
                $ok = false;
            }

            if (!$ok) {
                continue;
            }

            $out[] = $subject;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $tree
     * @return list<Entity>
     */
    private function findByVisualTree(
        string $entityType,
        ?string $tenantId,
        int $limit,
        array $tree,
        ?User $actor,
        bool $crossTenant = false,
    ): array {
        if ($actor === null) {
            throw new Error('findByVisualTree requires run-as user for ACL');
        }

        $items = $this->customFieldsBag->normalizeWhereList($tree);
        $partition = $this->customFieldsBag->partitionWhere($items);

        // Fail closed unless cross-tenant was explicitly authorized: the run-as user
        // may be an admin (ACL bypass), so the tenant predicate is the real boundary.
        $tenantWhere = $this->tenantGuard->tenantWhereForRead(
            $entityType,
            $tenantId,
            'automation subject filter',
            $crossTenant,
        );

        $fetchLimit = $partition['bag'] !== []
            ? min(self::SCAN_CAP, max($limit * 20, 200))
            : $limit;

        $builder = $this->selectBuilderFactory
            ->create()
            ->from($entityType)
            ->forUser($actor)
            ->withAccessControlFilter();

        if ($partition['native'] !== []) {
            $builder->withWhere(WhereItem::fromRawAndGroup($partition['native']));
        }

        $qb = $builder->buildQueryBuilder();

        if ($tenantWhere !== []) {
            $qb->where($tenantWhere);
        }

        $query = $qb->select(['id'])->limit(0, $fetchLimit)->build();
        $found = $this->entityManager
            ->getRDBRepository($entityType)
            ->clone($query)
            ->find();

        $out = [];
        foreach ($found as $row) {
            if (!$row instanceof Entity) {
                continue;
            }

            // Re-load full entity: select(['id']) leaves attributes empty so
            // matchWhereItem (emailAddress, bag fields, …) would false-negative.
            $subject = $this->entityManager->getEntityById($entityType, (string) $row->getId());
            if (!$subject) {
                continue;
            }

            if ($partition['bag'] !== [] && !$this->customFieldsBag->matchWhereItems($partition['bag'], $subject)) {
                continue;
            }

            // Re-check full tree in memory so mixed native+bag semantics stay correct.
            if (!$this->customFieldsBag->matchWhereItem($tree, $subject)) {
                continue;
            }

            if (!$this->tenantGuard->entityAllowedForRead($subject, $tenantId, $crossTenant)) {
                continue;
            }

            $out[] = $subject;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<Entity>
     */
    private function loadCandidates(
        string $entityType,
        ?string $tenantId,
        int $limit,
        ?User $actor,
        bool $crossTenant = false,
    ): array {
        if ($actor === null) {
            throw new Error('loadCandidates requires run-as user for ACL');
        }

        // Fail closed — see findByVisualTree(). ACL alone is not a tenant boundary.
        $tenantWhere = $this->tenantGuard->tenantWhereForRead(
            $entityType,
            $tenantId,
            'automation candidate scan',
            $crossTenant,
        );

        $builder = $this->selectBuilderFactory
            ->create()
            ->from($entityType)
            ->forUser($actor)
            ->withAccessControlFilter();

        $qb = $builder->buildQueryBuilder();

        if ($tenantWhere !== []) {
            $qb->where($tenantWhere);
        }

        $query = $qb->limit(0, $limit)->build();
        $found = $this->entityManager
            ->getRDBRepository($entityType)
            ->clone($query)
            ->find();

        $out = [];
        foreach ($found as $subject) {
            if (!$subject instanceof Entity) {
                continue;
            }

            // Formula candidates are handed to user-authored scripts — never leak a
            // foreign-tenant row into that scope even if the query somehow matched.
            if (!$this->tenantGuard->entityAllowedForRead($subject, $tenantId, $crossTenant)) {
                continue;
            }

            $out[] = $subject;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeTree(mixed $value): ?array
    {
        if ($value instanceof \stdClass) {
            $value = json_decode(json_encode($value) ?: '{}', true);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (!is_array($decoded)) {
                // A pre-builder string held an entity type. The dispatcher still
                // honors it as a legacy type constraint, not as a predicate.
                return null;
            }

            $value = $decoded;
        }

        if (!is_array($value) || $value === []) {
            return null;
        }

        if (isset($value['type']) || isset($value['attribute'])) {
            return $value;
        }

        if (array_is_list($value)) {
            return [
                'type' => 'and',
                'value' => $value,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $node
     */
    private function hasRules(?array $node): bool
    {
        if ($node === null) {
            return false;
        }

        $type = $node['type'] ?? null;

        if (in_array($type, ['and', 'or'], true)) {
            $children = $node['value'] ?? [];

            if (!is_array($children)) {
                return false;
            }

            foreach ($children as $child) {
                if (is_array($child) && $this->hasRules($child)) {
                    return true;
                }
            }

            return false;
        }

        if ($type === 'not') {
            $inner = $node['value'] ?? null;

            return is_array($inner) && $this->hasRules($inner);
        }

        return isset($node['attribute']) && is_string($node['attribute']) && $node['attribute'] !== '';
    }
}
