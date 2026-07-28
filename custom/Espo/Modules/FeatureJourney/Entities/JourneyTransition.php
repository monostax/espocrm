<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Entities;

use Espo\Core\ORM\Entity;

class JourneyTransition extends Entity
{
    public const ENTITY_TYPE = 'JourneyTransition';

    public const TRIGGER_SIGNAL = 'signal';
    public const TRIGGER_TIMER = 'timer';
    public const TRIGGER_ENTITY_CHANGE = 'entityChange';
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_FORMULA = 'formula';

    public const SCOPE_STAGE = 'stage';
    public const SCOPE_JOURNEY = 'journey';
    public const SCOPE_ENROLLMENT = 'enrollment';

    /** @var list<string> */
    public const SCOPES = [
        self::SCOPE_STAGE,
        self::SCOPE_JOURNEY,
        self::SCOPE_ENROLLMENT,
    ];

    /** @var list<string> */
    public const WAKE_SOURCES = [
        self::TRIGGER_SIGNAL,
        self::TRIGGER_TIMER,
        self::TRIGGER_ENTITY_CHANGE,
        self::TRIGGER_MANUAL,
    ];

    /**
     * Wake sources that can queue this transition (OR).
     * BC: empty wakeSources → fall back to single triggerType (except formula).
     *
     * @return list<string>
     */
    public function getWakeSources(): array
    {
        $raw = $this->get('wakeSources');

        if (is_array($raw) && $raw !== []) {
            $out = [];

            foreach ($raw as $item) {
                if (!is_string($item) || $item === '') {
                    continue;
                }

                if (!in_array($item, self::WAKE_SOURCES, true)) {
                    continue;
                }

                if (!in_array($item, $out, true)) {
                    $out[] = $item;
                }
            }

            if ($out !== []) {
                return $out;
            }
        }

        $trigger = $this->get('triggerType');

        if (is_string($trigger) && in_array($trigger, self::WAKE_SOURCES, true)) {
            return [$trigger];
        }

        return [];
    }

    public function wakesOn(string $source): bool
    {
        return in_array($source, $this->getWakeSources(), true);
    }

    /**
     * Preferential primary for list/BC triggerType column.
     */
    public static function primaryTriggerFromWakes(array $wakes, ?string $fallback = null): string
    {
        $order = self::WAKE_SOURCES;

        foreach ($order as $source) {
            if (in_array($source, $wakes, true)) {
                return $source;
            }
        }

        if (is_string($fallback) && $fallback !== '') {
            return $fallback;
        }

        return self::TRIGGER_SIGNAL;
    }

    /**
     * Wake resolution for any ORM entity row (typed or plain).
     *
     * @return list<string>
     */
    public static function resolveWakeSources(Entity $entity): array
    {
        if ($entity instanceof self) {
            return $entity->getWakeSources();
        }

        $raw = $entity->get('wakeSources');
        $out = [];

        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (!is_string($item) || $item === '') {
                    continue;
                }

                if (!in_array($item, self::WAKE_SOURCES, true)) {
                    continue;
                }

                if (!in_array($item, $out, true)) {
                    $out[] = $item;
                }
            }
        }

        if ($out !== []) {
            return $out;
        }

        $trigger = $entity->get('triggerType');

        if (is_string($trigger) && in_array($trigger, self::WAKE_SOURCES, true)) {
            return [$trigger];
        }

        return [];
    }

    public static function entityWakesOn(Entity $entity, string $source): bool
    {
        return in_array($source, self::resolveWakeSources($entity), true);
    }

    /**
     * Rows created before the scope field existed remain deterministic until rebuild backfills them.
     */
    public static function resolveScope(Entity $entity): string
    {
        if ($entity->get('fromStageId')) {
            return self::SCOPE_STAGE;
        }

        $scope = $entity->get('scope');

        if (is_string($scope) && in_array($scope, self::SCOPES, true)) {
            return $scope;
        }

        return self::SCOPE_ENROLLMENT;
    }

    public static function appliesToStage(Entity $entity, ?string $stageId): bool
    {
        $scope = self::resolveScope($entity);

        if ($scope === self::SCOPE_JOURNEY) {
            return $stageId !== null && $stageId !== '';
        }

        if ($scope !== self::SCOPE_STAGE) {
            return false;
        }

        $fromStageId = $entity->get('fromStageId');

        return $stageId !== null && $stageId !== '' && (string) $fromStageId === $stageId;
    }

    /**
     * Lower priority wins; a stage-specific edge wins a tie over a journey-wide edge.
     */
    public static function compareForRecord(Entity $a, Entity $b): int
    {
        $priority = (int) ($a->get('priority') ?? 10) <=> (int) ($b->get('priority') ?? 10);

        if ($priority !== 0) {
            return $priority;
        }

        $specificity = (self::resolveScope($a) === self::SCOPE_STAGE ? 0 : 1)
            <=> (self::resolveScope($b) === self::SCOPE_STAGE ? 0 : 1);

        if ($specificity !== 0) {
            return $specificity;
        }

        return strcmp((string) $a->getId(), (string) $b->getId());
    }
}
