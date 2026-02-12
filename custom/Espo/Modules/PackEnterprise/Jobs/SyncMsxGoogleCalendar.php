<?php

namespace Espo\Modules\PackEnterprise\Jobs;

use Espo\Core\InjectableFactory;
use Espo\Core\Job\JobDataLess;
use Espo\Modules\PackEnterprise\Services\MsxGoogleCalendar;
use Espo\ORM\EntityManager;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Scheduled job that syncs Google Calendar for all active MsxGoogleCalendarUser records.
 * Replaces the Google module's SynchronizeEventsWithGoogleCalendar job.
 * Does NOT depend on Integration/ExternalAccount entities.
 */
class SyncMsxGoogleCalendar implements JobDataLess
{
    private EntityManager $entityManager;
    private InjectableFactory $injectableFactory;
    private LoggerInterface $log;

    public function __construct(
        EntityManager $entityManager,
        InjectableFactory $injectableFactory,
        LoggerInterface $log
    ) {
        $this->entityManager = $entityManager;
        $this->injectableFactory = $injectableFactory;
        $this->log = $log;
    }

    public function run(): void
    {
        $this->log->debug('MsxGoogleCalendar [Job]: START scheduled sync job');

        $service = $this->injectableFactory->create(MsxGoogleCalendar::class);

        $collection = $this->entityManager
            ->getRDBRepository('MsxGoogleCalendarUser')
            ->where([
                'active' => true,
            ])
            ->order('lastLooked')
            ->find();

        $count = count($collection);
        $this->log->debug("MsxGoogleCalendar [Job]: Found {$count} active MsxGoogleCalendarUser records");

        foreach ($collection as $calendarUser) {
            $this->log->debug("MsxGoogleCalendar [Job]: Syncing MsxGoogleCalendarUser id=" . $calendarUser->get('id') .
                ", userId=" . $calendarUser->get('userId'));

            try {
                $service->syncCalendar($calendarUser);
            } catch (Exception $e) {
                $this->log->error(
                    'MsxGoogleCalendar: Sync Error for MsxGoogleCalendarUser ' .
                    $calendarUser->get('id') . ': ' . $e->getMessage()
                );
            }
        }

        $this->log->debug('MsxGoogleCalendar [Job]: END scheduled sync job');
    }
}
