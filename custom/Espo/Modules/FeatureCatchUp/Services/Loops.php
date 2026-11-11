<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use DateTimeImmutable;

/**
 * Loops are the core of Em dia (gtm/05 §16, decided 2026-11-10): a subject's record keeps every move one side owes the
 * other, explicit ("te mando o contrato até 18h") or implied (an unanswered question, a proposal with no reply). The model
 * keeps the loops; this class only compares their times with the clock and the follow-up rule, and turns them into the
 * card a reader sees and the bucket it sits in:
 * - Esperando a gente: one of ours is due. Without a time it is due now (customer_waiting); with one, once it passes
 *   (promise_due). Opening and closing a deal (decided, new_deal) and a stalled deal (next_step_missing) are ours too.
 * - Esperando o cliente: one of theirs is late (follow_up_due): its time passed, or, without one, the conversation has
 *   been quiet for Feed::FOLLOW_UP_AFTER_DAYS. Not while the AI follow-up is armed on the deal: the AI nudges then.
 * - Agenda: loops are open but none is due yet (`scheduled`, no card).
 *
 * Time alone never asks for an analysis (decided 2026-11-11): the stored verdict keeps its open loops (basis()) and the
 * time the clock next changes the pick (flipsAt()); Verdicts::flipDue() picks again then, in code.
 */
final class Loops
{
    /** Verdicts with a card, by bucket. Found deals have their own tab (Negócios encontrados, decided 2026-11-11). */
    public const BUCKETS = [
        'customer_waiting' => 'us', 'promise_due' => 'us', 'decided' => 'us', 'next_step_missing' => 'us',
        'follow_up_due' => 'customer',
        'new_deal' => 'found',
    ];
    /** Judged, with nothing to show now: kept out of the deck until the revision moves. */
    public const QUIET = ['none', 'scheduled'];

    /** @return list<array<string, mixed>> */
    public static function open(?array $record): array
    {
        return array_values(array_filter($record['promises'] ?? [], fn ($loop) => is_array($loop) && isset($loop['text'])
            && in_array($loop['side'] ?? null, ['ours', 'theirs'], true) && ($loop['status'] ?? 'open') === 'open'));
    }

    /**
     * The verdict a reader gets from the model's answer and the record it returned.
     *
     * @param string $answer the model's verdict: loops, decided, next_step_missing, new_deal or none
     * @param array<string, mixed> $facts the snapshot's facts (quietDays, aiFollowupActive)
     * @param bool $stepPending the deal has a pending next step (Task/Call/Meeting)
     * @return array{verdict: string, loop: ?array{id: ?string, side: string, text: string, dueAt: ?string}}
     */
    public static function verdict(string $answer, ?array $record, array $facts, DateTimeImmutable $now, bool $stepPending = false): array
    {
        $open = self::open($record);
        if (in_array($answer, ['decided', 'new_deal'], true)) return ['verdict' => $answer, 'loop' => null];
        // A stalled deal is one where nobody owes anybody anything and nothing is scheduled.
        if ($answer === 'next_step_missing' && !$open) return ['verdict' => $stepPending ? 'none' : $answer, 'loop' => null];
        if (!in_array($answer, ['loops', 'next_step_missing'], true) || !$open) return ['verdict' => 'none', 'loop' => null];
        return self::pick($open, $facts, $now);
    }

    /**
     * The most pressing open loop and the verdict it makes: ours due now first, then ours past its time, then theirs
     * late, otherwise the next to come due (Agenda).
     *
     * @param list<array<string, mixed>> $open
     * @return array{verdict: string, loop: array{id: ?string, side: string, text: string, dueAt: ?string}}
     */
    public static function pick(array $open, array $facts, DateTimeImmutable $now): array
    {
        $ours = array_values(array_filter($open, fn ($loop) => $loop['side'] === 'ours'));
        $theirs = array_values(array_filter($open, fn ($loop) => $loop['side'] === 'theirs'));
        $waiting = array_values(array_filter($ours, fn ($loop) => self::due($loop) === null));
        if ($waiting) return self::found('customer_waiting', $waiting[0]);
        $late = self::earliest(array_filter($ours, fn ($loop) => self::due($loop) <= $now->getTimestamp()));
        if ($late) return self::found('promise_due', $late);
        // The AI follow-up nudges a quiet customer on its own; a person isn't asked to as well.
        if (empty($facts['aiFollowupActive'])) {
            $quiet = (int) ($facts['quietDays'] ?? 0) >= Feed::FOLLOW_UP_AFTER_DAYS;
            $chase = self::earliest(array_filter($theirs, fn ($loop) => self::due($loop) !== null && self::due($loop) <= $now->getTimestamp()))
                ?? ($quiet ? (array_values(array_filter($theirs, fn ($loop) => self::due($loop) === null))[0] ?? null) : null);
            if ($chase) return self::found('follow_up_due', $chase);
        }
        $next = self::earliest(array_filter($open, fn ($loop) => self::due($loop) !== null)) ?? $open[0];
        return self::found('scheduled', $next);
    }

    /**
     * What a stored verdict is picked again from when the clock moves: the open loops, when the conversation went quiet,
     * and whether the AI follow-up was armed. Null for a verdict that no loop decides (decided, new_deal, none).
     *
     * @param list<array<string, mixed>> $open
     * @return ?array{loops: list<array<string, mixed>>, quietSince: ?string, followup: bool}
     */
    public static function basis(array $open, array $facts, ?string $quietSince): ?array
    {
        if (!$open) return null;
        $keep = fn (array $loop) => array_intersect_key($loop, array_flip(['id', 'side', 'text', 'dueAt', 'status']));
        return ['loops' => array_map($keep, $open), 'quietSince' => $quietSince, 'followup' => !empty($facts['aiFollowupActive'])];
    }

    /** The facts pick() reads, at $now, from a stored basis. */
    public static function factsAt(array $basis, DateTimeImmutable $now): array
    {
        return ['quietDays' => Revision::quietDays($basis['quietSince'] ?? null, $now), 'aiFollowupActive' => !empty($basis['followup'])];
    }

    /**
     * The next time the clock alone can change pick(): one of our loops or (without the AI follow-up) one of theirs comes
     * due, or a loop of theirs without a time crosses the follow-up threshold. Null when nothing will.
     *
     * @param array{loops: list<array<string, mixed>>, quietSince: ?string, followup: bool} $basis
     */
    public static function flipsAt(array $basis, DateTimeImmutable $now): ?int
    {
        $clock = $now->getTimestamp();
        $times = [];
        foreach (self::open(['promises' => $basis['loops'] ?? []]) as $loop) {
            $due = self::due($loop);
            if ($loop['side'] === 'theirs' && !empty($basis['followup'])) continue;
            if ($due !== null && $due > $clock) $times[] = $due;
            if ($due === null && $loop['side'] === 'theirs' && !empty($basis['quietSince'])) {
                $quiet = (new DateTimeImmutable((string) $basis['quietSince'], new \DateTimeZone('UTC')))->getTimestamp();
                $threshold = $quiet + Feed::FOLLOW_UP_AFTER_DAYS * 86400;
                if ($threshold > $clock) $times[] = $threshold;
            }
        }
        return $times ? min($times) : null;
    }

    /** Whether two card loops are the same: by id when both have one, else by side and text. */
    public static function same(?array $a, ?array $b): bool
    {
        if (!$a || !$b) return $a === $b;
        if (isset($a['id'], $b['id'])) return (string) $a['id'] === (string) $b['id'];
        return ($a['side'] ?? null) === ($b['side'] ?? null) && trim((string) ($a['text'] ?? '')) === trim((string) ($b['text'] ?? ''));
    }

    /** A loop's due time as a Unix time; null when it has none (or none code can read). */
    public static function due(array $loop): ?int
    {
        if (empty($loop['dueAt']) || !is_string($loop['dueAt'])) return null;
        try {
            return (new DateTimeImmutable($loop['dueAt']))->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /** @param array<array<string, mixed>> $loops */
    private static function earliest(array $loops): ?array
    {
        $best = null;
        foreach ($loops as $loop) if ($best === null || self::due($loop) < self::due($best)) $best = $loop;
        return $best;
    }

    /** The loop as a card shows it; its id lets "Já resolvi" keep exactly that loop in the record (Records::keep). */
    private static function found(string $verdict, array $loop): array
    {
        return ['verdict' => $verdict, 'loop' => [
            'id' => isset($loop['id']) && (is_string($loop['id']) || is_int($loop['id'])) ? (string) $loop['id'] : null,
            'side' => (string) $loop['side'], 'text' => mb_substr((string) $loop['text'], 0, 200),
            'dueAt' => is_string($loop['dueAt'] ?? null) && $loop['dueAt'] !== '' ? $loop['dueAt'] : null,
        ]];
    }
}
