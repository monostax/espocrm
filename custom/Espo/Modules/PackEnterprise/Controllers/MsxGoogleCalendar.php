<?php

namespace Espo\Modules\PackEnterprise\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\InjectableFactory;
use Espo\Modules\PackEnterprise\Services\MsxGoogleCalendar as MsxGoogleCalendarService;

class MsxGoogleCalendar
{
    private InjectableFactory $injectableFactory;

    public function __construct(
        InjectableFactory $injectableFactory
    ) {
        $this->injectableFactory = $injectableFactory;
    }

    /**
     * @return array<string, string>
     */
    public function getActionUsersCalendars(Request $request): array
    {
        $oAuthAccountId = $request->getQueryParam('oAuthAccountId');

        $service = $this->injectableFactory->create(MsxGoogleCalendarService::class);

        if ($oAuthAccountId) {
            return $service->usersCalendarsByOAuthAccount($oAuthAccountId);
        }

        return $service->usersCalendars();
    }
}
