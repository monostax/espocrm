<?php

declare(strict_types=1);

namespace Espo\Modules\FeaturePlaybook\Controllers;

use Espo\Core\Api\Request;
use Espo\Modules\FeaturePlaybook\Services\Playbooks;

class OpportunityPlaybook
{
    public function __construct(private Playbooks $playbooks) {}

    public function getActionList(Request $request): object
    {
        return $this->playbooks->overview((string) $request->getRouteParam('id'));
    }

    public function postActionApply(Request $request): object
    {
        return $this->playbooks->apply((string) $request->getRouteParam('id'), $request->getParsedBody());
    }

    public function postActionTemplate(Request $request): object
    {
        return $this->playbooks->saveTemplate((string) $request->getRouteParam('id'), $request->getParsedBody());
    }

    public function postActionMutate(Request $request): object
    {
        return $this->playbooks->mutate(
            (string) $request->getRouteParam('id'),
            (string) $request->getRouteParam('runId'),
            $request->getParsedBody(),
        );
    }
}
