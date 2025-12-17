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

class ChatwootIndexView extends View {
    template = "chatwoot:chatwoot/index";

    chatwootBaseUrl = "https://chatwoot.am.monostax.dev.localhost";

    // Track if SSO authentication has been completed this session
    static hasSsoAuthenticated = false;

    setup() {
        // Get cwPath and SSO URL from options (passed from controller)
        this.cwPath = this.options.cwPath || "";
        this.chatwootSsoUrl = this.options.chatwootSsoUrl || "";

        console.log("Chatwoot View: Setup called with cwPath:", this.cwPath);
        console.log("Chatwoot View: SSO URL available:", !!this.chatwootSsoUrl);
        console.log("Chatwoot View: Full options:", this.options);

        // Notify parent to switch to Chatwoot mode when this view is loaded
        this.notifyParentToChatwoot();

        // Listen for Chatwoot navigation updates from parent iframe
        this.setupChatwootListener();
    }

    notifyParentToChatwoot() {
        // Check if we're in an iframe
        const isInIframe = window.self !== window.top;

        if (!isInIframe) {
            console.log(
                "Chatwoot View: Not in iframe, skip parent notification"
            );
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
            console.warn("Chatwoot View: Could not determine parent origin");
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
            console.log(
                "Chatwoot View: Notified parent to switch to Chatwoot mode with path:",
                this.cwPath
            );
        } catch (e) {
            console.error("Chatwoot View: Failed to notify parent:", e);
        }
    }

    setupChatwootListener() {
        // This view acts as a bridge - listening to Chatwoot iframe messages
        // and updating the URL bar to stay in sync
        const messageHandler = (event) => {
            // Only process Chatwoot messages
            if (event.data.type === "CHATWOOT_ROUTE_CHANGE") {
                const chatwootPath = event.data.path || "";
                console.log(
                    "Chatwoot View: Route change detected:",
                    chatwootPath
                );

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
                            console.log(
                                "Chatwoot View: Forwarded path change to parent:",
                                chatwootPath
                            );
                        } catch (e) {
                            console.error(
                                "Chatwoot View: Failed to forward path change:",
                                e
                            );
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
                        console.log(
                            "Chatwoot View: Updated URL bar to:",
                            newHash
                        );
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
        if (this.chatwootSsoUrl && !ChatwootIndexView.hasSsoAuthenticated) {
            // Use SSO URL for authentication
            // The SSO URL will authenticate and redirect to the Chatwoot dashboard
            chatwootUrl = this.chatwootSsoUrl;
            ChatwootIndexView.hasSsoAuthenticated = true;

            console.log("Chatwoot View: Using SSO URL for authentication");
            console.log("Chatwoot View: SSO URL:", chatwootUrl);

            // If we have a specific path to navigate to after auth,
            // we'll need to navigate there after the iframe loads
            if (this.cwPath) {
                console.log(
                    "Chatwoot View: Will navigate to path after SSO auth:",
                    this.cwPath
                );
                this.pendingNavigation = this.cwPath;
            }
        } else {
            // Already authenticated or no SSO URL - use direct path
            chatwootUrl = this.cwPath
                ? `${this.chatwootBaseUrl}${this.cwPath}`
                : this.chatwootBaseUrl;

            console.log(
                "Chatwoot View: Building URL with cwPath:",
                this.cwPath
            );
        }

        console.log("Chatwoot View: Final chatwootUrl:", chatwootUrl);

        return {
            chatwootUrl: chatwootUrl,
        };
    }

    afterRender() {
        const $iframe = this.$el.find("iframe");

        console.log("Chatwoot View: afterRender called");
        console.log(
            "Chatwoot View: Iframe src attribute:",
            $iframe.attr("src")
        );
        console.log("Chatwoot View: Iframe element:", $iframe[0]);

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
                    console.log(
                        "Chatwoot View: SSO auth complete, navigating to:",
                        pendingPath
                    );

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

