<?php

namespace Espo\Modules\PackEnterprise\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\PackEnterprise\Services\MsxGoogleCalendar as MsxGoogleCalendarService;
use stdClass;

class MsxGoogleCalendarUser extends \Espo\Core\Controllers\Record
{
    /**
     * POST MsxGoogleCalendarUser/:id/syncNow - Trigger an immediate sync.
     *
     * @throws BadRequest
     * @throws NotFound
     */
    public function postActionSyncNow(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        $entityManager = $this->entityManager;

        $entity = $entityManager->getEntityById('MsxGoogleCalendarUser', $id);

        if (!$entity) {
            throw new NotFound("MsxGoogleCalendarUser '{$id}' not found.");
        }

        $GLOBALS['log']->debug("MsxGoogleCalendar [SyncNow]: Controller hit for MsxGoogleCalendarUser id={$id}, " .
            "userId=" . $entity->get('userId') . ", active=" . var_export($entity->get('active'), true) .
            ", oAuthAccountId=" . ($entity->get('oAuthAccountId') ?? 'NULL') .
            ", direction=" . ($entity->get('calendarDirection') ?? 'NULL'));

        $service = $this->injectableFactory->create(MsxGoogleCalendarService::class);
        $service->syncCalendar($entity);

        $GLOBALS['log']->debug("MsxGoogleCalendar [SyncNow]: syncCalendar() returned for id={$id}");

        // Reload to get updated fields (lastSync, lastLooked, etc.)
        $entity = $entityManager->getEntityById('MsxGoogleCalendarUser', $id);

        return $entity->getValueMap();
    }
}
