<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\ORM\Entity;

/**
 * A deal's revision: what its verdict was decided on. The same deal state always gives the same revision,
 * so a stored verdict is reused until something on the deal moves. Silence moves it in steps, not daily.
 */
final class Revision
{
    /** Keep in step with the backend's CATCH_UP_PROMPT_VERSION; bumping it has every deal judged again. */
    public const PROMPT_VERSION = '10';
    /** Where the conversation is read from; bumping it has every deal judged again. 2: Chatwoot, not the CRM copy that lost messages. */
    public const EVIDENCE_VERSION = '2';

    /** Silence re-judges a deal at these days; 30 is the last check (decided 2026-11-05: older history rarely matters). */
    private const QUIET_STEPS = [1, 2, 4, 7, 14, 30];

    /**
     * @param ?array<string, mixed> $state CatchUpDealState row
     * @param ?array<string, mixed> $nextAction
     */
    public static function of(Entity $deal, ?array $state, ?array $nextAction, ?string $lastPostAt, DateTimeImmutable $now, ?int $dueTs = null): string
    {
        return hash('sha256', json_encode([
            self::PROMPT_VERSION, self::EVIDENCE_VERSION, self::due($dueTs, $now),
            $deal->get('opportunityStageId'), $deal->get('amount'), $deal->get('assignedUserId'), $deal->get('followupStatus'),
            $nextAction ? [$nextAction['id'], $nextAction['status'] ?? null, $nextAction['dateEnd'] ?? null, $nextAction['dateEndDate'] ?? null] : null,
            $state ? [$state['last_message_at'], (int) $state['message_count'], $state['staff_user_id']] : null,
            $lastPostAt,
            self::quietStep(self::quietDays($state['last_message_at'] ?? null, $now)),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * A conversation without a deal (Atendimento): its activity, who spoke last, its message count and
     * assignee, and the same quiet-time steps as a deal.
     */
    public static function ofConversation(Entity $conversation, DateTimeImmutable $now, ?int $dueTs = null): string
    {
        return hash('sha256', json_encode([
            self::PROMPT_VERSION, self::EVIDENCE_VERSION, 'conversation', self::due($dueTs, $now),
            $conversation->get('lastActivityAt'), $conversation->get('lastMessageReceivedAt'), $conversation->get('lastMessageSentAt'),
            (int) $conversation->get('messagesCount'), $conversation->get('assigneeId'),
            self::quietStep(self::quietDays($conversation->get('lastActivityAt'), $now)),
        ], JSON_THROW_ON_ERROR));
    }

    /** Whether a promise in the subject's record is past due: it is judged again once it passes. */
    public static function due(?int $dueTs, DateTimeImmutable $now): bool
    {
        return $dueTs !== null && $dueTs <= $now->getTimestamp();
    }

    public static function quietDays(?string $lastMessageAt, DateTimeImmutable $now): ?int
    {
        if (!$lastMessageAt) return null;
        $last = new DateTimeImmutable($lastMessageAt, new DateTimeZone('UTC'));
        return max(0, intdiv($now->getTimestamp() - $last->getTimestamp(), 86400));
    }

    public static function quietStep(?int $days): ?int
    {
        if ($days === null) return null;
        $step = 0;
        foreach (self::QUIET_STEPS as $threshold) if ($days >= $threshold) $step++;
        return $step;
    }
}
