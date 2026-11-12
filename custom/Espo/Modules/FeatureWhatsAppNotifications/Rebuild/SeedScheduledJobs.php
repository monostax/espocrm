<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOption;

class SeedScheduledJobs implements RebuildAction
{
    private const JOBS = [
        'SendWhatsAppMorningCards' => ['Em dia no WhatsApp: morning cards', '*/5 * * * *'],
        'SendWhatsAppReminders' => ['Em dia no WhatsApp: reminders', '*/5 * * * *'],
        'SendWhatsAppHotLeads' => ['Em dia no WhatsApp: hot leads', '*/10 * * * *'],
    ];

    public function __construct(private EntityManager $entityManager) {}

    public function process(): void
    {
        foreach (self::JOBS as $job => [$name, $scheduling]) {
            if ($this->entityManager->getRDBRepository('ScheduledJob')->where(['job' => $job])->findOne()) continue;
            $this->entityManager->createEntity('ScheduledJob', [
                'name' => $name, 'job' => $job, 'status' => 'Active', 'scheduling' => $scheduling,
            ], [SaveOption::SKIP_ALL => true]);
        }
    }
}
