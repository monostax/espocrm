/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * Custom field view for `calendarsToManage` on ChatwootAccountUserMembership.
 *
 * Subclasses the stock `link-multiple-with-columns` view to surface an inline,
 * per-calendar `description` column (varchar 4000) on the link table
 * `chatwoot_account_user_membership_calendar_user`.
 *
 * The DB column is declared via `additionalColumns` on the link def in
 * entityDefs. The client column metadata is declared via `fields.calendarsToManage.columns`
 * pointing to the virtual `calendarDescription` field on the host entity, which
 * the stock parent's host-entity fallback resolver will pick up automatically
 * (link-multiple-with-columns.js:88-93).
 *
 * If the host-entity fallback ever stops working, populate `this.columnsDefs.description`
 * directly in `setup()` BEFORE calling `super.setup()`:
 *   this.columnsDefs = {
 *     description: {
 *       type: 'varchar',
 *       maxLength: 4000,
 *       scope: this.model.entityType,
 *       field: 'calendarDescription',
 *     },
 *   };
 *
 * The `description` is INTERNAL operator guidance, surfaced into the cached
 * Gemini system prompt at agents/$chatwoot.ts (appointmentsInstructions).
 * It must NEVER be quoted verbatim to the customer (privacy clause enforced
 * in the system prompt).
 */
define('chatwoot:views/account-user-membership/fields/calendars-to-manage', ['views/fields/link-multiple-with-columns'], function (Dep) {

    return Dep.extend({});
});
