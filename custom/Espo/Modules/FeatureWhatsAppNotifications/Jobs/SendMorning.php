<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Pushes;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Settings;
use Espo\ORM\EntityManager;
use Throwable;

/** Every 5 minutes: the day's card for each active subscription whose push time (08:00, 11:00, 15:00) has come (Pushes::morning). */
class SendMorning implements JobDataLess
{
    public function __construct(private EntityManager $em, private Settings $settings, private Pushes $pushes, private Log $log) {}

    public function run(): void
    {
        if (!$this->settings->enabled()) return;
        $subscriptions = $this->em->getRDBRepository('WhatsAppNotificationSubscription')
            ->where(['status' => 'active', 'morningEnabled' => true])->find();
        foreach ($subscriptions as $subscription) {
            try {
                $this->pushes->morning($subscription);
            } catch (Throwable $e) {
                $this->log->warning("WhatsApp notifications: morning push for {$subscription->getId()} failed: " . $e->getMessage());
            }
        }
    }
}
