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
     * Gets the embed type from the URL query parameter
     * @returns {string|null} The embed type, or null if not specified
     */
    function getEmbedType() {
        try {
            var params = new URLSearchParams(window.location.search);
            return params.get("embed");
        } catch (e) {
            return null;
        }
    }

    /**
     * Applies iframe detection classes to an element
     * @param {HTMLElement} element - The element to apply classes to
     * @param {boolean} isEmbedded - Whether the app is embedded
     * @param {string|null} embedType - The embed type from query param
     */
    function applyClasses(element, isEmbedded, embedType) {
        if (isEmbedded) {
            element.classList.add("is-embedded", "is-iframe");
            element.dataset.embedded = "true";
            element.dataset.iframe = "true";

            if (embedType) {
                element.classList.add("is-" + embedType);
                element.dataset.embedType = embedType;
            }
        } else {
            element.classList.remove("is-embedded", "is-iframe");
            delete element.dataset.embedded;
            delete element.dataset.iframe;
            delete element.dataset.embedType;
        }
    }

    /**
     * Detects and marks iframe embedding
     */
    function detectAndMarkIframeEmbedding() {
        const isEmbedded = isInIframe();
        const embedType = getEmbedType();
        const htmlElement = document.documentElement;

        // Always apply to html element immediately
        applyClasses(htmlElement, isEmbedded, embedType);

        // Apply to body if it exists
        if (document.body) {
            applyClasses(document.body, isEmbedded, embedType);
            console.log(
                isEmbedded
                    ? "EspoCRM: Application is embedded in an iframe" + (embedType ? " (type: " + embedType + ")" : "")
                    : "EspoCRM: Application is NOT embedded"
            );
        } else {
            // Use MutationObserver to watch for body element
            const observer = new MutationObserver((mutations, obs) => {
                if (document.body) {
                    applyClasses(document.body, isEmbedded, embedType);
                    console.log(
                        isEmbedded
                            ? "EspoCRM: Application is embedded in an iframe" + (embedType ? " (type: " + embedType + ")" : "")
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

