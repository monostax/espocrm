/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import DefaultsPreparator from "handlers/model/defaults-preparator";

/**
 * Defaults preparator for ChatwootTeam.
 * When creating from a ChatwootAccount context (accountId is pre-filled),
 * copies the account's teams so the read-only teams field is populated
 * and validation passes without manual input.
 */
export default class extends DefaultsPreparator {
    /**
     * @param {import('model').default} model
     * @return {Promise<Object.<string, *>>}
     */
    async prepare(model) {
        const accountId = model.get("accountId");

        if (!accountId) {
            return {};
        }

        const response = await Espo.Ajax.getRequest("ChatwootAccount", {
            where: [
                {
                    type: "equals",
                    attribute: "id",
                    value: accountId,
                },
            ],
            maxSize: 1,
        });

        const account = response.list?.[0];

        if (!account || !account.teamsIds?.length) {
            return {};
        }

        const teamsIds = account.teamsIds;
        const teamsNames = {};

        (account.teamsNames || []).forEach((item) => {
            if (item && typeof item === "object") {
                teamsNames[item.id] = item.name;
            }
        });

        return {
            teamsIds: teamsIds,
            teamsNames: teamsNames,
        };
    }
}
