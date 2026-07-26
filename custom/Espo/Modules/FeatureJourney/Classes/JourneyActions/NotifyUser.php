<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\EntityManager;

class NotifyUser implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantGuard $tenantGuard,
        private Log $log,
    ) {}

    public function run(ActionContext $context): void
    {
        $tenantId = $context->tenantId;
        if (!$tenantId) {
            throw new Error('NotifyUser: missing tenantId.');
        }

        $params = $context->params;
        $userId = isset($params['userId'])
            ? (string) $params['userId']
            : (isset($params['assignedUserId']) ? (string) $params['assignedUserId'] : '');

        $message = (string) ($params['message'] ?? ('Journey update: ' . ($context->journey->get('name') ?: '')));

        if ($userId === '') {
            $this->log->warning('NotifyUser action: no userId');

            return;
        }

        $this->tenantGuard->assertUserInTenant($userId, $tenantId, 'notifyUser');

        $notification = $this->entityManager->getNewEntity('Notification');
        $notification->set([
            'type' => 'Message',
            'userId' => $userId,
            'message' => $message,
            'relatedType' => $context->record->getEntityType(),
            'relatedId' => $context->record->getId(),
        ]);

        $this->entityManager->saveEntity($notification, [
            SaveOption::SILENT => true,
        ]);
    }
}
