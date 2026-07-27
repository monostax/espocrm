<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Hooks\JourneyTransition;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyStage;
use Espo\Modules\FeatureJourney\Entities\JourneyTransition;
use Espo\Modules\FeatureJourney\Services\PeriodParser;
use Espo\Modules\FeatureJourney\Services\TransitionRulesCompiler;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<JourneyTransition> */
class ValidateTransition implements BeforeSave
{
    public static int $order = 15;

    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private Metadata $metadata,
        private TransitionRulesCompiler $rulesCompiler,
        private PeriodParser $periodParser,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof JourneyTransition) {
            return;
        }

        $journeyId = $entity->get('journeyId');
        if (!$journeyId) {
            throw new BadRequest('journey is required.');
        }

        $journey = $this->entityManager->getEntityById(Journey::ENTITY_TYPE, (string) $journeyId);
        if (!$journey) {
            throw new BadRequest('Journey not found.');
        }

        $status = $journey->get('status');
        if (!in_array($status, [Journey::STATUS_DRAFT, Journey::STATUS_PAUSED], true)) {
            throw new BadRequest("Cannot modify transitions while journey status is {$status}.");
        }

        $toStageId = $entity->get('toStageId');
        if (!$toStageId) {
            throw new BadRequest('toStage is required.');
        }

        $toStage = $this->entityManager->getEntityById(JourneyStage::ENTITY_TYPE, (string) $toStageId);
        if (!$toStage || $toStage->get('journeyId') !== $journeyId) {
            throw new BadRequest('toStage must belong to the same journey.');
        }

        $fromStageId = $entity->get('fromStageId');
        if ($fromStageId) {
            $fromStage = $this->entityManager->getEntityById(JourneyStage::ENTITY_TYPE, (string) $fromStageId);
            if (!$fromStage || $fromStage->get('journeyId') !== $journeyId) {
                throw new BadRequest('fromStage must belong to the same journey.');
            }
        }

        // Derive engine fields from the rules tree (wakes / codes / wait)
        $this->rulesCompiler->applyToEntity($entity);
        $this->normalizeWakes($entity);

        $wakes = $entity instanceof JourneyTransition
            ? $entity->getWakeSources()
            : JourneyTransition::resolveWakeSources($entity);
        $trigger = (string) ($entity->get('triggerType') ?? '');

        if ($wakes === [] && $trigger === JourneyTransition::TRIGGER_FORMULA) {
            $formula = $entity->get('conditionsFormula');
            if (!is_string($formula) || trim($formula) === '') {
                throw new BadRequest('conditionsFormula is required for formula transitions.');
            }
        } elseif ($wakes === []) {
            throw new BadRequest(
                'Add at least one rule (event, time in step, person filter) or allow manual / record-change advance.'
            );
        }

        if (in_array(JourneyTransition::TRIGGER_SIGNAL, $wakes, true)) {
            $codes = $entity->get('eventCodes') ?: [];
            if (!is_array($codes) || $codes === []) {
                throw new BadRequest(
                    'Add an event rule (past event or this event) so the connection knows which events to listen for.'
                );
            }

            $clean = [];
            foreach ($codes as $code) {
                if (is_string($code) && $code !== '' && !in_array($code, $clean, true)) {
                    $clean[] = $code;
                }
            }

            if ($clean === []) {
                throw new BadRequest(
                    'Add an event rule (past event or this event) so the connection knows which events to listen for.'
                );
            }

            $entity->set('eventCodes', $clean);
        }

        if (in_array(JourneyTransition::TRIGGER_TIMER, $wakes, true)) {
            if (!$entity->get('waitPeriod')) {
                throw new BadRequest(
                    'Add a “time in this step” rule (e.g. 3 days) so the timer knows when to re-check.'
                );
            }
        }

        // Format check, not just presence. An unparseable waitPeriod is accepted by the
        // DB (plain varchar) and then makes the timer silently never fire.
        $wait = $entity->get('waitPeriod');
        if (is_string($wait) && trim($wait) !== '') {
            $normalised = $this->periodParser->normalise($wait);

            if ($normalised === null && !$this->periodParser->isValid($wait)) {
                throw new BadRequest(
                    "waitPeriod '{$wait}' is not a valid period. " .
                    'Use a format like “3 days” / “3 dias”, or an ISO-8601 duration like “PT30M”.'
                );
            }

            // Persist canonical English so the compiler and the job agree on one format.
            if ($normalised !== null && $normalised !== $wait) {
                $entity->set('waitPeriod', $normalised);
            }
        }

        $evaluatorClass = $entity->get('evaluatorClassName');
        if (is_string($evaluatorClass) && $evaluatorClass !== '') {
            if (!$this->user->isAdmin()) {
                throw new Forbidden('evaluatorClassName is platform-tier (superadmin only).');
            }

            if (!class_exists($evaluatorClass)) {
                throw new BadRequest("evaluatorClassName '{$evaluatorClass}' not found.");
            }

            /** @var list<string>|null $allow */
            $allow = $this->metadata->get(['app', 'journeyPlatformAllowList', 'evaluatorClassNameList']);
            if (!is_array($allow) || !in_array($evaluatorClass, $allow, true)) {
                throw new BadRequest(
                    "evaluatorClassName '{$evaluatorClass}' is not in app.journeyPlatformAllowList.evaluatorClassNameList."
                );
            }
        }
    }

    private function normalizeWakes(JourneyTransition $entity): void
    {
        $raw = $entity->get('wakeSources');
        $wakes = [];

        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (!is_string($item) || $item === '') {
                    continue;
                }

                if ($item === JourneyTransition::TRIGGER_FORMULA) {
                    continue;
                }

                if (!in_array($item, JourneyTransition::WAKE_SOURCES, true)) {
                    continue;
                }

                if (!in_array($item, $wakes, true)) {
                    $wakes[] = $item;
                }
            }
        }

        $trigger = $entity->get('triggerType');

        if ($wakes === [] && is_string($trigger) && in_array($trigger, JourneyTransition::WAKE_SOURCES, true)) {
            $wakes = [$trigger];
        }

        if ($wakes !== []) {
            $entity->set('wakeSources', $wakes);
            $entity->set(
                'triggerType',
                JourneyTransition::primaryTriggerFromWakes(
                    $wakes,
                    is_string($trigger) ? $trigger : null
                )
            );

            return;
        }

        if (is_string($trigger) && $trigger === JourneyTransition::TRIGGER_FORMULA) {
            $entity->set('wakeSources', []);
        }
    }
}
