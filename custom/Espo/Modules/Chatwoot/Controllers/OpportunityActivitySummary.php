<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\Chatwoot\Services\OpportunityActivitySummary as Summary;
use Espo\Modules\Crm\Tools\Activities\FetchParams;
use Espo\Modules\Crm\Tools\Activities\Service as Activities;

class OpportunityActivitySummary
{
    public function __construct(
        private Summary $summary,
        private Activities $activities,
        private Acl $acl,
    ) {}

    public function getActionSummary(Request $request): object
    {
        return (object) $this->summary->get($request);
    }

    public function getActionCount(Request $request): object
    {
        if (!$this->acl->check('Activities')) {
            throw new Forbidden();
        }
        $id = $request->getRouteParam('id');
        if (!$id) {
            throw new BadRequest();
        }
        // Reuse the tab's ACL, configured activity types and status semantics,
        // but execute only its count queries, with no record hydration.
        $params = new FetchParams(1, 0, null, countOnly: true);
        return (object) ['total' =>
            $this->activities->getActivities('Opportunity', $id, $params)->getTotal() +
            $this->activities->getHistory('Opportunity', $id, $params)->getTotal(),
        ];
    }
}
