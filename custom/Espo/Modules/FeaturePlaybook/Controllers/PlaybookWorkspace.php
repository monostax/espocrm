<?php

declare(strict_types=1);

namespace Espo\Modules\FeaturePlaybook\Controllers;

use Espo\Core\Api\Request;
use Espo\Modules\FeaturePlaybook\Services\Workspace;

class PlaybookWorkspace
{
    public function __construct(private Workspace $workspace) {}

    public function getActionList(Request $request): object
    {
        return $this->workspace->listing($request);
    }

    public function getActionDetail(Request $request): object
    {
        return $this->workspace->detail((string) $request->getRouteParam('accountId'), (string) $request->getRouteParam('id'));
    }

    public function postActionSave(Request $request): object
    {
        return $this->workspace->save((string) $request->getRouteParam('accountId'), $request->getParsedBody());
    }
}
