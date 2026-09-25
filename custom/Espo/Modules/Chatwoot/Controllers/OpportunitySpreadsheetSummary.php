<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Modules\Chatwoot\Services\OpportunitySpreadsheetSummary as Summary;

class OpportunitySpreadsheetSummary
{
    public function __construct(private Summary $summary) {}

    public function getActionSummary(Request $request, Response $response): object
    {
        $started = hrtime(true);
        $result = $this->summary->get($request);
        $response->setHeader('Cache-Control', 'private, no-store');
        $response->setHeader('Server-Timing', 'spreadsheetSummary;dur=' . round((hrtime(true) - $started) / 1e6, 2));
        return (object) $result;
    }
}
