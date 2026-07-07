<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEvent;
use Espo\ORM\EntityManager;

/**
 * Backfills anonymous browsing history onto a Contact once the visitor
 * identifies (the Mixpanel "alias/merge" model).
 *
 * Scheduled by TrackingEventIngester whenever an event resolves BOTH a
 * Contact and an anonymousId. Job data:
 *   contactId   — resolved Contact id.
 *   anonymousId — the visitor id whose history should be rewired.
 *   tenantId    — tenant scope; rows from other tenants are never touched.
 *
 * For every prior TrackingEvent in the tenant that carries this anonymousId
 * and no contact yet:
 *   - contact is set,
 *   - anonymousId is cleared (per entityDefs contract),
 *   - status flips Received → Stitched.
 *
 * Idempotent: the query filters contactId=null, so redelivered/duplicate
 * jobs (job groups serialize but do NOT dedupe) find nothing to do.
 * Saves are silent+skipHooks — rows already carry tenant/teams, and the
 * cascade hooks early-return on silent anyway.
 *
 * Job group `tracking-stitch-{anonymousId}` serializes concurrent identify
 * bursts for the same visitor.
 */
class AnonymousStitcher implements Job
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $contactId = $data->get('contactId');
        $anonymousId = $data->get('anonymousId');
        $tenantId = $data->get('tenantId');

        if (
            !is_string($contactId) || $contactId === '' ||
            !is_string($anonymousId) || $anonymousId === '' ||
            !is_string($tenantId) || $tenantId === ''
        ) {
            $this->log->warning('TrackingEvent AnonymousStitcher: missing contactId/anonymousId/tenantId in job data.');

            return;
        }

        $stitched = 0;

        // Loop in batches: each pass re-queries because stitched rows drop
        // out of the filter (contactId gets set), so no offset bookkeeping.
        while (true) {
            $events = $this->entityManager
                ->getRDBRepository(TrackingEvent::ENTITY_TYPE)
                ->where([
                    'anonymousId' => $anonymousId,
                    'tenantId' => $tenantId,
                    'contactId' => null,
                    'deleted' => false,
                ])
                ->limit(0, self::BATCH_SIZE)
                ->find();

            $count = 0;

            foreach ($events as $event) {
                $event->set('contactId', $contactId);
                $event->set('anonymousId', null);
                $event->set('status', TrackingEvent::STATUS_STITCHED);

                $this->entityManager->saveEntity($event, ['skipHooks' => true, 'silent' => true]);

                $count++;
            }

            $stitched += $count;

            if ($count < self::BATCH_SIZE) {
                break;
            }
        }

        if ($stitched > 0) {
            $this->log->info(
                "TrackingEvent AnonymousStitcher: stitched {$stitched} event(s) " .
                "anonymousId={$anonymousId} -> contact={$contactId} (tenant={$tenantId})."
            );
        }
    }
}
