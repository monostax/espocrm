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

namespace Espo\Modules\Global\Hooks\Opportunity;

use DateTimeZone;
use Espo\Core\Field\Date;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Config;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * Sets `closeDate` to today when status transitions into Won/Lost
 * (probability 100 / 0 via OpportunityStage). Clears it when reopening.
 *
 * Runs AFTER {@see SyncFromOpportunityStage} ($order = 6) so status is
 * already synchronized from the stage probability.
 *
 * On create-as-Won / create-as-Lost always stamps today (overwrites factory
 * “expected close +30d”). On update, keeps an explicit closeDate the user
 * sent in the same payload.
 *
 * @implements BeforeSave<Opportunity>
 */
class AutoSetCloseDateOnStatusChange implements BeforeSave
{
    public static int $order = 9;

    private const CLOSED = ['Won', 'Lost'];

    public function __construct(
        private Config $config,
    ) {}

    /**
     * @param Opportunity $entity
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $newStatus = (string) ($entity->get('status') ?? '');
        $oldStatus = null;

        if (!$entity->isNew()) {
            $fetched = $entity->getFetched('status');
            $oldStatus = $fetched !== null && $fetched !== '' ? (string) $fetched : null;
        }

        if ($newStatus === (string) ($oldStatus ?? '')) {
            return;
        }

        if (in_array($newStatus, self::CLOSED, true)) {
            // Existing record + user explicitly sent closeDate this save → keep it.
            if (
                !$entity->isNew() &&
                $entity->isAttributeChanged('closeDate') &&
                $entity->get('closeDate') !== null &&
                $entity->get('closeDate') !== ''
            ) {
                return;
            }

            $entity->set('closeDate', $this->todayString());

            return;
        }

        if (
            $newStatus === 'Open' &&
            $oldStatus !== null &&
            in_array($oldStatus, self::CLOSED, true)
        ) {
            if ($entity->isAttributeChanged('closeDate')) {
                return;
            }

            $entity->set('closeDate', null);
        }
    }

    private function todayString(): string
    {
        $tzName = trim((string) $this->config->get('timeZone', 'UTC'));
        if ($tzName === '') {
            $tzName = 'UTC';
        }

        try {
            $tz = new DateTimeZone($tzName);
        } catch (Throwable) {
            $tz = new DateTimeZone('UTC');
        }

        return Date::createToday($tz)->toString();
    }
}
