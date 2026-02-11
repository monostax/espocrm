/**
 * EspoCRM PWA Push Subscription Client
 *
 * This module handles push notification subscription on the client side.
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
                "/pwa-sw.js",
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
                            p256dh: btoa(
                                String.fromCharCode.apply(
                                    null,
                                    subscription.getKey("p256dh"),
                                ),
                            ),
                            auth: btoa(
                                String.fromCharCode.apply(
                                    null,
                                    subscription.getKey("auth"),
                                ),
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

    // Auto-register service worker on page load
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", () => {
            if (isSupported()) {
                registerServiceWorker().catch(console.error);
            }
        });
    } else {
        if (isSupported()) {
            registerServiceWorker().catch(console.error);
        }
    }
})();

