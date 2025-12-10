/************************************************************************
 * Iframe-Parent Bridge
 * Enables bidirectional communication between EspoCRM (iframe) and parent window
 *
 * Features:
 * - Notifies parent when CRM navigation changes
 * - Listens for navigation commands from parent
 * - Syncs URL state between iframe and parent
 * - Provides public API for custom views (window.EspoCRMBridge)
 *
 * Public API:
 * - window.EspoCRMBridge.notifyThreadNavigation(aiThreadId)
 *   Notifies parent to navigate to a specific AI thread
 ************************************************************************/

(function () {
    "use strict";

    // Only run if we're in an iframe
    function isInIframe() {
        try {
            return window.self !== window.top;
        } catch (e) {
            return true;
        }
    }

    if (!isInIframe()) {
        console.log("EspoCRM: Not in iframe, bridge disabled");
        return;
    }

    console.log("EspoCRM: Initializing iframe-parent bridge");

    let isInitialized = false;
    let currentHash = window.location.hash;
    let parentOrigin = null;
    let allowedOrigins = [];

    // Derive parent origin from current location (most reliable method)
    // The parent is at the same domain but without the 'crm.' subdomain
    try {
        const currentUrl = new URL(window.location.href);
        const hostname = currentUrl.hostname;

        // If hostname is like 'crm.acme.am.monostax.dev.localhost'
        // Parent would be 'acme.am.monostax.dev.localhost'
        if (hostname.startsWith("crm.")) {
            const parentHostname = hostname.substring(4); // Remove 'crm.'
            const parentOriginDerived = `${currentUrl.protocol}//${parentHostname}`;

            // Always use the derived origin as the primary parent origin
            parentOrigin = parentOriginDerived;
            allowedOrigins.push(parentOriginDerived);

            console.log("EspoCRM: Derived parent origin:", parentOriginDerived);
        }
    } catch (e) {
        console.error("EspoCRM: Failed to derive parent origin:", e);
    }

    // Also try document.referrer as a fallback/additional allowed origin
    if (document.referrer) {
        try {
            const referrerUrl = new URL(document.referrer);
            const referrerOrigin = referrerUrl.origin;

            // Add referrer to allowed origins if not already there
            if (!allowedOrigins.includes(referrerOrigin)) {
                allowedOrigins.push(referrerOrigin);
                console.log(
                    "EspoCRM: Added referrer to allowed origins:",
                    referrerOrigin
                );
            }

            // Only use referrer as parentOrigin if we don't have one yet
            if (!parentOrigin) {
                parentOrigin = referrerOrigin;
                console.log(
                    "EspoCRM: Using referrer as parent origin:",
                    referrerOrigin
                );
            }
        } catch (e) {
            console.error("EspoCRM: Failed to parse referrer:", e);
        }
    }

    if (!parentOrigin || allowedOrigins.length === 0) {
        console.warn("EspoCRM: Could not determine parent origin");
        return;
    }

    console.log("EspoCRM: Allowed parent origins:", allowedOrigins);

    /**
     * Notify parent of route change
     * @param {string} hash - The current hash/route
     */
    function notifyParentOfRouteChange(hash) {
        // Remove leading # if present
        const path = hash.startsWith("#") ? hash.substring(1) : hash;

        try {
            window.parent.postMessage(
                {
                    type: "CRM_ROUTE_CHANGE",
                    path: path,
                    timestamp: Date.now(),
                },
                parentOrigin
            );
            console.log("EspoCRM: Notified parent of route change:", path);
        } catch (e) {
            console.error("EspoCRM: Failed to notify parent:", e);
        }
    }

    /**
     * Notify parent to navigate to a specific AI thread
     * @param {string} aiThreadId - The AI thread ID to navigate to
     */
    function notifyParentOfThreadNavigation(aiThreadId) {
        if (!isInIframe() || !parentOrigin) {
            console.warn(
                "EspoCRM: Cannot notify parent - not in iframe or no parent origin"
            );
            return;
        }

        try {
            window.parent.postMessage(
                {
                    type: "CRM_THREAD_NAVIGATE",
                    aiThreadId: aiThreadId,
                    timestamp: Date.now(),
                },
                parentOrigin
            );
            console.log(
                "EspoCRM: Notified parent to navigate to thread:",
                aiThreadId
            );
        } catch (e) {
            console.error(
                "EspoCRM: Failed to notify parent of thread navigation:",
                e
            );
        }
    }

    /**
     * Extract breadcrumb text from DOM
     * @returns {Object|null} Breadcrumb data or null if not found
     */
    function extractBreadcrumbs() {
        const breadcrumbElement = document.querySelector(".header-breadcrumbs");

        if (!breadcrumbElement) {
            return null;
        }

        const breadcrumbItems = [];
        const items = breadcrumbElement.querySelectorAll(".breadcrumb-item");

        items.forEach((item) => {
            const text = item.textContent.trim();
            if (text) {
                breadcrumbItems.push(text);
            }
        });

        if (breadcrumbItems.length === 0) {
            return null;
        }

        return {
            items: breadcrumbItems,
            text: breadcrumbItems.join(" > "),
        };
    }

    /**
     * Notify parent of breadcrumb change
     * @param {Object} breadcrumbs - The breadcrumb data
     */
    function notifyParentOfBreadcrumbChange(breadcrumbs) {
        if (!breadcrumbs) {
            return;
        }

        try {
            window.parent.postMessage(
                {
                    type: "CRM_BREADCRUMB_UPDATE",
                    breadcrumbs: breadcrumbs.items,
                    breadcrumbText: breadcrumbs.text,
                    timestamp: Date.now(),
                },
                parentOrigin
            );
            console.log(
                "EspoCRM: Notified parent of breadcrumb change:",
                breadcrumbs.text
            );
        } catch (e) {
            console.error(
                "EspoCRM: Failed to notify parent of breadcrumbs:",
                e
            );
        }
    }

    /**
     * Navigate to a route
     * @param {string} path - The path to navigate to
     */
    function navigateToPath(path) {
        // Remove leading # if present
        const cleanPath = path.startsWith("#") ? path.substring(1) : path;

        // Update hash without triggering hashchange if already there
        if (window.location.hash === "#" + cleanPath) {
            return;
        }

        console.log("EspoCRM: Navigating to path from parent:", cleanPath);
        window.location.hash = cleanPath;
    }

    /**
     * Update EspoCRM theme via API
     * @param {string} theme - Theme value: 'light' or 'dark'
     */
    function updateEspoCRMTheme(theme) {
        // Map parent themes to EspoCRM themes
        const espoCRMTheme = theme === "light" ? "Espo" : "Dark";

        console.log(
            "EspoCRM: Theme change requested:",
            espoCRMTheme,
            "(from parent theme:",
            theme + ")"
        );

        // Check if Espo.Ajax is available
        if (!window.Espo || !window.Espo.Ajax) {
            console.error("EspoCRM: Espo.Ajax not available yet");
            return;
        }

        // First, fetch current settings to check actual persisted theme
        window.Espo.Ajax.getRequest("Settings")
            .then((data) => {
                const currentTheme = data?.theme || null;

                console.log(
                    "EspoCRM: Current persisted theme:",
                    currentTheme,
                    "Target theme:",
                    espoCRMTheme
                );

                // Only update if theme is different
                if (currentTheme === espoCRMTheme) {
                    console.log(
                        "EspoCRM: Theme already set to",
                        espoCRMTheme,
                        "- skipping update"
                    );
                    return;
                }

                // Use EspoCRM's built-in Ajax utility (handles auth automatically)
                return window.Espo.Ajax.putRequest("Settings/1", {
                    theme: espoCRMTheme,
                }).then((data) => {
                    console.log("EspoCRM: Theme updated successfully:", data);
                    // Reload to apply theme change
                    setTimeout(() => {
                        console.log("EspoCRM: Reloading to apply theme change");
                        window.location.reload();
                    }, 500);
                });
            })
            .catch((error) => {
                console.error("EspoCRM: Error checking/updating theme:", error);
            });
    }

    /**
     * Listen for navigation commands from parent
     */
    function setupParentListener() {
        window.addEventListener("message", function (event) {
            console.log("EspoCRM: Received message:", {
                type: event.data?.type,
                origin: event.origin,
                allowedOrigins: allowedOrigins,
            });

            // Verify origin is in allowed list
            if (!allowedOrigins.includes(event.origin)) {
                console.warn(
                    "EspoCRM: Origin not in allowed list, ignoring message"
                );
                return;
            }

            console.log("EspoCRM: Origin verified, processing message");

            // Handle parent navigation commands
            if (event.data.type === "PARENT_NAVIGATE") {
                const path = event.data.path || "";
                navigateToPath(path);
            }

            // Handle theme change from parent
            if (event.data.type === "THEME_CHANGE") {
                const theme = event.data.theme;
                if (theme) {
                    console.log(
                        "EspoCRM: Received theme change from parent:",
                        theme
                    );
                    updateEspoCRMTheme(theme);
                }
            }
        });

        console.log(
            "EspoCRM: Listening for parent messages from:",
            allowedOrigins
        );
    }

    /**
     * Monitor hash changes and notify parent
     */
    function setupHashChangeListener() {
        // Use a more reliable method to detect hash changes
        let lastHash = window.location.hash;

        function checkHashChange() {
            const newHash = window.location.hash;
            if (newHash !== lastHash) {
                lastHash = newHash;
                currentHash = newHash;
                notifyParentOfRouteChange(newHash);
            }
        }

        // Listen to hashchange event
        window.addEventListener("hashchange", checkHashChange);

        // Also poll periodically as backup (some SPAs might not trigger hashchange)
        setInterval(checkHashChange, 500);

        console.log("EspoCRM: Monitoring hash changes");
    }

    /**
     * Monitor breadcrumb changes and notify parent
     */
    function setupBreadcrumbMonitor() {
        let lastBreadcrumbText = null;

        function checkBreadcrumbs() {
            const breadcrumbs = extractBreadcrumbs();

            if (breadcrumbs && breadcrumbs.text !== lastBreadcrumbText) {
                lastBreadcrumbText = breadcrumbs.text;
                notifyParentOfBreadcrumbChange(breadcrumbs);
            }
        }

        // Use MutationObserver to watch for DOM changes
        const observer = new MutationObserver(function (mutations) {
            // Check if any mutation affected the header area
            for (let mutation of mutations) {
                if (
                    mutation.target.classList &&
                    (mutation.target.classList.contains("header-breadcrumbs") ||
                        mutation.target.classList.contains("page-header") ||
                        mutation.target.closest(".header-breadcrumbs"))
                ) {
                    checkBreadcrumbs();
                    return;
                }
            }
        });

        // Start observing the document with configured parameters
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true,
        });

        // Also poll periodically as backup
        setInterval(checkBreadcrumbs, 1000);

        // Initial check
        setTimeout(checkBreadcrumbs, 500);

        console.log("EspoCRM: Monitoring breadcrumb changes");
    }

    /**
     * Initialize the bridge
     */
    function initialize() {
        if (isInitialized) {
            return;
        }

        setupParentListener();
        setupHashChangeListener();
        setupBreadcrumbMonitor();

        // Expose public API for custom views to use
        window.EspoCRMBridge = {
            notifyThreadNavigation: notifyParentOfThreadNavigation,
        };

        // Notify parent that CRM is ready
        try {
            window.parent.postMessage(
                {
                    type: "CRM_READY",
                    initialPath: currentHash.startsWith("#")
                        ? currentHash.substring(1)
                        : currentHash,
                    timestamp: Date.now(),
                },
                parentOrigin
            );
            console.log("EspoCRM: Sent ready message to parent");
        } catch (e) {
            console.error("EspoCRM: Failed to send ready message:", e);
        }

        isInitialized = true;
        console.log("EspoCRM: Iframe-parent bridge initialized");
    }

    // Wait for DOM to be ready and app to initialize
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () {
            // Delay slightly to ensure app is initialized
            setTimeout(initialize, 1000);
        });
    } else {
        setTimeout(initialize, 1000);
    }
})();

