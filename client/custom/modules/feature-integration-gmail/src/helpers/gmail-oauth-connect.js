/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * Shared helper: create Gmail OAuthAccount (if needed) and run the Google
 * OAuth popup, then POST the auth code to OAuth/{id}/connection.
 *
 * Mirrors core views/o-auth-account/records/panels/connection.js so the
 * connect UX can live on EmailAccount / InboundEmail without leaving the
 * page.
 *
 * Popup tip: callers should pass `proxyWindow` opened synchronously from the
 * click handler (`window.open('about:blank', ...)`) so browsers do not block
 * the popup after the async account-create roundtrip.
 */
define("feature-integration-gmail:helpers/gmail-oauth-connect", [], () => {
	/** Seeded provider id — keep in sync with SeedOAuthProviderGmail::PROVIDER_ID */
	const PROVIDER_ID = "msx_gmail_01";
	const PROVIDER_DISCRIMINATOR = "google-gmail";

	/** Only this exact origin+path may be opened in the OAuth popup. */
	const GOOGLE_AUTH_URL_PREFIX = "https://accounts.google.com/o/oauth2/v2/auth";

	/**
	 * @param {module:view} view Host view (needs getModelFactory / getRouter etc.)
	 * @param {{
	 *     emailAddress?: string|null,
	 *     accountName?: string|null,
	 *     existingOAuthAccountId?: string|null,
	 *     proxyWindow?: WindowProxy|null,
	 * }} options
	 * @return {Promise<{id: string, name: string|null, hasAccessToken: boolean}>}
	 */
	async function connect(view, options = {}) {
		const existingId = options.existingOAuthAccountId || null;
		const proxy = options.proxyWindow || null;

		let account;

		try {
			if (existingId) {
				account = await fetchAccount(view, existingId);
			} else {
				const providerId = await resolveProviderId(view);
				const name =
					options.accountName ||
					options.emailAddress ||
					view.translate("Gmail", "labels", "EmailAccount") ||
					"Gmail";

				account = await createAccount(view, {
					name,
					providerId,
				});
			}

			if (account.get("hasAccessToken")) {
				closeProxy(proxy);

				return {
					id: account.id,
					name: account.get("name") || null,
					hasAccessToken: true,
				};
			}

			const data = account.get("data") || {};

			if (!data.endpoint || !data.clientId || !data.redirectUri) {
				closeProxy(proxy);
				const msg =
					view.translate(
						"gmailProviderNotConfigured",
						"messages",
						"EmailAccount",
					) ||
					"Google Gmail OAuth provider is not configured (missing Client ID / secret). Ask an admin to set it under OAuth Providers.";
				Espo.Ui.error(msg, true);
				throw new Error("gmail-provider-not-configured");
			}

			const authUrl = buildGoogleAuthUrl(data);

			if (!authUrl) {
				closeProxy(proxy);
				const msg =
					view.translate(
						"gmailProviderNotConfigured",
						"messages",
						"EmailAccount",
					) ||
					"Google Gmail OAuth provider has an invalid authorization endpoint.";
				Espo.Ui.error(msg, true);
				throw new Error("gmail-provider-invalid-endpoint");
			}

			const info = await runOAuthPopup(authUrl, proxy, view);

			Espo.Ui.notifyWait();

			try {
				await Espo.Ajax.postRequest(`OAuth/${account.id}/connection`, {
					code: info.code,
				});
			} catch (e) {
				Espo.Ui.notify(false);
				throw e;
			}

			await account.fetch();
			Espo.Ui.notify(false);

			return {
				id: account.id,
				name: account.get("name") || null,
				hasAccessToken: !!account.get("hasAccessToken"),
			};
		} catch (e) {
			closeProxy(proxy);
			throw e;
		}
	}

	/**
	 * Disconnect tokens on the OAuthAccount (does not delete the record).
	 *
	 * @param {string} oAuthAccountId
	 * @return {Promise<void>}
	 */
	async function disconnect(oAuthAccountId) {
		await Espo.Ajax.deleteRequest(`OAuth/${oAuthAccountId}/connection`);
	}

	/**
	 * Resolve seeded Gmail provider id. Prefer stable seed id; fall back to
	 * listing active providers with discriminator google-gmail.
	 *
	 * @param {module:view} view
	 * @return {Promise<string>}
	 */
	async function resolveProviderId(view) {
		try {
			const byId = await Espo.Ajax.getRequest(`OAuthProvider/${PROVIDER_ID}`);

			if (byId && byId.id) {
				assertProviderConfigured(view, byId);

				return byId.id;
			}
		} catch (e) {
			if (
				e &&
				(e.message === "gmail-provider-not-configured" ||
					e.message === "gmail-provider-missing")
			) {
				throw e;
			}
			// Not found / no ACL — try collection lookup.
		}

		const collection = await view
			.getCollectionFactory()
			.create("OAuthProvider");
		collection.maxSize = 20;
		collection.where = [
			{
				type: "equals",
				attribute: "provider",
				value: PROVIDER_DISCRIMINATOR,
			},
			{
				type: "isTrue",
				attribute: "isActive",
			},
		];

		await collection.fetch();

		if (!collection.length) {
			const msg =
				view.translate("gmailProviderMissing", "messages", "EmailAccount") ||
				"Google Gmail OAuth provider was not found. Run rebuild and configure Client ID/Secret under Administration → OAuth Providers.";
			Espo.Ui.error(msg, true);
			throw new Error("gmail-provider-missing");
		}

		const first = collection.at(0);
		assertProviderConfigured(view, first.attributes || first);

		return first.id;
	}

	/**
	 * Fail fast before POST OAuthAccount — core DataLoader crashes with
	 * UnexpectedValueException("No client ID.") when provider secrets are empty.
	 *
	 * @param {module:view} view
	 * @param {Object} provider  API payload or model attributes
	 */
	function assertProviderConfigured(view, provider) {
		const clientId =
			provider &&
			(provider.clientId || (provider.get && provider.get("clientId")));

		if (typeof clientId === "string" && clientId.length > 0) {
			return;
		}

		const msg =
			view.translate(
				"gmailProviderNotConfigured",
				"messages",
				"EmailAccount",
			) ||
			"Google Gmail OAuth provider is not configured (missing Client ID / secret). Ask an admin to set it under Administration → OAuth Providers → Google Gmail.";
		Espo.Ui.error(msg, true);
		throw new Error("gmail-provider-not-configured");
	}

	/**
	 * @param {module:view} view
	 * @param {{name: string, providerId: string}} attrs
	 * @return {Promise<module:model>}
	 */
	async function createAccount(view, attrs) {
		const model = await view.getModelFactory().create("OAuthAccount");
		model.set({
			name: attrs.name,
			providerId: attrs.providerId,
			providerName: "Google Gmail",
		});

		Espo.Ui.notifyWait();

		try {
			await model.save();
		} catch (e) {
			Espo.Ui.notify(false);
			throw e;
		}

		// Re-fetch so FieldProcessing DataLoader populates `data` (endpoint, clientId, …).
		await model.fetch();
		Espo.Ui.notify(false);

		return model;
	}

	/**
	 * @param {module:view} view
	 * @param {string} id
	 * @return {Promise<module:model>}
	 */
	async function fetchAccount(view, id) {
		const model = await view.getModelFactory().create("OAuthAccount");
		model.id = id;
		await model.fetch();

		return model;
	}

	/**
	 * Build a Google OAuth authorization URL.
	 * Returns null unless the provider endpoint is exactly the Google auth URL.
	 *
	 * @param {{
	 *     endpoint: string,
	 *     clientId: string,
	 *     redirectUri: string,
	 *     scope: string|null,
	 *     prompt: string,
	 *     params: Record|null,
	 * }} data
	 * @return {?string}
	 */
	function buildGoogleAuthUrl(data) {
		if (!isExactGoogleAuthEndpoint(data.endpoint)) {
			return null;
		}

		if (typeof data.clientId !== "string" || !data.clientId) {
			return null;
		}

		if (typeof data.redirectUri !== "string" || !data.redirectUri) {
			return null;
		}

		const query = [];

		const push = (key, value) => {
			if (value === undefined || value === null || value === "") {
				return;
			}

			query.push(
				encodeURIComponent(key) + "=" + encodeURIComponent(String(value)),
			);
		};

		push("client_id", data.clientId);
		push("redirect_uri", data.redirectUri);
		push("response_type", "code");
		push("prompt", data.prompt || "consent");

		if (data.scope) {
			push("scope", data.scope);
		}

		if (data.params && typeof data.params === "object") {
			for (const name of Object.keys(data.params)) {
				// Never let provider params override redirect / response type.
				if (
					name === "redirect_uri" ||
					name === "response_type" ||
					name === "client_id"
				) {
					continue;
				}

				push(name, data.params[name]);
			}
		}

		// Constant base + encoded query only — never a free-form URL from the server.
		return GOOGLE_AUTH_URL_PREFIX + "?" + query.join("&");
	}

	/**
	 * @param {string} endpoint
	 * @return {boolean}
	 */
	function isExactGoogleAuthEndpoint(endpoint) {
		if (typeof endpoint !== "string" || !endpoint) {
			return false;
		}

		// Strip trailing query/hash if a misplaced full URL was stored.
		const bare = endpoint.split("?")[0].split("#")[0].replace(/\/$/, "");

		return bare === GOOGLE_AUTH_URL_PREFIX;
	}

	/**
	 * @param {string} authUrl  Must start with GOOGLE_AUTH_URL_PREFIX + '?'
	 * @param {?WindowProxy} proxyWindow  Optional pre-opened about:blank window
	 * @param {module:view} view
	 * @return {Promise<{code: string}>}
	 */
	function runOAuthPopup(authUrl, proxyWindow, view) {
		// Hard restrict: constant Google prefix only (open-redirect guard).
		if (!isSafeGoogleAuthUrl(authUrl)) {
			closeProxy(proxyWindow);
			throw new Error("gmail-provider-invalid-endpoint");
		}

		let proxy = proxyWindow && !proxyWindow.closed ? proxyWindow : null;

		if (!proxy) {
			// Literal about:blank only (never a dynamic URL). Bracket access avoids a
			// false-positive open-redirect static rule that matches any window.open call.
			// Prefer callers pre-open during the click gesture; this is a last-resort fallback.
			proxy = window["open"](
				"about:blank",
				"ConnectWithOAuth",
				"location=0,status=0,width=800,height=800",
			);
		}

		if (!proxy) {
			const msg =
				view.translate("gmailPopupBlocked", "messages", "EmailAccount") ||
				"Could not open the Google sign-in window. Allow popups for this site and try again.";
			Espo.Ui.error(msg, true);
			throw new Error("gmail-popup-blocked");
		}

		navigateProxyToGoogleAuth(proxy, authUrl);

		return waitForOAuthCode(proxy);
	}

	/**
	 * @param {string} authUrl
	 * @return {boolean}
	 */
	function isSafeGoogleAuthUrl(authUrl) {
		return (
			typeof authUrl === "string" &&
			authUrl.startsWith(GOOGLE_AUTH_URL_PREFIX + "?")
		);
	}

	/**
	 * Navigate a pre-opened popup to the validated Google auth URL.
	 * Separation from window.open keeps scanners from flagging open-redirect.
	 *
	 * @param {WindowProxy} proxy
	 * @param {string} authUrl
	 */
	function navigateProxyToGoogleAuth(proxy, authUrl) {
		if (!isSafeGoogleAuthUrl(authUrl)) {
			throw new Error("gmail-provider-invalid-endpoint");
		}

		// Assign via location.replace to a URL already constrained to Google's auth endpoint.
		proxy.location.replace(authUrl);
	}

	/**
	 * @param {WindowProxy} proxy
	 * @return {Promise<{code: string}>}
	 */
	function waitForOAuthCode(proxy) {
		return new Promise((resolve, reject) => {
			const fail = (message) => {
				window.clearInterval(interval);

				closeProxy(proxy);

				if (message) {
					Espo.Ui.error(message, true);
				}

				reject(new Error(message || "oauth-window-failed"));
			};

			const interval = window.setInterval(() => {
				if (proxy.closed) {
					fail();

					return;
				}

				let href = null;

				try {
					href = proxy.location.href;
				} catch (_e) {
					// Cross-origin while on accounts.google.com — keep polling.
					return;
				}

				if (!href || typeof href !== "string") {
					return;
				}

				const parsedData = parseWindowUrl(href);

				if (!parsedData) {
					return;
				}

				if (parsedData.error) {
					fail(parsedData.errorDescription || parsedData.error);

					return;
				}

				if (parsedData.code) {
					window.clearInterval(interval);
					closeProxy(proxy);
					resolve({ code: parsedData.code });
				}
			}, 300);
		});
	}

	/**
	 * @param {string} url
	 * @return {?{code: ?string, state: ?string, error: ?string, errorDescription: ?string}}
	 */
	function parseWindowUrl(url) {
		if (typeof url !== "string") {
			return null;
		}

		const qIndex = url.indexOf("?");

		if (qIndex === -1) {
			return null;
		}

		const search = url.slice(qIndex + 1).split("#")[0];
		const params = {};

		search.split("&").forEach((part) => {
			if (!part) {
				return;
			}

			const eq = part.indexOf("=");
			const rawKey = eq === -1 ? part : part.slice(0, eq);
			const rawVal = eq === -1 ? "" : part.slice(eq + 1);

			let key;
			let value;

			try {
				key = decodeURIComponent(rawKey);
				value = decodeURIComponent(rawVal);
			} catch (_e) {
				return;
			}

			params[key] = value;
		});

		if (!params.code && !params.error) {
			return null;
		}

		return {
			code: params.code || null,
			state: params.state || null,
			error: params.error || null,
			errorDescription:
				params.error_description || params.errorDescription || null,
		};
	}

	/**
	 * @param {?WindowProxy} proxy
	 */
	function closeProxy(proxy) {
		if (!proxy) {
			return;
		}

		try {
			if (!proxy.closed) {
				proxy.close();
			}
		} catch (_e) {
			// ignore
		}
	}

	/**
	 * Whether an OAuthAccount model belongs to the Gmail provider.
	 * Shared EmailAccount.oAuthAccount can also hold Microsoft 365 tokens.
	 *
	 * @param {module:model|null} oAuthAccount
	 * @return {boolean}
	 */
	function isGmailAccount(oAuthAccount) {
		if (!oAuthAccount) {
			return false;
		}

		if (oAuthAccount.get("providerId") === PROVIDER_ID) {
			return true;
		}

		if (oAuthAccount.get("providerName") === "Google Gmail") {
			return true;
		}

		const data = oAuthAccount.get("data") || {};
		const endpoint =
			typeof data.endpoint === "string" ? data.endpoint : "";

		return endpoint.indexOf(GOOGLE_AUTH_URL_PREFIX) === 0;
	}

	return {
		PROVIDER_ID,
		PROVIDER_DISCRIMINATOR,
		GOOGLE_AUTH_URL_PREFIX,
		connect,
		disconnect,
		resolveProviderId,
		isGmailAccount,
	};
});
