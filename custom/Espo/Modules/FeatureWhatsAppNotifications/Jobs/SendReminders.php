<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureWhatsAppNotifications\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Pushes;
use Espo\Modules\FeatureWhatsAppNotifications\Tools\Settings;
use Espo\ORM\EntityManager;
use Throwable;

/** Every 5 minutes: cards put off with "Me lembre mais tarde" whose time has come (Pushes::remind). */
class SendReminders implements JobDataLess
{
    private const BATCH = 200;

    public function __construct(private EntityManager $em, private Settings $settings, private Pushes $pushes, private Log $log) {}

    public function run(): void
    {
        if (!$this->settings->enabled()) return;
        $cards = $this->em->getRDBRepository('WhatsAppDeckCard')->where([
            'status' => 'snoozed', 'remindedAt' => null, 'remindAt<=' => gmdate('Y-m-d H:i:s'),
        ])->order('remindAt')->limit(0, self::BATCH)->find();
        foreach ($cards as $card) {
            try {
                $this->pushes->remind($card);
            } catch (Throwable $e) {
                $this->log->warning("WhatsApp notifications: reminder for card {$card->getId()} failed: " . $e->getMessage());
            }
        }
    }
}
