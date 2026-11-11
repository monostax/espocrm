<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\ORM\Entity;

/**
 * A subject's revision: what its verdict was decided on. The same state always gives the same revision, so a stored
 * verdict is reused until something the analysis reads moves. Time alone doesn't (decided 2026-11-11): a promise coming due
 * or a quiet customer is the clock against the stored loops, which Loops and Verdicts::flipDue() handle in code.
 */
final class Revision
{
    /** Keep in step with the backend's CATCH_UP_PROMPT_VERSION; bumping it has every deal judged again. */
    public const PROMPT_VERSION = '12';
    /**
     * Where the conversation is read from and what moves a revision; bumping it has every deal judged again. 2: Chatwoot,
     * not the CRM copy that lost messages. 3: no quiet-time steps or due promises.
     */
    public const EVIDENCE_VERSION = '3';

    /**
     * @param ?array<string, mixed> $state CatchUpDealState row
     * @param ?array<string, mixed> $nextAction
     */
    public static function of(Entity $deal, ?array $state, ?array $nextAction, ?string $lastPostAt): string
    {
        return hash('sha256', json_encode([
            self::PROMPT_VERSION, self::EVIDENCE_VERSION,
            $deal->get('opportunityStageId'), $deal->get('amount'), $deal->get('assignedUserId'), $deal->get('followupStatus'),
            $nextAction ? [$nextAction['id'], $nextAction['status'] ?? null, $nextAction['dateEnd'] ?? null, $nextAction['dateEndDate'] ?? null] : null,
            $state ? [$state['last_message_at'], (int) $state['message_count'], $state['staff_user_id']] : null,
            $lastPostAt,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * A conversation without a deal (Atendimento): its newest message each way. Activity (assignments, status changes)
     * doesn't move it: the analysis doesn't read it, and nothing analyses after it. A private note does, through the
     * evidence scope the verdict is stored under, and its settle analyses it.
     */
    public static function ofConversation(Entity $conversation): string
    {
        return hash('sha256', json_encode([
            self::PROMPT_VERSION, self::EVIDENCE_VERSION, 'conversation',
            $conversation->get('lastMessageReceivedAt'), $conversation->get('lastMessageSentAt'),
        ], JSON_THROW_ON_ERROR));
    }

    public static function quietDays(?string $lastMessageAt, DateTimeImmutable $now): ?int
    {
        if (!$lastMessageAt) return null;
        $last = new DateTimeImmutable($lastMessageAt, new DateTimeZone('UTC'));
        return max(0, intdiv($now->getTimestamp() - $last->getTimestamp(), 86400));
    }
}
