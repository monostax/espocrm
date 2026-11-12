<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Pushes;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Settings;
use Espo\ORM\EntityManager;
use Throwable;

/** Every 10 minutes: hot-lead alerts for each active subscription with them on (Pushes::hotLeads). */
class SendHotLeads implements JobDataLess
{
    public function __construct(private EntityManager $em, private Settings $settings, private Pushes $pushes, private Log $log) {}

    public function run(): void
    {
        if (!$this->settings->enabled() || $this->settings->hotLeadDailyCap() === 0) return;
        $subscriptions = $this->em->getRDBRepository('WhatsAppNotificationSubscription')
            ->where(['status' => 'active', 'hotLeadsEnabled' => true])->find();
        foreach ($subscriptions as $subscription) {
            try {
                $this->pushes->hotLeads($subscription);
            } catch (Throwable $e) {
                $this->log->warning("WhatsApp notifications: hot leads for {$subscription->getId()} failed: " . $e->getMessage());
            }
        }
    }
}
