<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

declare(strict_types=1);

namespace Espo\Modules\Global\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\SearchParamsFetcher;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Json;
use Espo\Modules\Advanced\Controllers\Report as AdvancedReport;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportParams;
use Espo\Modules\Advanced\Tools\Report\Service as AdvancedReportService;

use JsonException;
use stdClass;

/**
 * Overrides Advanced's Report controller so that the two action methods that
 * resolve the Report Service directly via `InjectableFactory::create()` go
 * through `createResolved()` instead, which honors the
 * `Advanced\Tools\Report\Service → Global\Tools\Report\Service`
 * `bindImplementation` mapping registered in {@see \Espo\Modules\Global\Binding}.
 *
 * Why this exists
 * ---------------
 * `InjectableFactory::create()` instantiates the requested class directly and
 * does not consult global `bindImplementation` mappings. Only `createResolved()`
 * (and per-constructor-parameter DI on classes built by the factory) honors
 * those bindings. Advanced's controller calls
 * `$this->injectableFactory->create(Service::class)` inside a `private`
 * `getReportService()` helper, so the binding is bypassed for grid/list runs
 * triggered through the controller.
 *
 * Every other code path that needs the Service receives it through constructor
 * injection on a peer service (`GridExportService`, `ListExportService`,
 * `SendingService`, `JointGridExecutor`, `PreviewReportProvider`,
 * `TargetListSyncService`, `Send` job, the run-preview API actions, …). The
 * factory resolves those constructor params via the binding container, so
 * those paths already pick up our Global Service transparently.
 *
 * The only two action methods that resolve the Service directly via
 * `create()` are `actionRun` and `actionRunList`. We override exactly those
 * two methods and route through `createResolved()`. All other actions are
 * inherited unchanged from the Advanced controller.
 *
 * Module precedence
 * -----------------
 * `ClassMap::getData()` merges per-module class maps in `Module::getOrderedList()`
 * order with later modules winning. Global (order 20) is loaded after Advanced
 * (order 15), so this class wins the `Controllers/Report` lookup performed by
 * `ClassFinder` for the `/Report/...` route family.
 *
 * Helpers
 * -------
 * `fetchSubReportParamsFromRequest` and `normalizeWhere` are duplicated from
 * the parent because both are declared `private` there (and `normalizeWhere`
 * is static), so a subclass cannot reuse them.
 */
class Report extends AdvancedReport
{
    /**
     * List report or grid sub-report.
     *
     * Overrides {@see AdvancedReport::actionRunList} only to swap the
     * Service resolution from `create()` to `createResolved()`.
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function actionRunList(Request $request): stdClass
    {
        $id = $request->getQueryParam('id');

        if (!$id) {
            throw new BadRequest();
        }

        $searchParams = $this->injectableFactory
            ->create(SearchParamsFetcher::class)
            ->fetch($request);

        $subReportParams = $this->fetchSubReportParamsFromRequestLocal($request);

        $service = $this->resolveReportService();

        // Passing the user is important.
        $result = $subReportParams ?
            $service->runSubReportList($id, $searchParams, $subReportParams, $this->user) :
            $service->runList($id, $searchParams, $this->user);

        return (object) [
            'list' => $result->getCollection()->getValueMapList(),
            'total' => $result->getTotal(),
            'columns' => $result->getColumns(),
            'columnsData' => $result->getColumnsData(),
        ];
    }

    /**
     * Grid report.
     *
     * Overrides {@see AdvancedReport::actionRun} only to swap the
     * Service resolution from `create()` to `createResolved()`. This is
     * the entry point that flows through
     * {@see \Espo\Modules\Global\Tools\Report\Service::reportRunGridOrJoint}
     * and therefore through the `DateBucketPadder` post-processing for
     * reports flagged with `fillEmptyDateBuckets = true`.
     *
     * @throws BadRequest
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function actionRun(Request $request): stdClass
    {
        $id = $request->getQueryParam('id');
        $where = $request->getQueryParams()['where'] ?? null;

        if ($where === '') {
            $where = null;
        }

        $whereItem = null;

        if ($where) {
            $whereItem = WhereItem::fromRawAndGroup(self::normalizeWhereLocal($where));
        }

        if (!$id) {
            throw new BadRequest();
        }

        // Passing the user is important.
        return $this->resolveReportService()
            ->runGrid($id, $whereItem, $this->user)
            ->toRaw();
    }

    /**
     * Resolve the Report Service via the binding container so the
     * `Advanced\Service → Global\Service` `bindImplementation` mapping
     * is honored.
     *
     * The return type is intentionally the Advanced Service class — that's
     * the contract the parent controller is written against, and our
     * Global Service extends it. `createResolved` returns the bound
     * implementation (Global Service) which satisfies the Advanced type.
     */
    private function resolveReportService(): AdvancedReportService
    {
        return $this->injectableFactory->createResolved(AdvancedReportService::class);
    }

    /**
     * Duplicate of the parent's private `fetchSubReportParamsFromRequest`.
     * Kept verbatim so the inherited contract is preserved.
     */
    private function fetchSubReportParamsFromRequestLocal(Request $request): ?SubReportParams
    {
        if (!$request->hasQueryParam('groupValue')) {
            return null;
        }

        $groupValue = $request->getQueryParam('groupValue');

        if ($groupValue === '') {
            $groupValue = null;
        }

        $groupValue2 = null;

        if ($request->hasQueryParam('groupValue2')) {
            $groupValue2 = $request->getQueryParam('groupValue2');

            if ($groupValue2 === '') {
                $groupValue2 = null;
            }
        }

        return new SubReportParams(
            (int) ($request->getQueryParam('groupIndex') ?? 0),
            $groupValue,
            $request->hasQueryParam('groupValue2'),
            $groupValue2
        );
    }

    /**
     * Duplicate of the parent's private static `normalizeWhere`. Kept
     * verbatim so the inherited contract is preserved.
     *
     * @throws BadRequest
     */
    private static function normalizeWhereLocal(mixed $where): mixed
    {
        try {
            return Json::decode(Json::encode($where), true);
        } catch (JsonException) {
            throw new BadRequest("Bad where.");
        }
    }
}
