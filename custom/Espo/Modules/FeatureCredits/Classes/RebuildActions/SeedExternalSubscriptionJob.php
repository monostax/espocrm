<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Classes\RebuildActions;

use Espo\Core\Rebuild\RebuildAction;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOption;

/** The ScheduledJob row for FundExternalSubscriptions; the other credit jobs were created by hand. */
final class SeedExternalSubscriptionJob implements RebuildAction
{
    public function __construct(private EntityManager $entityManager) {}

    public function process(): void
    {
        if ($this->entityManager->getRDBRepository('ScheduledJob')->where(['job' => 'FundExternalSubscriptions'])->findOne()) return;
        $this->entityManager->createEntity('ScheduledJob', [
            'name' => 'Fund Linked Asaas Subscriptions', 'job' => 'FundExternalSubscriptions',
            'status' => 'Active', 'scheduling' => '*/15 * * * *',
        ], [SaveOption::SKIP_ALL => true]);
    }
}
