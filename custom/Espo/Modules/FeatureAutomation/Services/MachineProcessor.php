<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeatureJourney\Services\PeriodParser;
use Espo\Modules\FeatureJourney\Services\RestrictedFormulaRunner;
use Espo\ORM\Entity;
use Throwable;

/**
 * Advances Machine automation items: onEnter/onExit, when-gated transitions,
 * waitPeriod / waitUntil on state or transition, join fan-in, final termination.
 *
 * Payload keys:
 *  - _state, _enteredStateAt, _wakeAt, _pendingTo, _onEnterDone, _machineLog, _machineSteps
 *  - _pendingJoins, _joinMode, _joinSatisfied (fan-in)
 */
class MachineProcessor
{
    private const MAX_STEPS = 40;

    public function __construct(
        private AutomationActionRunner $actionRunner,
        private JoinCoordinator $joinCoordinator,
        private PeriodParser $periodParser,
        private WakeAtResolver $wakeAtResolver,
        private RestrictedFormulaRunner $formulaRunner,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $def
     * @param array<string, mixed> $payload
     * @return array{
     *   status: string,
     *   payload: array<string, mixed>,
     *   log: list<array<string, mixed>>,
     *   error?: string
     * }
     */
    public function process(
        array $def,
        Entity $automation,
        Entity $run,
        Entity $item,
        Entity $target,
        string $tenantId,
        array $payload,
        ?User $actor = null,
    ): array {
        return $this->advance($def, $automation, $run, $item, $target, $tenantId, $payload, false, $actor);
    }

    /**
     * Dry-run machine walk — no waits sleep, no side-effect actions.
     *
     * @param array<string, mixed> $def
     * @param array<string, mixed> $payload
     * @return array{
     *   status: string,
     *   payload: array<string, mixed>,
     *   log: list<array<string, mixed>>,
     *   error?: string
     * }
     */
    public function trace(
        array $def,
        Entity $automation,
        Entity $target,
        string $tenantId,
        array $payload,
        ?User $actor = null,
    ): array {
        $run = $automation; // unused placeholder for signature parity
        $item = $automation;

        return $this->advance($def, $automation, $run, $item, $target, $tenantId, $payload, true, $actor);
    }

    /**
     * @param array<string, mixed> $def
     * @param array<string, mixed> $payload
     * @return array{
     *   status: string,
     *   payload: array<string, mixed>,
     *   log: list<array<string, mixed>>,
     *   error?: string
     * }
     */
    private function advance(
        array $def,
        Entity $automation,
        Entity $run,
        Entity $item,
        Entity $target,
        string $tenantId,
        array $payload,
        bool $dryRun,
        ?User $actor = null,
    ): array {
        $states = $this->indexStates($def['states'] ?? []);
        $transitions = is_array($def['transitions'] ?? null) ? $def['transitions'] : [];
        $log = is_array($payload['_machineLog'] ?? null) ? $payload['_machineLog'] : [];
        $steps = (int) ($payload['_machineSteps'] ?? 0);

        $stateId = (string) ($payload['_state'] ?? $def['initial'] ?? '');
        if ($stateId === '' || !isset($states[$stateId])) {
            return [
                'status' => 'Failed',
                'payload' => $payload,
                'log' => $log,
                'error' => "Unknown machine state '{$stateId}'",
            ];
        }

        while ($steps < self::MAX_STEPS) {
            $state = $states[$stateId];
            $type = (string) ($state['type'] ?? 'normal');

            $wakeAt = isset($payload['_wakeAt']) ? (string) $payload['_wakeAt'] : '';
            if ($wakeAt !== '' && !$dryRun) {
                if (strtotime($wakeAt) > time()) {
                    // Still waiting on clock — but join may have been satisfied externally
                    if ($type === 'join' || !empty($payload['_joinWaiting'])) {
                        $mode = (string) ($state['joinMode'] ?? $payload['_joinMode'] ?? 'waitAll');
                        $eval = $this->joinCoordinator->evaluate($payload, $mode);
                        $payload = $eval['payload'];
                        if ($eval['satisfied']) {
                            unset($payload['_wakeAt'], $payload['_joinWaiting']);
                            $payload['_onEnterDone'] = true;
                            $log[] = ['state' => $stateId, 'status' => 'join_satisfied_early'];
                            // fall through
                        } else {
                            $payload['_state'] = $stateId;
                            $payload['_machineLog'] = $log;
                            $payload['_machineSteps'] = $steps;

                            return [
                                'status' => 'Waiting',
                                'payload' => $payload,
                                'log' => $log,
                            ];
                        }
                    } else {
                        $payload['_state'] = $stateId;
                        $payload['_machineLog'] = $log;
                        $payload['_machineSteps'] = $steps;

                        return [
                            'status' => 'Waiting',
                            'payload' => $payload,
                            'log' => $log,
                        ];
                    }
                } else {
                    unset($payload['_wakeAt']);
                    $pendingTo = isset($payload['_pendingTo']) ? (string) $payload['_pendingTo'] : '';
                    if ($pendingTo !== '' && isset($states[$pendingTo])) {
                        $exitLog = $this->runSide(
                            $state['onExit'] ?? [],
                            $automation,
                            $run,
                            $item,
                            $target,
                            $tenantId,
                            $payload,
                            'onExit',
                            $stateId,
                            $dryRun,
                            $actor,
                        );
                        $log = array_merge($log, $exitLog['log']);
                        if (!$exitLog['ok']) {
                            $payload['_machineLog'] = $log;
                            $payload['_machineSteps'] = $steps;

                            return [
                                'status' => 'Failed',
                                'payload' => $payload,
                                'log' => $log,
                                'error' => $exitLog['error'] ?? 'onExit failed',
                            ];
                        }

                        unset($payload['_pendingTo'], $payload['_onEnterDone'], $payload['_joinWaiting']);
                        $stateId = $pendingTo;
                        $payload['_state'] = $stateId;
                        $payload['_enteredStateAt'] = date('Y-m-d H:i:s');
                        $steps++;
                        continue;
                    }

                    if ($type === 'wait' || $type === 'join') {
                        // Timeout elapsed for join without satisfaction → fail unless waitAny got anything
                        if ($type === 'join') {
                            $mode = (string) ($state['joinMode'] ?? $payload['_joinMode'] ?? 'waitAll');
                            $eval = $this->joinCoordinator->evaluate($payload, $mode);
                            $payload = $eval['payload'];
                            if (!$eval['satisfied']) {
                                $payload['_joinTimedOut'] = true;
                                $payload['_onEnterDone'] = true;
                                $payload['_state'] = $stateId;
                                $payload['_machineLog'] = $log;
                                $payload['_machineSteps'] = $steps;
                                $log[] = [
                                    'state' => $stateId,
                                    'status' => 'join_timeout',
                                    'mode' => $mode,
                                ];

                                return [
                                    'status' => 'Failed',
                                    'payload' => $payload,
                                    'log' => $log,
                                    'error' => "Join state '{$stateId}' timed out ({$mode})",
                                ];
                            }
                            unset($payload['_joinWaiting']);
                            $payload['_onEnterDone'] = true;
                        } else {
                            $payload['_onEnterDone'] = true;
                        }
                    }
                }
            } elseif ($wakeAt !== '' && $dryRun) {
                $log[] = [
                    'state' => $stateId,
                    'status' => 'would_wait',
                    'wakeAt' => $wakeAt,
                ];
                unset($payload['_wakeAt'], $payload['_pendingTo']);
                $payload['_onEnterDone'] = true;
            }

            // —— join state ——
            if ($type === 'join') {
                if (empty($payload['_onEnterDone'])) {
                    $enterLog = $this->runSide(
                        $state['onEnter'] ?? [],
                        $automation,
                        $run,
                        $item,
                        $target,
                        $tenantId,
                        $payload,
                        'onEnter',
                        $stateId,
                        $dryRun,
                        $actor,
                    );
                    $log = array_merge($log, $enterLog['log']);
                    if (!$enterLog['ok']) {
                        return $this->fail($payload, $log, $steps, $enterLog['error'] ?? 'onEnter failed');
                    }
                    if (!empty($enterLog['waiting'])) {
                        $payload = $enterLog['payload'] ?? $payload;
                        $payload['_state'] = $stateId;
                        $payload['_machineLog'] = $log;
                        $payload['_machineSteps'] = $steps;

                        return [
                            'status' => 'Waiting',
                            'payload' => $payload,
                            'log' => $log,
                        ];
                    }
                    $payload['_onEnterDone'] = true;
                    $payload['_enteredStateAt'] = date('Y-m-d H:i:s');
                }

                $mode = (string) ($state['joinMode'] ?? 'waitAll');
                $eval = $this->joinCoordinator->evaluate($payload, $mode);
                $payload = $eval['payload'];

                if (!$eval['satisfied']) {
                    if ($dryRun) {
                        $log[] = [
                            'state' => $stateId,
                            'status' => 'would_wait_join',
                            'mode' => $mode,
                        ];
                        // Assume satisfied for dry-run walk-through
                        $payload['_joinSatisfied'] = true;
                    } else {
                        $timeout = trim((string) ($state['timeoutPeriod'] ?? $state['waitPeriod'] ?? '2 hours'));
                        try {
                            $payload['_wakeAt'] = $this->periodParser->addToNow(
                                $timeout !== '' ? $timeout : '2 hours'
                            );
                        } catch (Throwable $e) {
                            return $this->fail($payload, $log, $steps, 'join timeout: ' . $e->getMessage());
                        }
                        $payload['_joinWaiting'] = true;
                        $payload['_joinMode'] = $mode;
                        $payload['_state'] = $stateId;
                        $payload['_machineLog'] = $log;
                        $payload['_machineSteps'] = $steps;
                        $log[] = [
                            'state' => $stateId,
                            'status' => 'waiting_join',
                            'mode' => $mode,
                            'wakeAt' => $payload['_wakeAt'],
                        ];

                        return [
                            'status' => 'Waiting',
                            'payload' => $payload,
                            'log' => $log,
                        ];
                    }
                }

                $log[] = ['state' => $stateId, 'status' => 'join_ok', 'mode' => $mode];
                // continue to pick transition
            }

            if ($type === 'wait' && empty($payload['_onEnterDone'])) {
                $enterLog = $this->runSide(
                    $state['onEnter'] ?? [],
                    $automation,
                    $run,
                    $item,
                    $target,
                    $tenantId,
                    $payload,
                    'onEnter',
                    $stateId,
                    $dryRun,
                    $actor,
                );
                $log = array_merge($log, $enterLog['log']);
                if (!$enterLog['ok']) {
                    return $this->fail($payload, $log, $steps, $enterLog['error'] ?? 'onEnter failed');
                }
                if (!empty($enterLog['waiting'])) {
                    $payload = $enterLog['payload'] ?? $payload;
                    $payload['_state'] = $stateId;
                    $payload['_machineLog'] = $log;
                    $payload['_machineSteps'] = $steps;

                    return ['status' => 'Waiting', 'payload' => $payload, 'log' => $log];
                }

                $resolved = $this->resolveWaitClock(
                    $state,
                    $target,
                    $item,
                    $automation,
                    $tenantId,
                    $payload,
                    "Wait state '{$stateId}'",
                );
                if (isset($resolved['error'])) {
                    return $this->fail($payload, $log, $steps, $resolved['error']);
                }

                if ($resolved['mode'] === 'elapsed') {
                    $log[] = [
                        'state' => $stateId,
                        'status' => $dryRun ? 'would_wait_elapsed' : 'wait_elapsed',
                        'wakeAt' => $resolved['wakeAt'] ?? null,
                    ];
                    $payload['_onEnterDone'] = true;
                } elseif ($dryRun) {
                    $log[] = [
                        'state' => $stateId,
                        'status' => 'would_wait',
                        'period' => $resolved['period'] ?? null,
                        'wakeAt' => $resolved['wakeAt'] ?? null,
                    ];
                    $payload['_onEnterDone'] = true;
                } else {
                    $payload['_wakeAt'] = $resolved['wakeAt'];
                    $payload['_onEnterDone'] = true;
                    $payload['_state'] = $stateId;
                    $payload['_enteredStateAt'] = date('Y-m-d H:i:s');
                    $payload['_machineLog'] = $log;
                    $payload['_machineSteps'] = $steps;
                    $log[] = [
                        'state' => $stateId,
                        'status' => 'waiting',
                        'wakeAt' => $payload['_wakeAt'],
                        'period' => $resolved['period'] ?? null,
                    ];

                    return ['status' => 'Waiting', 'payload' => $payload, 'log' => $log];
                }
            }

            if (empty($payload['_onEnterDone']) && $type !== 'join') {
                $enterLog = $this->runSide(
                    $state['onEnter'] ?? [],
                    $automation,
                    $run,
                    $item,
                    $target,
                    $tenantId,
                    $payload,
                    'onEnter',
                    $stateId,
                    $dryRun,
                    $actor,
                );
                $log = array_merge($log, $enterLog['log']);
                if (!$enterLog['ok']) {
                    return $this->fail($payload, $log, $steps, $enterLog['error'] ?? 'onEnter failed');
                }
                if (!empty($enterLog['waiting'])) {
                    $payload = $enterLog['payload'] ?? $payload;
                    $payload['_state'] = $stateId;
                    $payload['_machineLog'] = $log;
                    $payload['_machineSteps'] = $steps;

                    return ['status' => 'Waiting', 'payload' => $payload, 'log' => $log];
                }
                $payload['_onEnterDone'] = true;
                $payload['_enteredStateAt'] = date('Y-m-d H:i:s');
            }

            if ($type === 'final') {
                $payload['_state'] = $stateId;
                $payload['_machineLog'] = $log;
                $payload['_machineSteps'] = $steps;

                return ['status' => 'Done', 'payload' => $payload, 'log' => $log];
            }

            $match = $this->pickTransition(
                $transitions,
                $stateId,
                $automation,
                $item,
                $target,
                $tenantId,
                $payload,
            );

            if ($match === null) {
                $payload['_state'] = $stateId;
                $payload['_machineLog'] = $log;
                $payload['_machineSteps'] = $steps;
                $log[] = ['state' => $stateId, 'status' => 'no_transition_end'];

                return ['status' => 'Done', 'payload' => $payload, 'log' => $log];
            }

            $to = (string) $match['to'];
            $hasClockWait = trim((string) ($match['waitPeriod'] ?? '')) !== ''
                || trim((string) ($match['waitUntil'] ?? '')) !== ''
                || trim((string) ($match['waitUntilFormula'] ?? '')) !== '';

            if ($hasClockWait) {
                $resolved = $this->resolveWaitClock(
                    $match,
                    $target,
                    $item,
                    $automation,
                    $tenantId,
                    $payload,
                    'transition wait',
                );
                if (isset($resolved['error'])) {
                    return $this->fail($payload, $log, $steps, $resolved['error']);
                }

                if ($resolved['mode'] === 'elapsed') {
                    $log[] = [
                        'from' => $stateId,
                        'to' => $to,
                        'status' => $dryRun ? 'would_wait_transition_elapsed' : 'wait_transition_elapsed',
                        'wakeAt' => $resolved['wakeAt'] ?? null,
                    ];
                } elseif ($dryRun) {
                    $log[] = [
                        'from' => $stateId,
                        'to' => $to,
                        'status' => 'would_wait_transition',
                        'period' => $resolved['period'] ?? null,
                        'wakeAt' => $resolved['wakeAt'] ?? null,
                    ];
                } else {
                    $payload['_wakeAt'] = $resolved['wakeAt'];
                    $payload['_pendingTo'] = $to;
                    $payload['_state'] = $stateId;
                    $payload['_machineLog'] = $log;
                    $payload['_machineSteps'] = $steps;
                    $log[] = [
                        'from' => $stateId,
                        'to' => $to,
                        'status' => 'waiting_transition',
                        'wakeAt' => $payload['_wakeAt'],
                        'period' => $resolved['period'] ?? null,
                    ];

                    return ['status' => 'Waiting', 'payload' => $payload, 'log' => $log];
                }
            }

            $exitLog = $this->runSide(
                $state['onExit'] ?? [],
                $automation,
                $run,
                $item,
                $target,
                $tenantId,
                $payload,
                'onExit',
                $stateId,
                $dryRun,
                $actor,
            );
            $log = array_merge($log, $exitLog['log']);
            if (!$exitLog['ok']) {
                return $this->fail($payload, $log, $steps, $exitLog['error'] ?? 'onExit failed');
            }

            $log[] = [
                'from' => $stateId,
                'to' => $to,
                'status' => 'transitioned',
            ];

            unset($payload['_onEnterDone'], $payload['_pendingTo'], $payload['_joinWaiting']);
            $stateId = $to;
            $payload['_state'] = $stateId;
            $payload['_enteredStateAt'] = date('Y-m-d H:i:s');
            $steps++;
        }

        $payload['_machineLog'] = $log;
        $payload['_machineSteps'] = $steps;

        return [
            'status' => 'Failed',
            'payload' => $payload,
            'log' => $log,
            'error' => 'Machine exceeded max steps (' . self::MAX_STEPS . ')',
        ];
    }

    /**
     * @param list<array<string, mixed>> $log
     * @param array<string, mixed> $payload
     * @return array{status: string, payload: array<string, mixed>, log: list<array<string, mixed>>, error: string}
     */
    private function fail(array $payload, array $log, int $steps, string $error): array
    {
        $payload['_machineLog'] = $log;
        $payload['_machineSteps'] = $steps;

        return [
            'status' => 'Failed',
            'payload' => $payload,
            'log' => $log,
            'error' => $error,
        ];
    }

    /**
     * @param list<array<string, mixed>> $states
     * @return array<string, array<string, mixed>>
     */
    private function indexStates(array $states): array
    {
        $out = [];
        foreach ($states as $s) {
            if (!is_array($s)) {
                continue;
            }
            $id = (string) ($s['id'] ?? '');
            if ($id !== '') {
                $out[$id] = $s;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $transitions
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function pickTransition(
        array $transitions,
        string $from,
        Entity $automation,
        Entity $item,
        Entity $target,
        string $tenantId,
        array $payload,
    ): ?array {
        foreach ($transitions as $t) {
            if (!is_array($t)) {
                continue;
            }
            if ((string) ($t['from'] ?? '') !== $from) {
                continue;
            }

            $when = $t['when'] ?? null;
            if (is_string($when) && trim($when) !== '') {
                try {
                    $pass = $this->evalWhen($when, $target, $item, $automation, $tenantId, $payload);
                } catch (Throwable $e) {
                    $this->log->warning('Machine when: ' . $e->getMessage());
                    continue;
                }
                if (!$pass) {
                    continue;
                }
            }

            return $t;
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @param array<string, mixed> $payload
     * @return array{
     *   ok: bool,
     *   log: list<array<string, mixed>>,
     *   error?: string,
     *   waiting?: bool,
     *   payload?: array<string, mixed>
     * }
     */
    private function runSide(
        array $actions,
        Entity $automation,
        Entity $run,
        Entity $item,
        Entity $target,
        string $tenantId,
        array $payload,
        string $side,
        string $stateId,
        bool $dryRun,
        ?User $actor = null,
    ): array {
        if ($actions === []) {
            return ['ok' => true, 'log' => [['state' => $stateId, 'side' => $side, 'status' => 'empty']]];
        }

        $result = $this->actionRunner->runActions(
            $actions,
            $automation,
            $run,
            $item,
            $target,
            $tenantId,
            $payload,
            'allMatching',
            $dryRun,
            $actor,
        );

        $tagged = [];
        foreach ($result['log'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['state'] = $stateId;
            $row['side'] = $side;
            $tagged[] = $row;
        }

        if (!$result['ok']) {
            return [
                'ok' => false,
                'log' => $tagged,
                'error' => $result['error'] ?? "{$side} failed",
                'payload' => $result['payload'] ?? $payload,
            ];
        }

        if (!empty($result['waiting'])) {
            return [
                'ok' => true,
                'log' => $tagged,
                'waiting' => true,
                'payload' => $result['payload'] ?? $payload,
            ];
        }

        return [
            'ok' => true,
            'log' => $tagged,
            'payload' => $result['payload'] ?? $payload,
        ];
    }

    /**
     * Resolve relative waitPeriod or absolute waitUntil into a wake plan.
     *
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $payload
     * @return array{
     *   mode: 'wait'|'elapsed',
     *   wakeAt?: string,
     *   period?: string,
     *   error?: string
     * }
     */
    private function resolveWaitClock(
        array $spec,
        Entity $target,
        Entity $item,
        Entity $automation,
        string $tenantId,
        array $payload,
        string $ctx,
    ): array {
        $period = trim((string) ($spec['waitPeriod'] ?? ''));
        $until = trim((string) ($spec['waitUntil'] ?? ''));
        $untilFormula = trim((string) ($spec['waitUntilFormula'] ?? ''));
        $tz = trim((string) ($spec['waitUntilTimezone'] ?? ''));

        if ($period !== '') {
            try {
                $wakeAt = $this->periodParser->addToNow($period);
            } catch (Throwable $e) {
                return ['mode' => 'wait', 'error' => "{$ctx} waitPeriod: " . $e->getMessage()];
            }

            return ['mode' => 'wait', 'wakeAt' => $wakeAt, 'period' => $period];
        }

        if ($untilFormula !== '') {
            try {
                $variables = (object) [
                    'automationId' => $automation->getId(),
                    'runItemId' => $item->getId(),
                    'tenantId' => $tenantId,
                    'payload' => json_decode(json_encode($payload) ?: '{}'),
                    'state' => $payload['_state'] ?? null,
                ];
                $evaluated = $this->formulaRunner->run(
                    $untilFormula,
                    $target,
                    $variables,
                    RestrictedFormulaRunner::MODE_CONDITION,
                );
                if ($evaluated === null || $evaluated === false || $evaluated === '') {
                    return [
                        'mode' => 'wait',
                        'error' => "{$ctx}: waitUntilFormula returned empty value",
                    ];
                }
                $until = is_scalar($evaluated) ? trim((string) $evaluated) : '';
                if ($until === '') {
                    return [
                        'mode' => 'wait',
                        'error' => "{$ctx}: waitUntilFormula returned empty value",
                    ];
                }
            } catch (Throwable $e) {
                return [
                    'mode' => 'wait',
                    'error' => "{$ctx} waitUntilFormula: " . $e->getMessage(),
                ];
            }
        }

        if ($until === '') {
            return ['mode' => 'wait', 'error' => "{$ctx}: missing waitPeriod or waitUntil"];
        }

        try {
            $wakeAt = $this->wakeAtResolver->toUtcSql($until, $tz !== '' ? $tz : null);
        } catch (Throwable $e) {
            return ['mode' => 'wait', 'error' => "{$ctx} waitUntil: " . $e->getMessage()];
        }

        if (strtotime($wakeAt) <= time()) {
            return ['mode' => 'elapsed', 'wakeAt' => $wakeAt];
        }

        return ['mode' => 'wait', 'wakeAt' => $wakeAt];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function evalWhen(
        string $formula,
        Entity $target,
        Entity $item,
        Entity $automation,
        string $tenantId,
        array $payload,
    ): bool {
        $variables = (object) [
            'automationId' => $automation->getId(),
            'runItemId' => $item->getId(),
            'tenantId' => $tenantId,
            'payload' => json_decode(json_encode($payload) ?: '{}'),
            'state' => $payload['_state'] ?? null,
        ];

        $value = $this->formulaRunner->run(
            $formula,
            $target,
            $variables,
            RestrictedFormulaRunner::MODE_CONDITION,
        );

        return (bool) $value;
    }
}
