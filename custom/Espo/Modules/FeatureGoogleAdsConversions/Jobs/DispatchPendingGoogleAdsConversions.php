<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Jobs;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionUpload;
use Espo\Modules\FeatureGoogleAdsConversions\Services\GoogleAdsDispatcher;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Recovers pending, due retry, and abandoned processing upload rows.
 */
class DispatchPendingGoogleAdsConversions implements JobDataLess
{
    private const BATCH_SIZE = 100;
    private const PROCESSING_TIMEOUT_MINUTES = 15;
    private const QUEUE_LEASE_MINUTES = 15;

    public function __construct(
        private EntityManager $entityManager,
        private GoogleAdsDispatcher $dispatcher,
        private Log $log,
    ) {}

    public function run(): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowString = $now->format('Y-m-d H:i:s');
        $staleString = $now
            ->sub(new DateInterval('PT' . self::PROCESSING_TIMEOUT_MINUTES . 'M'))
            ->format('Y-m-d H:i:s');
        $uploads = $this->entityManager
            ->getRDBRepository(GoogleAdsConversionUpload::ENTITY_TYPE)
            ->where([
                'deleted' => false,
                'attemptCount<' => GoogleAdsDispatcher::MAX_ATTEMPTS,
                'OR' => [
                    [
                        'status' => GoogleAdsConversionUpload::STATUS_PENDING,
                        'OR' => [
                            ['nextAttemptAt' => null],
                            ['nextAttemptAt<=' => $nowString],
                        ],
                    ],
                    [
                        'status' => GoogleAdsConversionUpload::STATUS_RETRY_SCHEDULED,
                        'nextAttemptAt<=' => $nowString,
                    ],
                    [
                        'status' => GoogleAdsConversionUpload::STATUS_PROCESSING,
                        'lastAttemptAt<=' => $staleString,
                    ],
                ],
            ])
            ->order('createdAt', 'ASC')
            ->limit(0, self::BATCH_SIZE)
            ->find();
        $leaseUntil = $now
            ->add(new DateInterval('PT' . self::QUEUE_LEASE_MINUTES . 'M'))
            ->format('Y-m-d H:i:s');

        foreach ($uploads as $upload) {
            if (!$upload instanceof GoogleAdsConversionUpload) {
                continue;
            }

            try {
                if ($upload->get('status') !== GoogleAdsConversionUpload::STATUS_PENDING) {
                    $upload->set('status', GoogleAdsConversionUpload::STATUS_PENDING);
                }

                // Prevent this minute sweeper from queueing the same row again
                // while the grouped worker is waiting or running.
                $upload->set('nextAttemptAt', $leaseUntil);
                $this->entityManager->saveEntity(
                    $upload,
                    ['skipHooks' => true, 'silent' => true],
                );

                $this->dispatcher->schedule($upload->getId());
            } catch (Throwable) {
                // Release the lease so the next sweep can try again.
                $upload->set('nextAttemptAt', $nowString);

                try {
                    $this->entityManager->saveEntity(
                        $upload,
                        ['skipHooks' => true, 'silent' => true],
                    );
                } catch (Throwable) {
                }

                $this->log->error(
                    'Google Ads sweeper failed to queue upload=' . $upload->getId(),
                );
            }
        }
    }
}
