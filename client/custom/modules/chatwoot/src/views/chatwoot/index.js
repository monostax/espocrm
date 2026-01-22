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

import View from "view";

// Session storage key for tracking SSO auth state globally
const CHATWOOT_SSO_AUTH_KEY = "chatwoot_sso_authenticated";

class ChatwootIndexView extends View {
    template = "chatwoot:chatwoot/index";

    chatwootBaseUrl = null;

    /**
     * Check if SSO has been completed this session (shared across all views)
     */
    static hasSsoAuthenticated() {
        return sessionStorage.getItem(CHATWOOT_SSO_AUTH_KEY) === "true";
    }

    /**
     * Mark SSO as completed for this session
     */
    static setSsoAuthenticated() {
        sessionStorage.setItem(CHATWOOT_SSO_AUTH_KEY, "true");
    }

    setup() {
        // Get cwPath, SSO URL and frontend URL from options (passed from controller)
        this.cwPath = this.options.cwPath || "";
        this.chatwootSsoUrl = this.options.chatwootSsoUrl || "";
        this.chatwootBaseUrl = this.options.chatwootFrontendUrl || "https://chatwoot.am.monostax.dev.localhost";

        // Notify parent to switch to Chatwoot mode when this view is loaded
        this.notifyParentToChatwoot();

        // Listen for Chatwoot navigation updates from parent iframe
        this.setupChatwootListener();
    }

    notifyParentToChatwoot() {
        // Check if we're in an iframe
        const isInIframe = window.self !== window.top;

        if (!isInIframe) {
            return;
        }

        // Get parent origin (should be available via EspoCRMBridge)
        const currentUrl = new URL(window.location.href);
        const hostname = currentUrl.hostname;

        let parentOrigin = null;
        if (hostname.startsWith("crm.")) {
            const parentHostname = hostname.substring(4); // Remove 'crm.'
            parentOrigin = `${currentUrl.protocol}//${parentHostname}`;
        }

        if (!parentOrigin) {
            return;
        }

        try {
            window.parent.postMessage(
                {
                    type: "CRM_CHATWOOT_NAVIGATE",
                    cwPath: this.cwPath || "",
                    timestamp: Date.now(),
                },
                parentOrigin
            );
        } catch (e) {
            // Failed to notify parent
        }
    }

    setupChatwootListener() {
        // This view acts as a bridge - listening to Chatwoot iframe messages
        // and updating the URL bar to stay in sync
        const messageHandler = (event) => {
            // Only process Chatwoot messages
            if (event.data.type === "CHATWOOT_ROUTE_CHANGE") {
                const chatwootPath = event.data.path || "";

                // Check if we're in an iframe (parent app mode)
                const isInIframe = window.self !== window.top;

                if (isInIframe) {
                    // We're embedded in parent app - forward to grandparent
                    const currentUrl = new URL(window.location.href);
                    const hostname = currentUrl.hostname;

                    let parentOrigin = null;
                    if (hostname.startsWith("crm.")) {
                        const parentHostname = hostname.substring(4);
                        parentOrigin = `${currentUrl.protocol}//${parentHostname}`;
                    }

                    if (parentOrigin) {
                        try {
                            window.parent.postMessage(
                                {
                                    type: "CRM_CHATWOOT_ROUTE_CHANGE",
                                    path: chatwootPath,
                                    timestamp: Date.now(),
                                },
                                parentOrigin
                            );
                        } catch (e) {
                            // Failed to forward path change
                        }
                    }
                } else {
                    // We're in standalone CRM - update the URL bar
                    // Encode the path for use in URL
                    const encodedPath = encodeURIComponent(chatwootPath);
                    const newHash = `#Chatwoot?cwPath=${encodedPath}`;

                    // Update URL without reloading the page
                    if (window.location.hash !== newHash) {
                        window.history.replaceState(null, null, newHash);
                    }
                }
            }
        };

        window.addEventListener("message", messageHandler);

        // Clean up listener when view is removed
        this.once("remove", () => {
            window.removeEventListener("message", messageHandler);
        });
    }

    data() {
        let chatwootUrl;

        // For first load, use SSO URL to authenticate
        // SSO URLs are single-use, so we only use them once per session
        if (this.chatwootSsoUrl && !ChatwootIndexView.hasSsoAuthenticated()) {
            // Use SSO URL for authentication
            // The SSO URL will authenticate and redirect to the Chatwoot dashboard
            chatwootUrl = this.chatwootSsoUrl;
            ChatwootIndexView.setSsoAuthenticated();

            // If we have a specific path to navigate to after auth,
            // we'll need to navigate there after the iframe loads
            if (this.cwPath) {
                this.pendingNavigation = this.cwPath;
            }
        } else {
            // Already authenticated or no SSO URL - use direct path
            chatwootUrl = this.cwPath
                ? `${this.chatwootBaseUrl}${this.cwPath}`
                : this.chatwootBaseUrl;
        }

        return {
            chatwootUrl: chatwootUrl,
        };
    }

    afterRender() {
        const $iframe = this.$el.find("iframe");

        // Set iframe to full height
        const updateHeight = () => {
            const headerHeight = $("#navbar").outerHeight() || 0;
            const windowHeight = $(window).height();
            const availableHeight = windowHeight - headerHeight;

            $iframe.css("height", availableHeight + "px");
        };

        updateHeight();

        // Update height on window resize
        $(window).on("resize", updateHeight);

        // If we used SSO and have a pending navigation, navigate after auth completes
        if (this.pendingNavigation) {
            const pendingPath = this.pendingNavigation;
            this.pendingNavigation = null;

            // Listen for the CHATWOOT_READY message indicating auth is complete
            const handleReady = (event) => {
                if (event.data.type === "CHATWOOT_READY") {
                    // Navigate to the pending path by updating iframe src
                    const targetUrl = `${this.chatwootBaseUrl}${pendingPath}`;
                    $iframe.attr("src", targetUrl);

                    window.removeEventListener("message", handleReady);
                }
            };

            window.addEventListener("message", handleReady);

            // Clean up if view is removed before navigation
            this.once("remove", () => {
                window.removeEventListener("message", handleReady);
            });
        }

        // Clean up event listener when view is removed
        this.once("remove", () => {
            $(window).off("resize", updateHeight);
        });
    }
}

export default ChatwootIndexView;

