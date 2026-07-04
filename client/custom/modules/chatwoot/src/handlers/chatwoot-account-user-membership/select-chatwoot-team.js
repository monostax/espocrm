/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import SelectRelatedHandler from "handlers/select-related";

/**
 * Filters the ChatwootTeam select modal to only show
 * teams from the same ChatwootAccount as the current ChatwootAccountUserMembership.
 */
export default class extends SelectRelatedHandler {
    /**
     * @param {import('model').default} model
     * @return {Promise<import('handlers/select-related').filters>}
     */
    getFilters(model) {
        const advanced = {};

        const accountId = model.get("chatwootAccountId");
        const accountName = model.get("chatwootAccountName");

        if (accountId) {
            advanced.account = {
                attribute: "accountId",
                type: "equals",
                value: accountId,
                data: {
                    type: "is",
                    nameValue: accountName,
                },
            };
        }

        return Promise.resolve({
            advanced: advanced,
        });
    }
}
