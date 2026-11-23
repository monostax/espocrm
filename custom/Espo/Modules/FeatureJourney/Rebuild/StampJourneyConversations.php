<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Services\JourneyConversations;
use Throwable;

/** Stamps live enrollments on the conversations they sent to that the CRM copied in after the send (JourneyConversations). */
class StampJourneyConversations implements RebuildAction
{
    public function __construct(private JourneyConversations $conversations, private Log $log) {}

    public function process(): void
    {
        try {
            $stamped = $this->conversations->backfill();
        } catch (Throwable $e) {
            $this->log->warning('FeatureJourney: stamping journey conversations failed: ' . $e->getMessage());

            return;
        }

        if ($stamped) {
            $this->log->info('FeatureJourney: stamped {count} conversations with their live enrollment.', ['count' => $stamped]);
        }
    }
}
