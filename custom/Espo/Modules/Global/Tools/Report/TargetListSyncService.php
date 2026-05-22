<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

declare(strict_types=1);

namespace Espo\Modules\Global\Tools\Report;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Advanced\Entities\Report;
use Espo\Modules\Advanced\Tools\Report\Service as AdvancedReportService;
use Espo\Modules\Advanced\Tools\Report\TargetListSyncService as ParentTargetListSyncService;
use Espo\Modules\Crm\Entities\TargetList;
use Espo\Modules\Crm\Tools\TargetList\RecordService;
use Espo\ORM\EntityManager;

/**
 * Overrides Advanced's TargetListSyncService so the query that selects
 * which target-entity rows to attach to a TargetList is built **for the
 * running user** (passing `$user` into `Service::prepareSelectBuilder`).
 *
 * Without that, the parent builds an unscoped query — `prepare()` sees
 * `$user = null` and skips the row-level ACL applier — so `massRelate`
 * copies every matching row of the target entity into the TargetList,
 * bypassing per-tenant trimming. Combined with the Report-side
 * `isGloballyShared` read grant, that would let any non-admin tenant
 * with TargetList edit access exfiltrate cross-tenant rows via a
 * shared List report.
 *
 * The parent declares its dependencies as constructor-promoted private
 * properties, so the subclass cannot reach them. We re-inject the ones
 * needed for the override and forward everything to the parent so the
 * other (un-overridden) methods continue to work.
 *
 * NOTE: kept in sync with
 * {@see ParentTargetListSyncService::populateTargetList} — if Advanced
 * adds new ACL gates / link resolution rules, mirror them here.
 */
class TargetListSyncService extends ParentTargetListSyncService
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private Metadata $metadata,
        ServiceContainer $serviceContainer,
        private AdvancedReportService $service,
        InjectableFactory $injectableFactory,
        private User $user,
    ) {
        parent::__construct(
            $entityManager,
            $acl,
            $metadata,
            $serviceContainer,
            $service,
            $injectableFactory,
        );
    }

    /**
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function populateTargetList(string $id, string $targetListId): void
    {
        /** @var ?Report $report */
        $report = $this->entityManager->getEntityById(Report::ENTITY_TYPE, $id);

        if (!$report) {
            throw new NotFound();
        }

        if (!$this->acl->checkEntityRead($report)) {
            throw new Forbidden();
        }

        $targetList = $this->entityManager->getEntity(TargetList::ENTITY_TYPE, $targetListId);

        if (!$targetList) {
            throw new NotFound();
        }

        if (!$this->acl->checkEntityEdit($targetList)) {
            throw new Forbidden();
        }

        if ($report->getType() !== Report::TYPE_LIST) {
            throw new Error("Report is not of 'List' type.");
        }

        $entityType = $report->getTargetEntityType();

        $linkList = $this->metadata->get(['scopes', 'TargetList', 'targetLinkList']) ??
            ['contacts', 'leads', 'accounts', 'users'];

        $link = null;

        foreach ($linkList as $itemLink) {
            if (
                $this->metadata->get(['entityDefs', 'TargetList', 'links', $itemLink, 'entity']) === $entityType
            ) {
                $link = $itemLink;

                break;
            }
        }

        if (!$link) {
            throw new Error("Not supported entity type '$entityType' for target list sync.");
        }

        // ---- The actual fix ----
        // Pass the running user so `Service::prepareSelectBuilder` →
        // `ListQueryPreparator::prepare(data, null, $user)` applies the
        // user's row-level ACL on the target entity. Admins keep their
        // unrestricted view (Espo's AclManager short-circuits for admins).
        $user = $this->user->isSystem() ? null : $this->user;

        $query = $this->service
            ->prepareSelectBuilder($report, $user)
            ->build();
        // ---- end fix ----

        $this->entityManager
            ->getRDBRepository(TargetList::ENTITY_TYPE)
            ->getRelation($targetList, $link)
            ->massRelate($query);
    }
}
