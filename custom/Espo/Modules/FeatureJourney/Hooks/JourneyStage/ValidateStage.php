<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Hooks\JourneyStage;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\Modules\FeatureJourney\Entities\JourneyStage;
use Espo\Modules\FeatureJourney\Services\PeriodParser;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<JourneyStage> */
class ValidateStage implements BeforeSave
{
    public static int $order = 15;

    public function __construct(
        private EntityManager $entityManager,
        private PeriodParser $periodParser,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof JourneyStage) {
            return;
        }

        $journeyId = $entity->get('journeyId');
        if (!$journeyId) {
            return;
        }

        $this->assertParentEditable((string) $journeyId);

        // maxDuration had no format validation at all: an unparseable value was stored
        // and the SLA then silently never applied.
        $max = $entity->get('maxDuration');
        if (is_string($max) && trim($max) !== '') {
            $normalised = $this->periodParser->normalise($max);

            if ($normalised === null && !$this->periodParser->isValid($max)) {
                throw new BadRequest(
                    "maxDuration '{$max}' is not a valid period. " .
                    'Use a format like “3 days” / “3 dias”, or an ISO-8601 duration like “PT30M”.'
                );
            }

            if ($normalised !== null && $normalised !== $max) {
                $entity->set('maxDuration', $normalised);
            }
        }

        if ($entity->get('stageType') === JourneyStage::TYPE_ENTRY) {
            $where = [
                'journeyId' => $journeyId,
                'stageType' => JourneyStage::TYPE_ENTRY,
            ];
            if (!$entity->isNew() && $entity->getId()) {
                $where['id!='] = $entity->getId();
            }

            $other = $this->entityManager
                ->getRDBRepository(JourneyStage::ENTITY_TYPE)
                ->where($where)
                ->findOne();

            if ($other) {
                throw new BadRequest('A journey can have only one Entry stage.');
            }
        }

        if (!$entity->isNew() && $entity->isAttributeChanged('isActive') && !$entity->get('isActive')) {
            $active = $this->entityManager
                ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
                ->where([
                    'currentStageId' => $entity->getId(),
                    'status' => [
                        JourneyRecord::STATUS_ACTIVE,
                        JourneyRecord::STATUS_PROCESSING,
                        JourneyRecord::STATUS_PAUSED,
                    ],
                ])
                ->findOne();

            if ($active) {
                throw new BadRequest('Cannot deactivate a stage that has active journey records.');
            }
        }
    }

    private function assertParentEditable(string $journeyId): void
    {
        $journey = $this->entityManager->getEntityById(Journey::ENTITY_TYPE, $journeyId);
        if (!$journey) {
            return;
        }

        $status = $journey->get('status');
        if (!in_array($status, [Journey::STATUS_DRAFT, Journey::STATUS_PAUSED], true)) {
            throw new BadRequest("Cannot modify stages while journey status is {$status}.");
        }
    }
}
