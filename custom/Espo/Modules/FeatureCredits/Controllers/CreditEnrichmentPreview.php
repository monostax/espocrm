<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCredits\Enrichment\Quotes;
use Espo\Modules\FeatureCredits\Enrichment\EmployeeFilters;
use InvalidArgumentException;

final class CreditEnrichmentPreview
{
    public function __construct(private Quotes $quotes, private EmployeeFilters $employeeFilters) {}

    public function getActionRead(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        try {
            $preview = $this->quotes->preview($request->getQueryParams());
            foreach ($preview['actions'] as &$action) {
                if ($action['action'] === 'employees' && $action['available']) {
                    $action['filterOptions'] = $this->employeeFilters->options();
                }
            }
            unset($action);
            return (object) $preview;
        } catch (InvalidArgumentException $e) {
            throw new BadRequest($e->getMessage());
        }
    }
}
