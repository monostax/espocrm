<?php

namespace Espo\Modules\FeatureIntegrationSimplesAgenda\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\FeatureIntegrationSimplesAgenda\Jobs\SyncContactsFromSimplesAgenda;
use stdClass;

class SimplesAgendaCliente extends \Espo\Core\Templates\Controllers\Base
{
    /**
     * POST SimplesAgendaCliente/action/syncNow
     *
     * @throws Forbidden
     */
    public function postActionSyncNow(Request $request): stdClass
    {
        if (!$this->acl->check('SimplesAgendaCliente', 'edit')) {
            throw new Forbidden();
        }

        $job = $this->injectableFactory->create(SyncContactsFromSimplesAgenda::class);
        $job->run();

        return (object) [
            'success' => true,
        ];
    }
}
