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
 * Shared defaults preparator that prefills the `tenant` link field
 * with the logged-in user's first Tenant (resolved via the `tenantUser`
 * many-to-many: Tenant linkedWith users = currentUser).
 *
 * Wired via clientDefs[Entity].modelDefaultsPreparator on entities that
 * have a required `tenant` belongsTo link (e.g. Contact, Account).
 *
 * Notes:
 * - Skips the lookup when the user is admin/portal or has no id.
 * - Returns an empty object if the user has no tenant — the field stays
 *   empty and the standard "required" validator will surface the error.
 */
export default class extends DefaultsPreparator {
    /**
     * @private
     * @type {User}
     */
    @inject(User)
    user;

    /**
     * @param {import('model').default} _model
     * @return {Promise<Object.<string, *>>}
     */
    async prepare(_model) {
        const userId = this.user.id;

        if (!userId) {
            return {};
        }

        try {
            const response = await Espo.Ajax.getRequest("Tenant", {
                where: [
                    {
                        type: "linkedWith",
                        attribute: "users",
                        value: [userId],
                    },
                ],
                select: "id,name",
                maxSize: 1,
            });

            if (response && Array.isArray(response.list) && response.list.length > 0) {
                const tenant = response.list[0];

                return {
                    tenantId: tenant.id,
                    tenantName: tenant.name,
                };
            }
        } catch (e) {
            // Swallow — leave tenant empty so the form's required
            // validator can guide the user instead of failing silently.
            console.warn("Tenant defaults-preparator: failed to resolve user's tenant", e);
        }

        return {};
    }
}
