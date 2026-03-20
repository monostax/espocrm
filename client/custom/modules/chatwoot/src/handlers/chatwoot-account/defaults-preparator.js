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
 * Defaults preparator for ChatwootAccount.
 * Prefills platform with the ChatwootPlatform marked as default.
 */
export default class extends DefaultsPreparator {
    /**
     * @param {import('model').default} model
     * @return {Promise<Object.<string, *>>}
     */
    async prepare(model) {
        const response = await Espo.Ajax.getRequest("ChatwootPlatform", {
            where: [
                {
                    type: "equals",
                    attribute: "isDefault",
                    value: true,
                },
            ],
            maxSize: 1,
            orderBy: "createdAt",
            order: "asc",
        });

        const platform = response.list?.[0];

        if (!platform) {
            return {};
        }

        return {
            platformId: platform.id,
            platformName: platform.name,
        };
    }
}
