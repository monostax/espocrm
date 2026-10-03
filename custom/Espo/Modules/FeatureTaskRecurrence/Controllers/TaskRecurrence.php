<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTaskRecurrence\Controllers;

use Espo\Core\Api\Request;
use Espo\Modules\FeatureTaskRecurrence\Services\Recurrence;

class TaskRecurrence
{
    public function __construct(private Recurrence $recurrence) {}

    public function postActionPreview(Request $request): object { return $this->recurrence->preview($request->getParsedBody()); }
    public function getActionRead(Request $request): ?object { return $this->recurrence->read((string) $request->getRouteParam('id')); }
    public function postActionConvert(Request $request): object { return $this->recurrence->convert((string) $request->getRouteParam('id'), $request->getParsedBody()); }
    public function postActionMutate(Request $request): ?object { return $this->recurrence->mutate((string) $request->getRouteParam('id'), $request->getParsedBody()); }
}
