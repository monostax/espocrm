<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureSelfService\Tools\Plans;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * What the checkout preselects, from how the workspace was used:
 *  - attendants: the people answering in Chatwoot (no AI users, no concierge);
 *  - monthly credits: the smallest step that, with the attendants' included
 *    credits, covers the AI conversations of the trial scaled to a month
 *    (none when the included credits are enough or there is too little to go by).
 */
class PlanRecommendation
{
    public function __construct(private EntityManager $entityManager) {}

    /**
     * @param array{includedCredits: int} $plan
     * @param list<array{key: string, credits: int}> $steps Smallest first; the first is the zero step.
     * @return array{seats: int, credits: string, attendants: int, trialConversations: ?int}
     */
    public function recommend(Entity $tenant, Entity $account, array $plan, array $steps): array
    {
        $attendants = $this->attendants($account);
        $seats = max(1, $attendants);
        $monthly = $this->monthlyAiConversations($tenant);
        $credits = $steps[0]['key'];

        if ($monthly !== null) {
            $needed = $monthly * PlanOffers::CREDITS_PER_CONVERSATION - $seats * $plan['includedCredits'];

            foreach ($steps as $step) {
                $credits = $step['key'];

                if ($step['credits'] >= $needed) {
                    break;
                }
            }
        }

        return [
            'seats' => $seats,
            'credits' => $credits,
            'attendants' => $attendants,
            'trialConversations' => $monthly,
        ];
    }

    private function attendants(Entity $account): int
    {
        $where = [
            'chatwootAccountId' => $account->getId(),
            'OR' => [['isAI' => false], ['isAI' => null]],
        ];

        if ($account->get('conciergeUserId')) {
            $where['chatwootUserId!='] = $account->get('conciergeUserId');
        }

        return $this->entityManager->getRDBRepository('ChatwootAccountUserMembership')->where($where)->count();
    }

    /**
     * AI conversations since the trial started, per 30 days. Null before the
     * trial has run a day, when there is too little to go by.
     */
    private function monthlyAiConversations(Entity $tenant): ?int
    {
        $startsAt = $tenant->get('trialStartsAt');

        if (!$startsAt) {
            return null;
        }

        $days = (time() - strtotime($startsAt . ' UTC')) / 86400;

        if ($days < 1) {
            return null;
        }

        $runs = $this->entityManager->getRDBRepository('ChatwootAiAgentRun')
            ->select(['conversationId'])
            ->where(['tenantId' => $tenant->getId(), 'runAt>=' => $startsAt, 'conversationId!=' => null])
            ->group('conversationId')
            ->find();

        return (int) ceil(count(iterator_to_array($runs, false)) * 30 / min($days, 30));
    }
}
