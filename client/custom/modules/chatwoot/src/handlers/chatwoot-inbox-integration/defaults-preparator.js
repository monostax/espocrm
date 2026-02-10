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
import { inject } from "di";
import User from "models/user";

/**
 * Defaults preparator for ChatwootInboxIntegration.
 * Prefills chatwootAccount based on the ChatwootAccount linked to the user's default team.
 */
export default class extends DefaultsPreparator {
    /**
     * @private
     * @type {User}
     */
    @inject(User)
    user;

    /**
     * @param {import('model').default} model
     * @return {Promise<Object.<string, *>>}
     */
    async prepare(model) {
        const defaultTeamId = this.user.get("defaultTeamId");

        if (!defaultTeamId) {
            return {};
        }

        // Fetch ChatwootAccount linked to user's default team
        const response = await Espo.Ajax.getRequest("ChatwootAccount", {
            where: [
                {
                    type: "linkedWith",
                    attribute: "teams",
                    value: [defaultTeamId],
                },
            ],
            maxSize: 1,
        });

        if (response.list && response.list.length > 0) {
            const account = response.list[0];

            return {
                chatwootAccountId: account.id,
                chatwootAccountName: account.name,
            };
        }

        return {};
    }
}

