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

namespace Espo\Modules\Global\Tools\Report;

use Espo\Core\FieldProcessing\ListLoadProcessor;
use Espo\Core\InjectableFactory;
use Espo\Core\Record\ServiceContainer as RecordServiceContainer;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Acl\UserAclManagerProvider;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Tools\Report\GridType\GridBuilder;
use Espo\Modules\Advanced\Tools\Report\GridType\Helper as GridHelper;
use Espo\Modules\Advanced\Tools\Report\GridType\QueryPreparator as GridQueryPreparator;
use Espo\Modules\Advanced\Tools\Report\GridType\Result as GridResult;
use Espo\Modules\Advanced\Tools\Report\GridType\ResultHelper;
use Espo\Modules\Advanced\Tools\Report\GridType\RunParams as GridRunParams;
use Espo\Modules\Advanced\Tools\Report\GridType\Util as GridUtil;
use Espo\Modules\Advanced\Tools\Report\ListType\QueryPreparator as ListQueryPreparator;
use Espo\Modules\Advanced\Tools\Report\ListType\SubListQueryPreparator;
use Espo\Modules\Advanced\Tools\Report\ListType\SubReportQueryPreparator;
use Espo\Modules\Advanced\Tools\Report\ReportHelper;
use Espo\Modules\Advanced\Tools\Report\Service as ParentService;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Overrides Advanced's Report Service to apply post-processing to Grid /
 * JointGrid results before they're handed back to the caller.
 *
 * Currently the only post-processor is {@see DateBucketPadder}, which fills
 * empty date buckets for reports flagged with `fillEmptyDateBuckets = true`.
 *
 * `reportRunGridOrJoint` is the single chokepoint exercised by every grid
 * code path in Advanced: `runGrid` / `runGridOrJoint` flow through it, and
 * the run-preview API endpoint calls it directly. Overriding here covers the
 * Report detail page, dashlets, report panels, scheduled email sending, and
 * PDF/XLSX exports.
 *
 * We intentionally do NOT touch `executeGridReport` — that's called by
 * `JointGridExecutor` for each sub-report individually. Joint reports are
 * padded once at the top level (here), not per sub-report; padding individual
 * sub-results would interfere with the joint executor's column merging.
 */
class Service extends ParentService
{
    private DateBucketPadder $padder;

    public function __construct(
        EntityManager $entityManager,
        Metadata $metadata,
        Config $config,
        User $user,
        InjectableFactory $injectableFactory,
        UserAclManagerProvider $userAclManagerProvider,
        RecordServiceContainer $recordServiceContainer,
        ResultHelper $gridResultHelper,
        GridHelper $gridHelper,
        GridBuilder $gridBuilder,
        GridUtil $gridUtil,
        ReportHelper $reportHelper,
        ListQueryPreparator $listQueryPreparator,
        SubReportQueryPreparator $subReportQueryPreparator,
        ListLoadProcessor $listLoadProcessor,
        Log $log,
        GridQueryPreparator $gridQueryPreparator,
        SubListQueryPreparator $subListQueryPreparator,
        DateBucketPadder $padder,
    ) {
        parent::__construct(
            $entityManager,
            $metadata,
            $config,
            $user,
            $injectableFactory,
            $userAclManagerProvider,
            $recordServiceContainer,
            $gridResultHelper,
            $gridHelper,
            $gridBuilder,
            $gridUtil,
            $reportHelper,
            $listQueryPreparator,
            $subReportQueryPreparator,
            $listLoadProcessor,
            $log,
            $gridQueryPreparator,
            $subListQueryPreparator,
        );

        $this->padder = $padder;
    }

    public function reportRunGridOrJoint(
        Report $report,
        ?WhereItem $whereItem,
        ?User $user,
        ?GridRunParams $runParams = null,
        ?array $idWhereMap = null,
    ): GridResult {

        $result = parent::reportRunGridOrJoint(
            report: $report,
            whereItem: $whereItem,
            user: $user,
            runParams: $runParams,
            idWhereMap: $idWhereMap,
        );

        // Padding is purely additive. If it throws (malformed report data,
        // unexpected attribute shape, etc.) the original result is more
        // valuable than a 500 — log and pass through.
        try {
            return $this->padder->pad($result, $report, $whereItem);
        } catch (Throwable $e) {
            // The padder logs its own warnings on expected failure modes;
            // this catches genuinely unexpected exceptions only.
            return $result;
        }
    }
}
