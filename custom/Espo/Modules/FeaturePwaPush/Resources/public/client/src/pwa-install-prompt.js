/**
 * EspoCRM PWA Install Prompt
 *
 * This module handles the PWA install prompt UI.
 */

(function () {
    "use strict";

    let deferredPrompt;
    let installButton;

    /**
     * Initialize the PWA install prompt.
     */
    function init() {
        // Listen for the beforeinstallprompt event
        window.addEventListener("beforeinstallprompt", (event) => {
            console.log("[PWA] Install prompt available");
            event.preventDefault();
            deferredPrompt = event;
            showInstallButton();
        });

        // Listen for app installed event
        window.addEventListener("appinstalled", () => {
            console.log("[PWA] App installed");
            hideInstallButton();
            deferredPrompt = null;
        });

        // Check if already installed
        if (window.matchMedia("(display-mode: standalone)").matches) {
            console.log("[PWA] Running in standalone mode");
        }
    }

    /**
     * Show the install button.
     */
    function showInstallButton() {
        // Create install button if it doesn't exist
        if (!installButton) {
            installButton = document.createElement("button");
            installButton.id = "pwa-install-button";
            installButton.innerHTML = `
                <span class="pwa-install-icon">📱</span>
                <span class="pwa-install-text">Install App</span>
            `;
            installButton.onclick = promptInstall;

            // Add styles
            const style = document.createElement("style");
            style.textContent = `
                #pwa-install-button {
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    background: linear-gradient(135deg, #2196f3, #1976d2);
                    color: white;
                    border: none;
                    border-radius: 25px;
                    padding: 12px 24px;
                    font-size: 14px;
                    font-weight: 500;
                    cursor: pointer;
                    box-shadow: 0 4px 12px rgba(33, 150, 243, 0.4);
                    z-index: 9999;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    transition: transform 0.2s, box-shadow 0.2s;
                }
                #pwa-install-button:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 16px rgba(33, 150, 243, 0.5);
                }
                #pwa-install-button:active {
                    transform: translateY(0);
                }
                .pwa-install-icon {
                    font-size: 18px;
                }
                @media (max-width: 480px) {
                    #pwa-install-button {
                        bottom: 10px;
                        right: 10px;
                        padding: 10px 18px;
                        font-size: 13px;
                    }
                }
            `;
            document.head.appendChild(style);
        }

        document.body.appendChild(installButton);
    }

    /**
     * Hide the install button.
     */
    function hideInstallButton() {
        if (installButton && installButton.parentNode) {
            installButton.parentNode.removeChild(installButton);
        }
    }

    /**
     * Prompt the user to install the PWA.
     */
    async function promptInstall() {
        if (!deferredPrompt) {
            console.warn("[PWA] No install prompt available");
            return;
        }

        // Show the install prompt
        deferredPrompt.prompt();

        // Wait for the user's response
        const { outcome } = await deferredPrompt.userChoice;
        console.log("[PWA] Install prompt outcome:", outcome);

        // Clear the deferred prompt
        deferredPrompt = null;
        hideInstallButton();
    }

    /**
     * Check if the app can be installed.
     */
    function canInstall() {
        return deferredPrompt !== null;
    }

    /**
     * Check if running as installed PWA.
     */
    function isStandalone() {
        return (
            window.matchMedia("(display-mode: standalone)").matches ||
            window.navigator.standalone === true
        );
    }

    // Export to global scope
    window.EspoPwaInstall = {
        init,
        promptInstall,
        canInstall,
        isStandalone,
        showInstallButton,
        hideInstallButton,
    };

    // Auto-initialize
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();

