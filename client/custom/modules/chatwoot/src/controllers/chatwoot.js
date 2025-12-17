/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2025 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

import Controller from "controller";
import AppParams from "app-params";
import { inject } from "di";

class ChatwootController extends Controller {
    @inject(AppParams)
    appParams;

    actionIndex(options) {
        console.log(
            "Chatwoot Controller: actionIndex called with options:",
            options
        );
        console.log(
            "Chatwoot Controller: All option keys:",
            Object.keys(options || {})
        );
        console.log("Chatwoot Controller: options.cwPath:", options?.cwPath);
        console.log(
            "Chatwoot Controller: Full window.location.hash:",
            window.location.hash
        );

        // Parse cwPath directly from the hash since EspoCRM router has issues with slashes
        let cwPath = "";
        const hash = window.location.hash;

        // Try to extract cwPath from hash (supports both ?cwPath= and URL-encoded format)
        if (hash.includes("cwPath=")) {
            const match = hash.match(/cwPath=([^&]*)/);
            if (match && match[1]) {
                cwPath = match[1];
                // URL decode if needed
                try {
                    cwPath = decodeURIComponent(cwPath);
                } catch (e) {
                    console.warn(
                        "Chatwoot Controller: Failed to decode cwPath:",
                        e
                    );
                }
            }
        }

        console.log("Chatwoot Controller: Extracted cwPath from hash:", cwPath);

        // Support template variables like {{chatwootAccountId}} in cwPath
        // This allows dynamic URLs like: #Chatwoot?cwPath=/app/accounts/{{chatwootAccountId}}/inbox-view
        if (cwPath && cwPath.includes("{{")) {
            cwPath = this.resolveTemplateVariables(cwPath);
            console.log("Chatwoot Controller: Resolved cwPath:", cwPath);
        }

        // Get SSO URL for authentication (required for first load)
        const chatwootSsoUrl = this.appParams.get("chatwootSsoUrl");
        console.log(
            "Chatwoot Controller: SSO URL available:",
            !!chatwootSsoUrl
        );

        this.main("chatwoot:views/chatwoot/index", {
            cwPath: cwPath,
            chatwootSsoUrl: chatwootSsoUrl,
        });
    }

    /**
     * Resolve template variables in a path string.
     * Supports variables like {{chatwootAccountId}} and {{chatwootSsoUrl}}
     *
     * @param {string} path - The path with template variables
     * @returns {string} - The resolved path
     */
    resolveTemplateVariables(path) {
        // Find all template variables in the format {{variableName}}
        const templateRegex = /\{\{(\w+)\}\}/g;

        return path.replace(templateRegex, (match, variableName) => {
            // Try to get the value from AppParams
            const value = this.appParams.get(variableName);

            if (value === null || value === undefined) {
                console.warn(
                    `Chatwoot Controller: Template variable {{${variableName}}} not found in AppParams`
                );
                return match; // Keep the original {{variableName}} if not found
            }

            console.log(
                `Chatwoot Controller: Resolved {{${variableName}}} to:`,
                value
            );
            return value;
        });
    }

    actionInbox() {
        console.log("Chatwoot Controller: actionInbox called");

        // Get the Chatwoot account ID from AppParams (NOT config!)
        // AppParams are user-specific values returned from /api/v1/App/user
        const chatwootAccountId = this.appParams.get("chatwootAccountId");
        const chatwootSsoUrl = this.appParams.get("chatwootSsoUrl");

        // Debug: Show what values we have
        console.log("Chatwoot Controller: AppParam values:", {
            chatwootAccountId: chatwootAccountId,
            chatwootSsoUrl: chatwootSsoUrl,
            hasSsoUrl: !!chatwootSsoUrl,
            typeOfAccountId: typeof chatwootAccountId,
            typeOfSsoUrl: typeof chatwootSsoUrl,
        });

        if (!chatwootAccountId) {
            console.error(
                "Chatwoot Controller: No chatwootAccountId found in AppParams"
            );
            console.error(
                "Chatwoot Controller: This means one of the following:"
            );
            console.error(
                "  1. Your EspoCRM user is not linked to a ChatwootUser record"
            );
            console.error(
                "  2. The ChatwootUser record doesn't have an accountId"
            );
            console.error(
                "  3. The ChatwootAccount record doesn't have chatwootAccountId field set"
            );
            console.error(
                "Chatwoot Controller: Check the EspoCRM logs for more details from ChatwootAccountId AppParam"
            );
            console.error(
                "Chatwoot Controller: Make sure you've rebuilt EspoCRM after fixing data (Administration → Rebuild)"
            );

            // Fallback: Load with SSO URL only
            this.main("chatwoot:views/chatwoot/index", {
                cwPath: "",
                chatwootSsoUrl: chatwootSsoUrl,
            });
            return;
        }

        // Build the inbox path
        const cwPath = `/app/accounts/${chatwootAccountId}/inbox-view`;
        console.log(
            "Chatwoot Controller: Navigating to inbox with account ID:",
            chatwootAccountId
        );
        console.log("Chatwoot Controller: Full path:", cwPath);

        this.main("chatwoot:views/chatwoot/index", {
            cwPath: cwPath,
            chatwootSsoUrl: chatwootSsoUrl,
        });
    }
}

export default ChatwootController;

