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

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Enforces the (followupStatus, followupTimer) coupling invariant on
 * `Opportunity`:
 *
 *   followupStatus = 'FollowupActive'  ⟺  followupTimer IS NOT NULL
 *
 * Or, equivalently:
 *   • status = 'FollowupActive'              ⇒ timer MUST be non-null
 *   • status ∈ {'ActionNeeded','Ended'}      ⇒ timer MUST be null
 *
 * Rationale: the scheduler (`getDueFollowups`) selects rows by
 * `followup_timer <= NOW()` after Phase E of
 * `plan-followup-active-collapse.md` reduced the active-set to the
 * single value `FollowupActive`. A `FollowupActive` row without a
 * timer is invisible to the scheduler and stays stuck until a
 * customer message resets it; conversely a non-active row carrying a
 * timer is dead data that leaks into operator dashboards and any
 * future "scheduled-for-X" UI affordances.
 *
 * The collapse plan (Phase D) wired the bookkeeping PUTs to write
 * both columns atomically inside `applyFollowupBookkeepingPut` and
 * `resetActiveFollowupsForContact`, but did NOT close three writer
 * paths that still touch the columns independently:
 *
 *   (a) AI `update_opportunity` tool — its Zod schema accepts
 *       `followupStatus` and `followupTimer` as independent optional
 *       fields. A `.superRefine()` block in
 *       `$chatwoot-opportunity-tool.ts` gives the AI a fast,
 *       structured error before the CRM round-trip; this hook is the
 *       authoritative gate that catches the request if the AI-side
 *       validation is bypassed or regressed.
 *
 *   (b) Operator UI — the EspoCRM detail-view dropdown for status
 *       and the datetime picker for timer are independent fields.
 *
 *   (c) {@see AutoSetFollowupStatusOnClose} — flips status to
 *       'Ended' on Won/Lost but does not NULL the timer. This hook
 *       runs at $order = 20, after AutoSet ($order = 10) and after
 *       {@see ResetFollowupCountOnStatusChange} ($order = 15), so
 *       it observes the post-other-hooks state and normalises the
 *       timer for any close transition that didn't pass through
 *       FollowupActive (e.g. ActionNeeded → Won, which neither of
 *       the existing hooks would touch the timer on).
 *
 * Enforcement strategy (per user decision):
 *   • Reject (BadRequest) on attempting to enter 'FollowupActive'
 *     with no timer — a clear caller bug that must surface loudly.
 *   • Auto-clear the timer on 'ActionNeeded' / 'Ended' — defensive
 *     normalisation, matches the spirit of AutoSetFollowupStatusOnClose's
 *     auto-flip and lets that hook stay timer-agnostic.
 *
 * @implements BeforeSave<Opportunity>
 */
class EnforceFollowupTimerInvariant implements BeforeSave
{
    public static int $order = 20;

    /**
     * @param Opportunity $entity
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (
            !$entity->isNew()
            && !$entity->isAttributeChanged('followupStatus')
            && !$entity->isAttributeChanged('followupTimer')
        ) {
            return;
        }

        $status = $entity->get('followupStatus');
        $timer = $entity->get('followupTimer');

        if ($status === 'FollowupActive' && $timer === null) {
            throw new BadRequest(
                "Opportunity invariant violated: followupTimer must be set when followupStatus is 'FollowupActive'."
            );
        }

        if ($status !== 'FollowupActive' && $timer !== null) {
            $entity->set('followupTimer', null);
        }
    }
}
