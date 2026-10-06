<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\EntityManager;
use Espo\Modules\Chatwoot\Tools\Billing\AiBudget;

final class AiBudgetStatus
{
    public function __construct(private EntityManager $entityManager, private Acl $acl, private AiBudget $budget) {}

    public function getActionRead(Request $request): object
    {
        $rate = $this->entityManager->getEntityById('TenantAiBillingRate', (string) $request->getRouteParam('id'));
        if (!$rate || !$this->acl->check($rate, 'read')) throw new Forbidden();
        return $this->budget->execute((object) ['operation' => 'status', 'tenantId' => $rate->get('tenantId')]);
    }
}
