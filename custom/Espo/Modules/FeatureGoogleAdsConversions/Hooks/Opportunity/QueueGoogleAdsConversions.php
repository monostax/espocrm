<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Hooks\Opportunity;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Log;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionMapping;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionUpload;
use Espo\Modules\FeatureGoogleAdsConversions\Services\GoogleAdsDispatcher;
use Espo\Modules\FeatureGoogleAdsConversions\Services\GoogleAdsUploadFactory;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * Materializes conversion uploads only when an Opportunity enters a mapped stage.
 *
 * @implements AfterSave<Entity>
 */
class QueueGoogleAdsConversions implements AfterSave
{
    public static int $order = 30;

    public function __construct(
        private EntityManager $entityManager,
        private GoogleAdsUploadFactory $uploadFactory,
        private GoogleAdsDispatcher $dispatcher,
        private Log $log,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof Opportunity) {
            return;
        }

        $stageToId = $entity->get('opportunityStageId');
        $stageFromId = $entity->getFetched('opportunityStageId');
        $isNew = $entity->isNew();

        if (
            !is_string($stageToId) ||
            $stageToId === '' ||
            (!$isNew && $stageFromId === $stageToId)
        ) {
            return;
        }

        $funnelId = $entity->get('funnelId');
        $tenantId = $entity->get('tenantId');

        if (!is_string($funnelId) || $funnelId === '' || !is_string($tenantId) || $tenantId === '') {
            return;
        }

        $mappings = $this->entityManager
            ->getRDBRepository(GoogleAdsConversionMapping::ENTITY_TYPE)
            ->where([
                'funnelId' => $funnelId,
                'opportunityStageId' => $stageToId,
                'tenantId' => $tenantId,
                'isActive' => true,
                'deleted' => false,
            ])
            ->find();
        $eventTime = $this->eventTime($entity);

        foreach ($mappings as $mapping) {
            if (
                !$mapping instanceof GoogleAdsConversionMapping ||
                ($isNew && !$mapping->get('includeOnCreate'))
            ) {
                continue;
            }

            $upload = null;

            try {
                $upload = $this->uploadFactory->createForOpportunity(
                    $entity,
                    $mapping,
                    is_string($stageFromId) && $stageFromId !== '' ? $stageFromId : null,
                    $stageToId,
                    $eventTime,
                );

                if (!$upload || $upload->get('status') !== GoogleAdsConversionUpload::STATUS_PENDING) {
                    continue;
                }

                $upload->set(
                    'nextAttemptAt',
                    (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                        ->add(new DateInterval('PT15M'))
                        ->format('Y-m-d H:i:s'),
                );
                $this->entityManager->saveEntity(
                    $upload,
                    ['skipHooks' => true, 'silent' => true],
                );
                $this->dispatcher->schedule($upload->getId());
            } catch (Throwable $e) {
                if (
                    $upload instanceof GoogleAdsConversionUpload &&
                    $upload->get('status') === GoogleAdsConversionUpload::STATUS_PENDING
                ) {
                    $upload->set(
                        'nextAttemptAt',
                        (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                            ->format('Y-m-d H:i:s'),
                    );

                    try {
                        $this->entityManager->saveEntity(
                            $upload,
                            ['skipHooks' => true, 'silent' => true],
                        );
                    } catch (Throwable) {
                    }
                }

                $this->log->error(
                    'Google Ads stage-entry queue failed for opportunity=' . $entity->getId() .
                    ' mapping=' . $mapping->getId() .
                    ' errorClass=' . $e::class,
                );
            }
        }
    }

    private function eventTime(Opportunity $opportunity): DateTimeImmutable
    {
        $raw = $opportunity->get('modifiedAt') ?: $opportunity->get('createdAt');
        $timezone = new DateTimeZone('UTC');

        if (is_string($raw) && $raw !== '') {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, $timezone);

            if ($parsed instanceof DateTimeImmutable) {
                return $parsed;
            }
        }

        return new DateTimeImmutable('now', $timezone);
    }
}
