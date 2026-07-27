<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Hooks\Automation;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureAutomation\Entities\Automation;
use Espo\Modules\FeatureAutomation\Services\ScheduleHelper;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<Automation> */
class ValidateScheduling implements BeforeSave
{
    public static int $order = 20;

    public function __construct(
        private ScheduleHelper $scheduleHelper,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof Automation || $options->get('silent')) {
            return;
        }

        if ($entity->get('triggerType') !== Automation::TRIGGER_SCHEDULE) {
            return;
        }

        $rrule = trim((string) ($entity->get('scheduling') ?? ''));
        if ($rrule === '') {
            if ($entity->get('status') === Automation::STATUS_ACTIVE) {
                throw new BadRequest('RRULE is required for a scheduled automation.');
            }

            return;
        }

        try {
            $this->scheduleHelper->validate(
                $rrule,
                (string) ($entity->get('timezone') ?: 'UTC'),
            );
        } catch (\InvalidArgumentException $e) {
            throw new BadRequest($e->getMessage());
        }

        if ($entity->get('status') !== Automation::STATUS_ACTIVE) {
            return;
        }

        if (
            !$entity->isNew() &&
            !$entity->isAttributeChanged('scheduling') &&
            !$entity->isAttributeChanged('timezone') &&
            !$entity->isAttributeChanged('status')
        ) {
            return;
        }

        $activatedAt = $entity->get('activatedAt') ?: date('Y-m-d H:i:s');
        $entity->set('activatedAt', $activatedAt);
        $startAt = new \DateTimeImmutable((string) $activatedAt, new \DateTimeZone('UTC'));

        $entity->set('nextRunAt', $this->scheduleHelper->nextRunAt(
            $rrule,
            (string) ($entity->get('timezone') ?: 'UTC'),
            null,
            $startAt,
        ));
    }
}
