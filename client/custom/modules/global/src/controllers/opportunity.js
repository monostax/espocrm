/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import RecordController from "controllers/record";

/**
 * Opportunities live in the workspace app. Opening a record directly in the
 * CRM sends the browser to `/opportunities/{id}` there. Inside an iframe the
 * parent app owns navigation, so the record renders as usual.
 */
class OpportunityController extends RecordController {
    actionView(options) {
        const url = this.getWorkspaceUrl(options.id);

        if (url) {
            window.location.replace(url);

            return;
        }

        super.actionView(options);
    }

    /**
     * @param {string} id
     * @return {string|null}
     */
    getWorkspaceUrl(id) {
        if (window.self !== window.top) {
            return null;
        }

        const baseUrl = this.appParams.get("chatwootFrontendUrl");
        const accountId = this.appParams.get("chatwootAccountId");

        if (!baseUrl || !accountId || !id) {
            return null;
        }

        return `${baseUrl.replace(/\/$/, "")}/app/accounts/${accountId}/opportunities/${encodeURIComponent(id)}`;
    }
}

export default OpportunityController;
