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
    // Only sources with an explicit tenantId ownership contract are supported.

    public function __construct(private EntityManager $entityManager, private Acl $acl) {}

    public function inspect(string $tenantId, string $usageId): array
    {
        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
        if (!$tenant || $tenant->get('deleted')) {
            throw new NotFound('Unknown accounting tenant.');
        }
        $usage = $this->entityManager->getEntityById('CreditUsage', $usageId);
        if (!$usage || $usage->get('deleted') || $usage->get('tenantId') !== $tenantId) {
            throw new NotFound('Unknown credit operation.');
        }
        $result = ['tenantId' => $tenantId, 'id' => $usageId, 'source' => null];
        $scope = $usage->get('sourceType');
        $id = $usage->get('sourceId');
        if (!is_string($scope) || !isset(SourceReference::TABLES[$scope]) || !is_string($id) ||
            !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id) || !$this->acl->checkScope($scope, 'read')) {
            return $result;
        }
        $source = $this->entityManager->getEntityById($scope, $id);
        if (!$source || $source->get('deleted') || $source->get('tenantId') !== $tenantId ||
            !$this->acl->check($source, 'read')) {
            return $result;
        }
        $result['source'] = ['scope' => $scope, 'id' => $id,
            'name' => $this->acl->checkField($scope, 'name') ? $source->get('name') : null];
        return $result;
    }
}
