<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAiUsage\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class Projection
{
    private array $records = [];

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private SelectBuilderFactory $selectBuilderFactory,
    ) {}

    public function record(string $scope, ?string $id): ?array
    {
        if (!$id) {
            return null;
        }
        $key = $scope . ':' . $id;
        if (array_key_exists($key, $this->records)) {
            return $this->records[$key];
        }
        $this->records[$key] = null;
        if (!$this->acl->checkScope($scope, 'read')) {
            return null;
        }
        $record = $this->entityManager->getEntityById($scope, $id);
        if (!$record || !$this->acl->check($record, 'read')) {
            return null;
        }
        return $this->records[$key] = [
            'id' => $id, 'scope' => $scope,
            'name' => $this->acl->checkField($scope, 'name') ? $record->get('name') : null,
        ];
    }

    public function accessibleIds(string $tenantId, Period $period): array
    {
        if (!$this->acl->checkScope('ChatwootAiAgentRun', 'read')) {
            return [];
        }
        try {
            $builder = $this->selectBuilderFactory->create()->from('ChatwootAiAgentRun')
                ->withStrictAccessControl()->buildQueryBuilder();
        } catch (Forbidden) {
            return [];
        }
        $query = $builder->select(['id'])->distinct()->where([
            'tenantId' => $tenantId, 'runAt>=' => $period->utcStart(), 'runAt<' => $period->utcCutoff(),
        ])->build();
        $result = [];
        foreach ($this->entityManager->getRDBRepository('ChatwootAiAgentRun')->clone($query)->sth()->find() as $run) {
            $result[$run->getId()] = true;
        }
        return $result;
    }

    public function activity(array $row, ?array $group = null): array
    {
        $result = ['id' => $row['id']];
        foreach (['runAt', 'kind', 'model', 'durationMs', 'usageMetricsVersion', 'modelRequestCount', 'inputTokens', 'outputTokens', 'cachedInputTokens'] as $field) {
            $result[$field] = $this->acl->checkField('ChatwootAiAgentRun', $field) ? ($row[$field] ?? null) : null;
        }
        foreach ([
            'agent' => 'ChatwootAccountUserMembership', 'conversation' => 'ChatwootConversation',
            'opportunity' => 'Opportunity', 'chatwootAccount' => 'ChatwootAccount', 'chatwootContact' => 'ChatwootContact',
            'sourceNote' => 'Note',
        ] as $link => $scope) {
            $result[$link] = $this->acl->checkLink('ChatwootAiAgentRun', $link)
                ? $this->record($scope, $row[$link . 'Id'] ?? null) : null;
        }
        $safeActions = $row;
        foreach (array_merge(['toolsUsed'], array_keys(Dataset::ACTIONS)) as $field) {
            if (!$this->acl->checkField('ChatwootAiAgentRun', $field)) {
                unset($safeActions[$field]);
            }
        }
        $result['actions'] = Dataset::actions($safeActions);
        $result['day'] = $row['day'];
        $result['billingStatus'] = Ledger::exemption($row) ?? Ledger::billingStatus($group);
        return $result;
    }

    public function canRead(Entity $entity): bool
    {
        return $this->acl->check($entity, 'read');
    }
}
