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
 *  - plan: Advanced when campaigns were used or the connected channels need
 *    more than Basic includes for that many attendants;
 *  - pack: the smallest that covers the AI conversations of the trial,
 *    scaled to a month.
 */
class PlanRecommendation
{
    private const WHATSAPP = ['whatsappQrcode', 'whatsappCloudApi', 'whatsappCoexistence'];

    public function __construct(private EntityManager $entityManager) {}

    /**
     * @param list<array{key: string, whatsappIncluded: int, instagramIncluded: int}> $plans
     * @param list<array{key: string, conversations: int}> $packs Smallest first.
     * @return array{plan: string, seats: int, pack: string, attendants: int, trialConversations: ?int}
     */
    public function recommend(Entity $tenant, Entity $account, array $plans, array $packs): array
    {
        $attendants = $this->attendants($account);
        $seats = max(1, $attendants);
        $basic = $plans[0];
        $extra = $seats - 1;

        [$whatsapp, $instagram] = $this->channels($account);
        $needsAdvanced = $whatsapp > $basic['whatsappIncluded'] + $extra ||
            $instagram > $basic['instagramIncluded'] + $extra ||
            $this->usedCampaigns((string) $tenant->getId());

        $monthly = $this->monthlyAiConversations($tenant);
        $pack = $packs[0]['key'];

        foreach ($packs as $candidate) {
            $pack = $candidate['key'];

            if ($monthly === null || $candidate['conversations'] >= $monthly) {
                break;
            }
        }

        return [
            'plan' => $needsAdvanced ? ($plans[1]['key'] ?? $basic['key']) : $basic['key'],
            'seats' => $seats,
            'pack' => $pack,
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
     * @return array{0: int, 1: int}
     */
    private function channels(Entity $account): array
    {
        $repository = $this->entityManager->getRDBRepository('ChatwootInboxIntegration');
        $base = ['chatwootAccountId' => $account->getId(), 'status' => 'ACTIVE'];

        return [
            $repository->where($base + ['channelType' => self::WHATSAPP])->count(),
            $repository->where($base + ['channelType' => 'instagram'])->count(),
        ];
    }

    private function usedCampaigns(string $tenantId): bool
    {
        return $this->entityManager->getRDBRepository('WhatsAppCampaign')
            ->select(['id'])
            ->where(['tenantId' => $tenantId])
            ->findOne() !== null;
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
