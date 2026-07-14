/************************************************************************
 * Chatwoot → CRM logout bridge
 * When Chatwoot (iframe) posts CHATWOOT_LOGOUT, destroy the CRM session.
 ************************************************************************/

(function () {
    "use strict";

    function isChatwootOrigin(origin) {
        try {
            return new URL(origin).hostname.startsWith("chat.");
        } catch (e) {
            return false;
        }
    }

    function getAuthToken() {
        var cookieMatch = document.cookie.match(/(?:^|;\s*)auth-token=([^;]*)/);
        if (cookieMatch) {
            return decodeURIComponent(cookieMatch[1]);
        }

        try {
            var stored = localStorage.getItem("espo-user-auth");
            if (!stored) {
                return null;
            }
            var decoded = atob(stored);
            var sep = decoded.indexOf(":");
            if (sep === -1) {
                return null;
            }
            return decoded.substring(sep + 1);
        } catch (e) {
            return null;
        }
    }

    function clearAuthCookie() {
        var host = window.location.hostname;
        var parts = host.split(".");
        var domainParam = "";
        if (parts.length > 2) {
            parts.shift();
            domainParam = "; domain=." + parts.join(".");
        }
        document.cookie =
            "auth-token=; SameSite=Lax; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/" +
            domainParam;
    }

    function clearLocalAuth() {
        try {
            localStorage.removeItem("espo-user-auth");
            localStorage.removeItem("espo-user-anotherUser");
            sessionStorage.removeItem("chatwoot_sso_authenticated");
        } catch (e) {
            // ignore
        }
        clearAuthCookie();
    }

    function destroyAuthToken(token) {
        if (!token) {
            return Promise.resolve();
        }
        return fetch("api/v1/App/destroyAuthToken", {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
            },
            body: JSON.stringify({ token: token }),
        }).catch(function () {
            // continue local session wipe even if request fails
        });
    }

    function handleChatwootLogout() {
        console.log("EspoCRM: Received CHATWOOT_LOGOUT from Chatwoot");
        var token = getAuthToken();
        destroyAuthToken(token).then(function () {
            clearLocalAuth();
            window.location.href =
                window.location.pathname + window.location.search;
        });
    }

    window.addEventListener("message", function (event) {
        if (!event.data || event.data.type !== "CHATWOOT_LOGOUT") {
            return;
        }
        if (!isChatwootOrigin(event.origin)) {
            return;
        }
        handleChatwootLogout();
    });

    console.log("EspoCRM: Chatwoot logout listener ready");
})();
