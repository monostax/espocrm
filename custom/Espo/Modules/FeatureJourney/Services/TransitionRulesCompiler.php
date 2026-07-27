<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Modules\FeatureJourney\Entities\JourneyTransition;
use Espo\ORM\Entity;

/**
 * Derives engine wakeSources / eventCodes / waitPeriod from conditionsGroup.
 * Keeps multi-wake power while the UI only edits rules.
 */
class TransitionRulesCompiler
{
    /**
     * @return array{
     *   wakeSources: list<string>,
     *   eventCodes: list<string>,
     *   waitPeriod: ?string,
     *   triggerType: string
     * }
     */
    public function compile(Entity $transition): array
    {
        $group = $transition->get('conditionsGroup');

        if ($group instanceof \stdClass) {
            $group = json_decode(json_encode($group) ?: '{}', true);
        }

        $wakes = [];
        $codes = [];
        $periods = [];

        if (is_array($group) && $group !== []) {
            $this->walk($group, $wakes, $codes, $periods);
        }

        // Preserve manual / entityChange from UI toggles (already on entity or BC)
        $existingWakes = JourneyTransition::resolveWakeSources($transition);

        foreach ([JourneyTransition::TRIGGER_MANUAL, JourneyTransition::TRIGGER_ENTITY_CHANGE] as $extra) {
            if (in_array($extra, $existingWakes, true)) {
                $this->addWake($wakes, $extra);
            }
        }

        $waitPeriod = null;

        if ($periods !== []) {
            $waitPeriod = $periods[0];

            foreach ($periods as $p) {
                $waitPeriod = $this->pickShorterPeriod((string) $waitPeriod, $p);
            }
        } elseif (in_array(JourneyTransition::TRIGGER_TIMER, $wakes, true)) {
            $existingWait = $transition->get('waitPeriod');
            if (is_string($existingWait) && $existingWait !== '') {
                $waitPeriod = $existingWait;
            }
        }

        // Legacy empty conditions: keep engine fields as-is for wakes
        if ($wakes === [] && $this->isEmptyGroup($group)) {
            $wakes = $existingWakes;
            $codes = $this->normalizeCodes($transition->get('eventCodes'));
            $w = $transition->get('waitPeriod');
            $waitPeriod = is_string($w) && $w !== '' ? $w : null;
        }

        $triggerType = JourneyTransition::primaryTriggerFromWakes(
            $wakes,
            is_string($transition->get('triggerType')) ? (string) $transition->get('triggerType') : null
        );

        return [
            'wakeSources' => $wakes,
            'eventCodes' => $codes,
            'waitPeriod' => $waitPeriod,
            'triggerType' => $triggerType,
        ];
    }

    public function applyToEntity(Entity $transition): void
    {
        $compiled = $this->compile($transition);
        $group = $transition->get('conditionsGroup');
        $treeEmpty = $this->isEmptyGroup(
            $group instanceof \stdClass
                ? json_decode(json_encode($group) ?: '{}', true)
                : $group
        );

        // When the tree has rules, derive engine fields from it.
        if (!$treeEmpty) {
            if ($compiled['wakeSources'] !== []) {
                $transition->set('wakeSources', $compiled['wakeSources']);
                $transition->set('triggerType', $compiled['triggerType']);
            }

            // Rule-driven event codes always win (even if empty when no event leaves)
            $transition->set('eventCodes', $compiled['eventCodes']);

            if (in_array(JourneyTransition::TRIGGER_TIMER, $compiled['wakeSources'], true)) {
                if ($compiled['waitPeriod'] !== null) {
                    $transition->set('waitPeriod', $compiled['waitPeriod']);
                }
            } else {
                $transition->set('waitPeriod', null);
            }

            return;
        }

        // Empty tree: honor explicit wakeSources if set (toggles-only / legacy)
        if ($compiled['wakeSources'] !== []) {
            $transition->set('wakeSources', $compiled['wakeSources']);
            $transition->set('triggerType', $compiled['triggerType']);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $wakes
     * @param list<string> $codes
     * @param list<string> $periods
     */
    private function walk(array $node, array &$wakes, array &$codes, array &$periods): void
    {
        if (isset($node['and']) && is_array($node['and'])) {
            foreach ($node['and'] as $child) {
                if (is_array($child)) {
                    $this->walk($child, $wakes, $codes, $periods);
                }
            }

            return;
        }

        if (isset($node['or']) && is_array($node['or'])) {
            foreach ($node['or'] as $child) {
                if (is_array($child)) {
                    $this->walk($child, $wakes, $codes, $periods);
                }
            }

            return;
        }

        if (isset($node['not']) && is_array($node['not'])) {
            $this->walk($node['not'], $wakes, $codes, $periods);

            return;
        }

        $type = $node['type'] ?? null;

        if ($type === 'eventHistory' || $type === 'currentSignal' || $type === 'anySignal') {
            $this->addWake($wakes, JourneyTransition::TRIGGER_SIGNAL);
            $this->collectCodes($node, $codes);

            return;
        }

        if ($type === 'payloadPath') {
            $this->addWake($wakes, JourneyTransition::TRIGGER_SIGNAL);

            return;
        }

        if ($type === 'elapsedInStage') {
            $this->addWake($wakes, JourneyTransition::TRIGGER_TIMER);
            $p = $node['period'] ?? $node['waitPeriod'] ?? null;

            if (is_string($p) && trim($p) !== '') {
                $periods[] = trim($p);
            }

            return;
        }

        if ($type === 'entityFilter' || $type === 'onRecordChange') {
            $this->addWake($wakes, JourneyTransition::TRIGGER_ENTITY_CHANGE);

            return;
        }

        if ($type === 'manual') {
            $this->addWake($wakes, JourneyTransition::TRIGGER_MANUAL);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $codes
     */
    private function collectCodes(array $node, array &$codes): void
    {
        $code = $node['code'] ?? null;

        if (is_string($code) && $code !== '' && !in_array($code, $codes, true)) {
            $codes[] = $code;
        }

        $list = $node['codes'] ?? null;

        if (is_array($list)) {
            foreach ($list as $c) {
                if (is_string($c) && $c !== '' && !in_array($c, $codes, true)) {
                    $codes[] = $c;
                }
            }
        }
    }

    /**
     * @param list<string> $wakes
     */
    private function addWake(array &$wakes, string $w): void
    {
        if (!in_array($w, $wakes, true)) {
            $wakes[] = $w;
        }
    }

    private function isEmptyGroup(mixed $group): bool
    {
        if ($group === null || $group === '' || $group === []) {
            return true;
        }

        if ($group instanceof \stdClass) {
            $group = json_decode(json_encode($group) ?: '{}', true);
        }

        if (!is_array($group)) {
            return true;
        }

        if (isset($group['and']) && is_array($group['and'])) {
            return $group['and'] === [];
        }

        if (isset($group['or']) && is_array($group['or'])) {
            return $group['or'] === [];
        }

        return $group === [];
    }

    /**
     * @return list<string>
     */
    private function normalizeCodes(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $c) {
            if (is_string($c) && $c !== '' && !in_array($c, $out, true)) {
                $out[] = $c;
            }
        }

        return $out;
    }

    private function pickShorterPeriod(string $a, string $b): string
    {
        $sa = $this->scorePeriod($a);
        $sb = $this->scorePeriod($b);

        if ($sa === null && $sb === null) {
            return $a;
        }

        if ($sa === null) {
            return $b;
        }

        if ($sb === null) {
            return $a;
        }

        return $sa <= $sb ? $a : $b;
    }

    private function scorePeriod(string $period): ?int
    {
        // Delegated to PeriodParser so English, pt-BR and ISO-8601 inputs all score the
        // same way. This used to carry its own regex, which rejected values the rest of
        // the stack accepted.
        return (new PeriodParser())->toSeconds($period);
    }
}
