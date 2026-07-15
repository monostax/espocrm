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

namespace Espo\Modules\Global\Hooks\Opportunity;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Authoritative gate for the "automatic follow-up disabled" toggles:
 *
 *   • `Funnel.followupsEnabled`                          — funnel level
 *   • `ChatwootAccountUserMembership.followupsEnabled`   — AI-agent level
 *
 * When EITHER toggle is OFF for an Opportunity (its funnel, or the AI
 * agent stamped on `followupAiAgentId`), the row must never enter
 * `followupStatus = 'FollowupActive'`. Any write attempting it —
 * AI tool call, operator UI edit, API client — is silently coerced to
 * `'ActionNeeded'` with a NULL timer ("always inactive" semantics).
 *
 * Coercion (not rejection) is deliberate: the AI-side tools also coerce
 * and continue the turn (creating/updating the opportunity is still
 * allowed — only the follow-up arming is suppressed), and a BadRequest
 * here would fail the whole entity save.
 *
 * Runs at $order = 18 — after {@see AutoSetFollowupStatusOnClose}
 * ($order = 10) and {@see ResetFollowupCountOnStatusChange}
 * ($order = 15), and BEFORE {@see EnforceFollowupTimerInvariant}
 * ($order = 20) so the invariant hook observes the post-coercion state
 * (non-active status + null timer) and never throws for this path.
 *
 * The companion `ResetFollowupsOnDisable` AfterSave hooks (on Funnel
 * and ChatwootAccountUserMembership) handle rows that were ALREADY
 * active when a toggle is switched off; this hook closes the door for
 * new writes while the toggle is off. The backend follow-up scheduler
 * additionally filters disabled rows out of its due-query
 * (defense-in-depth for any row that slips through).
 *
 * @implements BeforeSave<Opportunity>
 */
class EnforceFollowupsEnabled implements BeforeSave
{
    public static int $order = 18;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * @param Opportunity $entity
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->get('followupStatus') !== 'FollowupActive') {
            return;
        }

        // Only re-evaluate when something follow-up-relevant changed —
        // avoids two entity lookups on every unrelated save of an
        // already-active row (those rows are handled by the bulk-reset
        // hooks + the scheduler-side filter).
        if (
            !$entity->isNew()
            && !$entity->isAttributeChanged('followupStatus')
            && !$entity->isAttributeChanged('followupTimer')
            && !$entity->isAttributeChanged('funnelId')
            && !$entity->isAttributeChanged('followupAiAgentId')
        ) {
            return;
        }

        if ($this->isFollowupDisabled($entity)) {
            $entity->set('followupStatus', 'ActionNeeded');
            $entity->set('followupTimer', null);
        }
    }

    private function isFollowupDisabled(Entity $entity): bool
    {
        $funnelId = $entity->get('funnelId');

        if ($funnelId) {
            $funnel = $this->entityManager->getEntityById('Funnel', $funnelId);

            if ($funnel && !$funnel->get('followupsEnabled')) {
                return true;
            }
        }

        $aiAgentId = $entity->get('followupAiAgentId');

        if (
            $aiAgentId
            && $this->entityManager
                ->getDefs()
                ->hasEntity('ChatwootAccountUserMembership')
        ) {
            $membership = $this->entityManager
                ->getEntityById('ChatwootAccountUserMembership', $aiAgentId);

            if ($membership && !$membership->get('followupsEnabled')) {
                return true;
            }
        }

        return false;
    }
}
