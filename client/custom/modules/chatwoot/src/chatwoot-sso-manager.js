/************************************************************************
 * ChatwootSsoManager - Centralized SSO authentication manager for Chatwoot iframes.
 *
 * Handles:
 * - SSO state tracking via sessionStorage (confirmed only after success)
 * - Fresh SSO URL fetching on demand via API
 * - CHATWOOT_READY message monitoring with login-page detection
 * - Automatic retry on SSO failure (up to 2 retries)
 * - Timeout fallback when bridge messages are not received
 *
 * Usage (ES module):
 *   import ChatwootSsoManager from 'chatwoot:chatwoot-sso-manager';
 *
 * Usage (AMD):
 *   define([..., 'chatwoot:chatwoot-sso-manager'], function(..., ChatwootSsoManager) { ... });
 ************************************************************************/

const CHATWOOT_SSO_AUTH_KEY = "chatwoot_sso_authenticated";

/**
 * Timeout (ms) to wait for CHATWOOT_READY before falling back.
 */
const READY_TIMEOUT_MS = 8000;

/**
 * Maximum number of SSO retry attempts.
 */
const MAX_RETRIES = 2;

class ChatwootSsoManager {
    constructor() {
        /** @type {number} */
        this._retryCount = 0;
    }

    /**
     * Check if SSO has been confirmed successful this session.
     * @returns {boolean}
     */
    isAuthenticated() {
        return sessionStorage.getItem(CHATWOOT_SSO_AUTH_KEY) === "true";
    }

    /**
     * Mark SSO as confirmed successful. Only call after verified success.
     */
    confirmAuthenticated() {
        sessionStorage.setItem(CHATWOOT_SSO_AUTH_KEY, "true");
        this._retryCount = 0;
    }

    /**
     * Clear SSO state (used before retrying).
     */
    clearAuthState() {
        sessionStorage.removeItem(CHATWOOT_SSO_AUTH_KEY);
    }

    /**
     * Determine the URL to load in the Chatwoot iframe.
     *
     * If SSO is already confirmed, returns a direct URL to the target path.
     * Otherwise, returns the SSO URL so the iframe can authenticate first.
     *
     * @param {string} chatwootBaseUrl - The Chatwoot frontend base URL
     * @param {string|null} ssoUrl - The SSO URL from AppParams (may be stale/null)
     * @param {string} [targetPath] - Optional path to navigate to after auth
     * @returns {{url: string, needsSso: boolean, pendingPath: string|null}}
     */
    getIframeUrl(chatwootBaseUrl, ssoUrl, targetPath) {
        if (this.isAuthenticated()) {
            const directUrl = targetPath
                ? `${chatwootBaseUrl}${targetPath}`
                : chatwootBaseUrl;
            return { url: directUrl, needsSso: false, pendingPath: null };
        }

        if (ssoUrl) {
            return {
                url: ssoUrl,
                needsSso: true,
                pendingPath: targetPath || null,
            };
        }

        // No SSO URL available and not authenticated - use direct URL (will likely show login)
        const directUrl = targetPath
            ? `${chatwootBaseUrl}${targetPath}`
            : chatwootBaseUrl;
        return { url: directUrl, needsSso: false, pendingPath: null };
    }

    /**
     * Set up monitoring for an iframe to detect SSO completion or failure.
     *
     * **Must be called BEFORE the iframe src is set** (i.e., in setup(), not afterRender()).
     *
     * Listens for CHATWOOT_READY from the Chatwoot bridge. When received:
     * - If the initialPath is a login page → SSO failed → triggers retry
     * - If the initialPath is NOT a login page → SSO confirmed → navigates to pending path
     *
     * Also sets a timeout fallback in case CHATWOOT_READY is never received.
     *
     * @param {object} options
     * @param {string} options.chatwootBaseUrl - The Chatwoot frontend base URL
     * @param {string|null} options.pendingPath - Path to navigate to after SSO
     * @param {string|null} options.ssoUrl - Current SSO URL (for retry with fresh URL)
     * @param {function(): HTMLIFrameElement|null} options.getIframe - Lazy getter for the iframe element
     * @param {function} [options.onConfirmed] - Called when SSO is confirmed successful
     * @param {function} [options.onNavigated] - Called with the target URL after navigation
     * @param {function} [options.onFailed] - Called when SSO fails and retries are exhausted
     * @returns {function} cleanup - Call to remove all listeners and timers
     */
    setupSsoMonitoring(options) {
        const {
            chatwootBaseUrl,
            pendingPath,
            ssoUrl,
            getIframe,
            onConfirmed,
            onNavigated,
            onFailed,
        } = options;

        let cleaned = false;
        let readyTimeout = null;
        let retrying = false;

        const cleanup = () => {
            if (cleaned) return;
            cleaned = true;
            window.removeEventListener("message", messageHandler);
            if (readyTimeout) {
                clearTimeout(readyTimeout);
                readyTimeout = null;
            }
        };

        const navigateToTarget = () => {
            const iframe = getIframe();
            if (!iframe) return;

            if (pendingPath) {
                const targetUrl = `${chatwootBaseUrl}${pendingPath}`;
                iframe.src = targetUrl;
                if (onNavigated) onNavigated(targetUrl);
            }
        };

        const handleSsoSuccess = () => {
            this.confirmAuthenticated();
            navigateToTarget();
            if (onConfirmed) onConfirmed();
            cleanup();
        };

        const handleSsoFailure = async () => {
            if (retrying || cleaned) return;
            retrying = true;

            this.clearAuthState();
            this._retryCount++;

            if (this._retryCount > MAX_RETRIES) {
                console.error(
                    "ChatwootSsoManager: Max SSO retries exceeded (" +
                        MAX_RETRIES +
                        ")",
                );
                if (onFailed) onFailed();
                cleanup();
                return;
            }

            console.log(
                "ChatwootSsoManager: SSO failed, retrying (" +
                    this._retryCount +
                    "/" +
                    MAX_RETRIES +
                    ")",
            );

            // Fetch a fresh SSO URL
            const freshSsoUrl = await this.fetchFreshSsoUrl();

            if (freshSsoUrl && !cleaned) {
                const iframe = getIframe();
                if (iframe) {
                    retrying = false;

                    // Re-setup monitoring for the new attempt (recursive, but bounded by MAX_RETRIES)
                    const retryCleanup = this.setupSsoMonitoring({
                        chatwootBaseUrl,
                        pendingPath,
                        ssoUrl: freshSsoUrl,
                        getIframe,
                        onConfirmed,
                        onNavigated,
                        onFailed,
                    });

                    // Load the fresh SSO URL
                    iframe.src = freshSsoUrl;

                    // Store the retry cleanup so the caller's cleanup also cleans this
                    this._lastRetryCleanup = retryCleanup;
                }
            } else {
                console.error(
                    "ChatwootSsoManager: Could not fetch fresh SSO URL",
                );
                if (onFailed) onFailed();
                cleanup();
            }
        };

        const messageHandler = (event) => {
            if (!event.data || event.data.type !== "CHATWOOT_READY") return;

            const initialPath = event.data.initialPath || "";

            // Check if the Chatwoot app landed on the login page (SSO failed)
            if (initialPath.includes("/app/login")) {
                console.warn(
                    "ChatwootSsoManager: CHATWOOT_READY received but on login page - SSO failed",
                );
                handleSsoFailure();
                return;
            }

            // SSO succeeded - Chatwoot loaded a real app page
            console.log(
                "ChatwootSsoManager: CHATWOOT_READY received, SSO confirmed (path: " +
                    initialPath +
                    ")",
            );
            handleSsoSuccess();
        };

        window.addEventListener("message", messageHandler);

        // Timeout fallback: if no CHATWOOT_READY within timeout, assume SSO worked
        // and try to navigate directly. This handles cases where the bridge
        // can't send messages (origin mismatch, etc.)
        readyTimeout = setTimeout(() => {
            if (cleaned) return;

            console.warn(
                "ChatwootSsoManager: CHATWOOT_READY not received within " +
                    READY_TIMEOUT_MS +
                    "ms, attempting direct navigation",
            );

            // Optimistically assume SSO succeeded (the redirect happened but bridge didn't communicate)
            handleSsoSuccess();
        }, READY_TIMEOUT_MS);

        return () => {
            cleanup();
            // Also cleanup any retry monitoring
            if (this._lastRetryCleanup) {
                this._lastRetryCleanup();
                this._lastRetryCleanup = null;
            }
        };
    }

    /**
     * Fetch a fresh SSO URL from the server.
     * This calls a lightweight API endpoint that generates a new single-use SSO token.
     *
     * @returns {Promise<string|null>} The SSO URL or null on failure
     */
    async fetchFreshSsoUrl() {
        try {
            const response = await Espo.Ajax.getRequest(
                "ChatwootSso/freshUrl",
            );
            return response?.ssoUrl || null;
        } catch (e) {
            console.error(
                "ChatwootSsoManager: Failed to fetch fresh SSO URL:",
                e,
            );
            return null;
        }
    }
}

// Export a singleton instance so all views share the same SSO state
const instance = new ChatwootSsoManager();
export default instance;
