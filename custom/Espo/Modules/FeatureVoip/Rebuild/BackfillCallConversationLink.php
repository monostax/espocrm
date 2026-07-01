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

namespace Espo\Modules\FeatureVoip\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Backfills Call.chatwootConversationRecordId for VoIP calls that were
 * mirrored before the ChatwootConversation link existed.
 *
 * A Call stores the raw external identifiers:
 *   - chatwootConversationId : Chatwoot-side conversation id (int)
 *   - chatwootAccountId      : Chatwoot-side account id (int)
 *
 * A ChatwootConversation stores:
 *   - chatwootConversationId : Chatwoot-side conversation id (int)
 *   - chatwootAccountId      : CRM ChatwootAccount record id (string)
 *
 * So we resolve external account id -> CRM ChatwootAccount, then match the
 * conversation and populate the link. Idempotent: skips calls that already
 * have the link set.
 */
class BackfillCallConversationLink implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $calls = $this->entityManager
            ->getRDBRepository('Call')
            ->where([
                'chatwootConversationRecordId' => null,
                'chatwootConversationId!=' => null,
                'chatwootAccountId!=' => null,
            ])
            ->find();

        // Cache external account id -> CRM ChatwootAccount id.
        $accountIdCache = [];
        $updated = 0;

        foreach ($calls as $call) {
            $externalConversationId = $call->get('chatwootConversationId');
            $externalAccountId = $call->get('chatwootAccountId');

            if (!$externalConversationId || !$externalAccountId) {
                continue;
            }

            if (!array_key_exists($externalAccountId, $accountIdCache)) {
                $account = $this->entityManager
                    ->getRDBRepository('ChatwootAccount')
                    ->where(['chatwootAccountId' => (int) $externalAccountId])
                    ->findOne();

                $accountIdCache[$externalAccountId] = $account ? $account->getId() : null;
            }

            $crmAccountId = $accountIdCache[$externalAccountId];
            if (!$crmAccountId) {
                continue;
            }

            $conversation = $this->entityManager
                ->getRDBRepository('ChatwootConversation')
                ->where([
                    'chatwootConversationId' => (int) $externalConversationId,
                    'chatwootAccountId' => $crmAccountId,
                ])
                ->findOne();

            if (!$conversation) {
                continue;
            }

            $call->set('chatwootConversationRecordId', $conversation->getId());
            $this->entityManager->saveEntity($call, ['silent' => true, 'skipHooks' => true]);
            $updated++;
        }

        if ($updated > 0) {
            $this->log->info("FeatureVoip: Backfilled chatwootConversationRecordId on {$updated} Call(s).");
        }
    }
}
