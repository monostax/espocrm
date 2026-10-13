<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureCredits\Accounting\SourceReference;

/** Read-time navigation only. Financial access never implies source-record access. */
final class OperationSource
{
    // Admission and navigation share the source ownership contract. Navigation
    // also requires the viewer's current record permissions.

    public function __construct(private EntityManager $entityManager, private Acl $acl) {}

    public function inspect(string $tenantId, string $usageId): array
    {
        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
        if (!$tenant || $tenant->get('deleted')) {
            throw new NotFound('Unknown accounting tenant.');
        }
        // Financial records are projections, not generic CRM record views.
        // Hydrating all CreditUsage links asks ORM for billingRate.name, while
        // TenantCreditBillingRate is versioned and has no name column.
        $query = $this->entityManager->getPDO()->prepare(
            'SELECT source_type, source_id, evidence FROM credit_usage WHERE id = ? AND tenant_id = ? AND deleted = FALSE'
        );
        $query->execute([$usageId, $tenantId]);
        $usage = $query->fetch(\PDO::FETCH_ASSOC);
        if (!$usage) {
            throw new NotFound('Unknown credit operation.');
        }
        $result = ['tenantId' => $tenantId, 'id' => $usageId, 'source' => null];
        $scope = $usage['source_type'];
        $id = $usage['source_id'];
        if (!is_string($scope) || !SourceReference::supports($scope) || !is_string($id) ||
            !preg_match('/^[a-zA-Z0-9_-]{1,24}$/D', $id) || !$this->acl->checkScope($scope, 'read')) {
            return $result;
        }
        $source = $this->entityManager->getEntityById($scope, $id);
        if (!$source || $source->get('deleted') || !$this->acl->check($source, 'read')) {
            return $result;
        }
        if (in_array($scope, SourceReference::LINKED_TYPES, true)) {
            if (!(new SourceReference($scope, $id))->belongsToTenant($this->entityManager->getPDO(), $tenantId)) return $result;
        } elseif ($source->get('tenantId') !== $tenantId) return $result;
        if ($scope === 'Note') {
            $parentType = $source->get('parentType');
            $parentId = $source->get('parentId');
            if (!is_string($parentType) || !is_string($parentId) || !$this->acl->checkScope($parentType, 'read')) return $result;
            $parent = $this->entityManager->getEntityById($parentType, $parentId);
            if (!$parent || !$this->acl->check($parent, 'read') || !$this->acl->checkEntity($parent, 'stream')) return $result;
        }
        $result['source'] = ['scope' => $scope, 'id' => $id,
            'name' => $this->acl->checkField($scope, 'name') ? $source->get('name') : null];
        $evidence = json_decode($usage['evidence'] ?? 'null');
        $requestId = is_object($evidence) ? ($evidence->sourceRequestId ?? null) : null;
        if (is_string($requestId) && preg_match('/^[a-f0-9]{64}$/D', $requestId)) $result['source']['requestId'] = $requestId;
        return $result;
    }
}
