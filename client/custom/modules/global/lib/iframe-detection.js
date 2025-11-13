/************************************************************************
 * Iframe Detection Script
 * Detects if EspoCRM is embedded in an iframe and adds CSS classes
 *
 * This script runs early during page load to add CSS classes that can
 * be used for styling the application differently when embedded.
 ************************************************************************/

(function () {
    "use strict";

    /**
     * Checks if the current window is running inside an iframe
     * @returns {boolean} true if running in iframe, false otherwise
     */
    function isInIframe() {
        try {
            // Check if window is not the top window
            return window.self !== window.top;
        } catch (e) {
            // If we get a security error accessing window.top,
            // it means we're in a cross-origin iframe
            return true;
        }
    }

    /**
     * Applies iframe detection classes to an element
     * @param {HTMLElement} element - The element to apply classes to
     * @param {boolean} isEmbedded - Whether the app is embedded
     */
    function applyClasses(element, isEmbedded) {
        if (isEmbedded) {
            element.classList.add("is-embedded", "is-iframe");
            element.dataset.embedded = "true";
            element.dataset.iframe = "true";
        } else {
            element.classList.remove("is-embedded", "is-iframe");
            delete element.dataset.embedded;
            delete element.dataset.iframe;
        }
    }

    /**
     * Detects and marks iframe embedding
     */
    function detectAndMarkIframeEmbedding() {
        const isEmbedded = isInIframe();
        const htmlElement = document.documentElement;

        // Always apply to html element immediately
        applyClasses(htmlElement, isEmbedded);

        // Apply to body if it exists
        if (document.body) {
            applyClasses(document.body, isEmbedded);
            console.log(
                isEmbedded
                    ? "EspoCRM: Application is embedded in an iframe"
                    : "EspoCRM: Application is NOT embedded"
            );
        } else {
            // Use MutationObserver to watch for body element
            const observer = new MutationObserver((mutations, obs) => {
                if (document.body) {
                    applyClasses(document.body, isEmbedded);
                    console.log(
                        isEmbedded
                            ? "EspoCRM: Application is embedded in an iframe"
                            : "EspoCRM: Application is NOT embedded"
                    );
                    obs.disconnect(); // Stop observing once body is found
                }
            });

            // Start observing the document for child additions
            observer.observe(document.documentElement, {
                childList: true,
                subtree: true,
            });
        }
    }

    // Run immediately - html element always exists at this point
    detectAndMarkIframeEmbedding();
})();

