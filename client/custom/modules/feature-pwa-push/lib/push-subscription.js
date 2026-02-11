/**
 * EspoCRM PWA Push Subscription Client
 *
 * This module handles push notification subscription on the client side.
 * Loaded via app/client.json scriptList.
 */

(function () {
    "use strict";

    /**
     * Check if service workers and push notifications are supported.
     */
    function isSupported() {
        return "serviceWorker" in navigator && "PushManager" in window;
    }

    /**
     * Register the service worker.
     * Uses the EspoCRM EntryPoint mechanism to serve the SW script.
     */
    async function registerServiceWorker() {
        if (!isSupported()) {
            console.warn(
                "[PWA] Service workers or push notifications not supported",
            );
            return null;
        }

        try {
            const registration = await navigator.serviceWorker.register(
                "/?entryPoint=pwaServiceWorker",
                {
                    scope: "/",
                },
            );
            console.log("[PWA] Service worker registered:", registration.scope);
            return registration;
        } catch (error) {
            console.error("[PWA] Service worker registration failed:", error);
            throw error;
        }
    }

    /**
     * Get the VAPID public key from the server.
     */
    async function getVapidPublicKey() {
        const response = await fetch(
            "/api/v1/PushSubscription/action/vapidPublicKey",
            {
                method: "GET",
                headers: {
                    "Content-Type": "application/json",
                },
            },
        );

        if (!response.ok) {
            throw new Error("Failed to get VAPID public key");
        }

        const data = await response.json();
        return data.publicKey;
    }

    /**
     * Subscribe to push notifications.
     */
    async function subscribe() {
        if (!isSupported()) {
            console.warn("[PWA] Push notifications not supported");
            return null;
        }

        try {
            // Request notification permission
            const permission = await Notification.requestPermission();
            if (permission !== "granted") {
                console.warn("[PWA] Notification permission denied");
                return null;
            }

            // Register service worker
            const registration = await registerServiceWorker();
            if (!registration) {
                return null;
            }

            // Get VAPID public key
            const vapidPublicKey = await getVapidPublicKey();
            if (!vapidPublicKey) {
                throw new Error("VAPID public key not available");
            }

            // Subscribe to push manager
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
            });

            // Send subscription to server
            const response = await fetch(
                "/api/v1/PushSubscription/action/subscribe",
                {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                    },
                    body: JSON.stringify({
                        endpoint: subscription.endpoint,
                        keys: {
                            p256dh: arrayBufferToBase64(
                                subscription.getKey("p256dh"),
                            ),
                            auth: arrayBufferToBase64(
                                subscription.getKey("auth"),
                            ),
                        },
                        userAgent: navigator.userAgent,
                        deviceName: getDeviceName(),
                    }),
                },
            );

            if (!response.ok) {
                throw new Error("Failed to save subscription on server");
            }

            console.log("[PWA] Push subscription successful");
            return subscription;
        } catch (error) {
            console.error("[PWA] Push subscription failed:", error);
            throw error;
        }
    }

    /**
     * Unsubscribe from push notifications.
     */
    async function unsubscribe() {
        if (!isSupported()) {
            return true;
        }

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription =
                await registration.pushManager.getSubscription();

            if (subscription) {
                // Notify server
                await fetch("/api/v1/PushSubscription/action/unsubscribe", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                    },
                    body: JSON.stringify({
                        endpoint: subscription.endpoint,
                    }),
                });

                // Unsubscribe from push manager
                await subscription.unsubscribe();
                console.log("[PWA] Push unsubscribed");
            }

            return true;
        } catch (error) {
            console.error("[PWA] Push unsubscription failed:", error);
            throw error;
        }
    }

    /**
     * Check if currently subscribed.
     */
    async function isSubscribed() {
        if (!isSupported()) {
            return false;
        }

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription =
                await registration.pushManager.getSubscription();
            return subscription !== null;
        } catch (error) {
            console.error("[PWA] Failed to check subscription status:", error);
            return false;
        }
    }

    /**
     * Get user's subscriptions.
     */
    async function getMySubscriptions() {
        const response = await fetch(
            "/api/v1/PushSubscription/action/mySubscriptions",
            {
                method: "GET",
                headers: {
                    "Content-Type": "application/json",
                },
            },
        );

        if (!response.ok) {
            throw new Error("Failed to get subscriptions");
        }

        const data = await response.json();
        return data.list || [];
    }

    /**
     * Convert base64 URL to Uint8Array.
     */
    function urlBase64ToUint8Array(base64String) {
        const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
        const base64 = (base64String + padding)
            .replace(/\-/g, "+")
            .replace(/_/g, "/");

        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    /**
     * Convert an ArrayBuffer to a base64 string.
     */
    function arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = "";
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary);
    }

    /**
     * Get a friendly device name.
     */
    function getDeviceName() {
        const ua = navigator.userAgent;

        if (/iPhone/.test(ua)) {
            return "iPhone";
        }
        if (/iPad/.test(ua)) {
            return "iPad";
        }
        if (/Android/.test(ua)) {
            if (/Mobile/.test(ua)) {
                return "Android Phone";
            }
            return "Android Tablet";
        }
        if (/Windows/.test(ua)) {
            return "Windows PC";
        }
        if (/Mac/.test(ua)) {
            return "Mac";
        }
        if (/Linux/.test(ua)) {
            return "Linux";
        }

        return "Unknown Device";
    }

    // Export to global scope
    window.EspoPwaPush = {
        isSupported,
        subscribe,
        unsubscribe,
        isSubscribed,
        getMySubscriptions,
        registerServiceWorker,
    };

    /**
     * Add the PWA manifest link tag to the document head.
     */
    function addManifestLink() {
        if (document.querySelector('link[rel="manifest"]')) {
            return; // Already exists
        }

        const link = document.createElement("link");
        link.rel = "manifest";
        link.href = "/?entryPoint=pwaManifest";
        document.head.appendChild(link);
        console.log("[PWA] Manifest link added");
    }

    /**
     * Detect if running on a mobile device.
     */
    function isMobile() {
        return /Android|iPhone|iPad|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i.test(
            navigator.userAgent,
        );
    }

    /**
     * Check if user is logged in by waiting for the EspoCRM app to load.
     */
    function waitForAuth() {
        return new Promise(function (resolve) {
            var checks = 0;
            var interval = setInterval(function () {
                checks++;
                if (
                    window.app &&
                    window.app.user &&
                    window.app.user.id
                ) {
                    clearInterval(interval);
                    resolve(true);
                }
                // Give up after 30 seconds
                if (checks > 60) {
                    clearInterval(interval);
                    resolve(false);
                }
            }, 500);
        });
    }

    /**
     * Show a prompt banner asking the user to enable push notifications.
     */
    function showNotificationPrompt() {
        // Don't show if already dismissed this session
        if (sessionStorage.getItem("pwa-push-prompt-dismissed")) {
            return;
        }

        var banner = document.createElement("div");
        banner.id = "pwa-push-prompt";
        banner.innerHTML =
            '<div style="' +
            "position:fixed;bottom:0;left:0;right:0;z-index:100000;" +
            "background:#1976d2;color:#fff;padding:16px 20px;" +
            "display:flex;align-items:center;justify-content:space-between;" +
            "gap:12px;font-family:-apple-system,BlinkMacSystemFont,sans-serif;" +
            "font-size:14px;box-shadow:0 -2px 10px rgba(0,0,0,0.2);" +
            '">' +
            '<div style="flex:1;">' +
            '<div style="font-weight:600;margin-bottom:2px;">Enable Notifications</div>' +
            '<div style="opacity:0.9;font-size:13px;">Get notified about assignments, messages and updates.</div>' +
            "</div>" +
            '<div style="display:flex;gap:8px;flex-shrink:0;">' +
            '<button id="pwa-push-prompt-dismiss" style="' +
            "background:transparent;color:#fff;border:1px solid rgba(255,255,255,0.4);" +
            "padding:8px 16px;border-radius:6px;font-size:13px;cursor:pointer;" +
            '">Later</button>' +
            '<button id="pwa-push-prompt-enable" style="' +
            "background:#fff;color:#1976d2;border:none;" +
            "padding:8px 16px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;" +
            '">Enable</button>' +
            "</div>" +
            "</div>";

        document.body.appendChild(banner);

        document
            .getElementById("pwa-push-prompt-enable")
            .addEventListener("click", function () {
                banner.remove();
                subscribe().catch(function (err) {
                    console.error("[PWA] Subscription from prompt failed:", err);
                });
            });

        document
            .getElementById("pwa-push-prompt-dismiss")
            .addEventListener("click", function () {
                banner.remove();
                sessionStorage.setItem("pwa-push-prompt-dismissed", "1");
            });
    }

    /**
     * Auto-prompt for push notifications on mobile if not yet subscribed.
     */
    async function autoPromptMobile() {
        if (!isSupported()) {
            return;
        }

        // Wait for user to be logged in
        var loggedIn = await waitForAuth();
        if (!loggedIn) {
            return;
        }

        // Check if already subscribed
        var alreadySubscribed = await isSubscribed();
        if (alreadySubscribed) {
            return;
        }

        // Check current permission state
        if (Notification.permission === "denied") {
            // User previously blocked — can't prompt again
            return;
        }

        // Show the prompt banner
        showNotificationPrompt();
    }

    /**
     * Initialize PWA: add manifest link, register service worker,
     * and auto-prompt on mobile.
     */
    function initPwa() {
        addManifestLink();

        if (isSupported()) {
            registerServiceWorker().catch(console.error);
            autoPromptMobile();
        }
    }

    // Auto-initialize on page load
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initPwa);
    } else {
        initPwa();
    }
})();
