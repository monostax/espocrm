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
    public const PROMPT_VERSION = '5';
    /** Where the conversation is read from; bumping it has every deal judged again. 2: Chatwoot, not the CRM copy that lost messages. */
    public const EVIDENCE_VERSION = '2';

    private const QUIET_STEPS = [1, 2, 4, 7, 14, 30, 60];

    /**
     * @param ?array<string, mixed> $state CatchUpDealState row
     * @param ?array<string, mixed> $nextAction
     */
    public static function of(Entity $deal, ?array $state, ?array $nextAction, ?string $lastPostAt, DateTimeImmutable $now): string
    {
        return hash('sha256', json_encode([
            self::PROMPT_VERSION, self::EVIDENCE_VERSION,
            $deal->get('opportunityStageId'), $deal->get('amount'), $deal->get('assignedUserId'), $deal->get('followupStatus'),
            $nextAction ? [$nextAction['id'], $nextAction['status'] ?? null, $nextAction['dateEnd'] ?? null, $nextAction['dateEndDate'] ?? null] : null,
            $state ? [$state['last_message_at'], (int) $state['message_count'], $state['staff_user_id']] : null,
            $lastPostAt,
            self::quietStep(self::quietDays($state['last_message_at'] ?? null, $now)),
        ], JSON_THROW_ON_ERROR));
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
