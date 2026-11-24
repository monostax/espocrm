<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

/**
 * Catch-up for a number added during WhatsApp reply review.
 *
 * The enrollment goes back to its Entry stage and walks the stages again at their normal
 * pace. Stages the company already got send only to the added numbers, and only their
 * WhatsApp/SMS actions. Everyone else waits. When the run reaches the stage where it
 * stopped (`until`), everyone continues together. A stage the company never got, or a
 * terminal stage, also ends the catch-up, so everyone gets it.
 *
 * Kept in the enrollment's `replyReviewState` as:
 * `catchUp: {until, untilEnteredAt, visited: [stageId], pass, phones: {phone: {contactId, received: {stageId: pass}}}}`.
 * `pass` counts restarts: a second number added mid catch-up restarts the run, and
 * numbers already part-way wait at the stage they reached. A number marked wrong leaves
 * `phones`; with none left, resuming returns the run to `until` ({@see abandoned}).
 */
final class JourneyCatchUp
{
    /** Actions that send to a person's phone numbers. Calls, tasks, notes and email don't repeat. */
    public const PHONE_ACTIONS = ['sendWhatsAppTemplate', 'sendWhatsAppMessage', 'sendSms'];

    public static function active(array $state): bool
    {
        return isset($state['catchUp']) && is_array($state['catchUp']);
    }

    /** Every added number was marked wrong: nobody is left to catch up. */
    public static function abandoned(array $state): bool
    {
        return self::active($state) && empty($state['catchUp']['phones']);
    }

    public static function drop(array $state, string $phone): array
    {
        if (self::active($state)) {
            unset($state['catchUp']['phones'][$phone]);
        }

        return $state;
    }

    /**
     * Who gets this stage's phone actions while catching up.
     *
     * @return array<string, string>|null phone => contactId; null when not catching up
     */
    public static function lane(array $state, string $stageId, string $trigger): ?array
    {
        if (!self::active($state)) {
            return null;
        }
        $pass = (int) ($state['catchUp']['pass'] ?? 1);
        $lane = [];
        foreach ($state['catchUp']['phones'] ?? [] as $phone => $item) {
            $got = $item['received'][$stageId] ?? null;
            // OnEnter: numbers that never got this stage. OnExit: numbers that entered it in this pass.
            if ($trigger === 'OnExit' ? $got === $pass : $got === null) {
                $lane[(string) $phone] = (string) ($item['contactId'] ?? '');
            }
        }

        return $lane;
    }

    /**
     * Add numbers that start from the Entry stage, together in one pass. A run already
     * catching up keeps its `until` and `visited`, so the others still wait for the same stage.
     *
     * @param list<string> $visited stages the company already got
     * @param array<string, string> $phones phone => contactId
     */
    public static function start(array $state, string $untilStageId, ?string $untilEnteredAt, array $visited,
        array $phones): array
    {
        $catchUp = self::active($state) ? $state['catchUp'] : [
            'until' => $untilStageId,
            'untilEnteredAt' => $untilEnteredAt,
            'visited' => array_values(array_unique([...$visited, $untilStageId])),
            'pass' => 0,
            'phones' => [],
        ];
        $catchUp['pass'] = (int) ($catchUp['pass'] ?? 0) + 1;
        foreach ($phones as $phone => $contactId) {
            $catchUp['phones'][(string) $phone] = ['contactId' => $contactId, 'received' => []];
        }
        $state['catchUp'] = $catchUp;

        return $state;
    }

    /** Before a stage's OnEnter: a terminal stage or one the company never got goes to everyone. */
    public static function beforeEnter(array $state, string $stageId, string $stageType): array
    {
        if (self::active($state) && (
            in_array($stageType, ['Success', 'Exit'], true) ||
            !in_array($stageId, $state['catchUp']['visited'] ?? [], true)
        )) {
            unset($state['catchUp']);
        }

        return $state;
    }

    /** After a stage's OnEnter: the lane got it. Reaching the stage the others wait at ends the catch-up. */
    public static function afterEnter(array $state, string $stageId): array
    {
        if (!self::active($state)) {
            return $state;
        }
        $pass = (int) ($state['catchUp']['pass'] ?? 1);
        foreach (array_keys(self::lane($state, $stageId, 'OnEnter') ?? []) as $phone) {
            $state['catchUp']['phones'][(string) $phone]['received'][$stageId] = $pass;
        }
        if ($stageId === ($state['catchUp']['until'] ?? null)) {
            unset($state['catchUp']);
        }

        return $state;
    }
}
