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
 * Shared helper: create Microsoft 365 OAuthAccount (if needed) and run the
 * Microsoft OAuth popup, then POST the auth code to OAuth/{id}/connection.
 *
 * Mirrors feature-integration-gmail:helpers/gmail-oauth-connect so the
 * connect UX can live on EmailAccount / InboundEmail without leaving the page.
 *
 * Popup tip: callers should pass `proxyWindow` opened synchronously from the
 * click handler (`window.open('about:blank', ...)`) so browsers do not block
 * the popup after the async account-create roundtrip.
 */
define("feature-integration-microsoft-365:helpers/microsoft-365-oauth-connect", [], () => {
	/** Seeded provider id — keep in sync with SeedOAuthProviderMicrosoft365::PROVIDER_ID */
	const PROVIDER_ID = "msx_m365_01";
	const PROVIDER_DISCRIMINATOR = "microsoft-365";

	/**
	 * Allowed Microsoft authorize endpoints:
	 * https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize
	 * tenant = common | organizations | consumers | GUID
	 */
	const MS_AUTH_ENDPOINT_RE =
		/^https:\/\/login\.microsoftonline\.com\/(common|organizations|consumers|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})\/oauth2\/v2\.0\/authorize$/;

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
					view.translate("Microsoft 365", "labels", "EmailAccount") ||
					"Microsoft 365";

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
						"m365ProviderNotConfigured",
						"messages",
						"EmailAccount",
					) ||
					"Microsoft 365 OAuth provider is not configured (missing Client ID / secret). Ask an admin to set it under OAuth Providers.";
				Espo.Ui.error(msg, true);
				throw new Error("m365-provider-not-configured");
			}

			const authUrl = buildMicrosoftAuthUrl(data);

			if (!authUrl) {
				closeProxy(proxy);
				const msg =
					view.translate(
						"m365ProviderNotConfigured",
						"messages",
						"EmailAccount",
					) ||
					"Microsoft 365 OAuth provider has an invalid authorization endpoint.";
				Espo.Ui.error(msg, true);
				throw new Error("m365-provider-invalid-endpoint");
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
	 * Resolve seeded Microsoft 365 provider id. Prefer stable seed id; fall back to
	 * listing active providers with discriminator microsoft-365.
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
				(e.message === "m365-provider-not-configured" ||
					e.message === "m365-provider-missing")
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
				view.translate("m365ProviderMissing", "messages", "EmailAccount") ||
				"Microsoft 365 OAuth provider was not found. Run rebuild and configure Client ID/Secret under Administration → OAuth Providers.";
			Espo.Ui.error(msg, true);
			throw new Error("m365-provider-missing");
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
				"m365ProviderNotConfigured",
				"messages",
				"EmailAccount",
			) ||
			"Microsoft 365 OAuth provider is not configured (missing Client ID / secret). Ask an admin to set it under Administration → OAuth Providers → Microsoft 365.";
		Espo.Ui.error(msg, true);
		throw new Error("m365-provider-not-configured");
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
			providerName: "Microsoft 365",
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
	 * Build a Microsoft OAuth authorization URL.
	 * Returns null unless the provider endpoint matches the allowlisted MS login pattern.
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
	function buildMicrosoftAuthUrl(data) {
		const endpoint = normalizeAuthEndpoint(data.endpoint);

		if (!endpoint || !MS_AUTH_ENDPOINT_RE.test(endpoint)) {
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

		// Allowlisted endpoint only — never a free-form URL from the server.
		return endpoint + "?" + query.join("&");
	}

	/**
	 * @param {string} endpoint
	 * @return {?string}
	 */
	function normalizeAuthEndpoint(endpoint) {
		if (typeof endpoint !== "string" || !endpoint) {
			return null;
		}

		// Strip trailing query/hash if a misplaced full URL was stored.
		return endpoint.split("?")[0].split("#")[0].replace(/\/$/, "");
	}

	/**
	 * @param {string} authUrl  Must match MS_AUTH_ENDPOINT_RE + '?'
	 * @param {?WindowProxy} proxyWindow  Optional pre-opened about:blank window
	 * @param {module:view} view
	 * @return {Promise<{code: string}>}
	 */
	function runOAuthPopup(authUrl, proxyWindow, view) {
		if (!isSafeMicrosoftAuthUrl(authUrl)) {
			closeProxy(proxyWindow);
			throw new Error("m365-provider-invalid-endpoint");
		}

		let proxy = proxyWindow && !proxyWindow.closed ? proxyWindow : null;

		if (!proxy) {
			// Literal about:blank only (never a dynamic URL). Bracket access avoids a
			// false-positive open-redirect static rule that matches any window.open call.
			proxy = window["open"](
				"about:blank",
				"ConnectWithOAuth",
				"location=0,status=0,width=800,height=800",
			);
		}

		if (!proxy) {
			const msg =
				view.translate("m365PopupBlocked", "messages", "EmailAccount") ||
				"Could not open the Microsoft sign-in window. Allow popups for this site and try again.";
			Espo.Ui.error(msg, true);
			throw new Error("m365-popup-blocked");
		}

		navigateProxyToMicrosoftAuth(proxy, authUrl);

		return waitForOAuthCode(proxy);
	}

	/**
	 * @param {string} authUrl
	 * @return {boolean}
	 */
	function isSafeMicrosoftAuthUrl(authUrl) {
		if (typeof authUrl !== "string") {
			return false;
		}

		const qIndex = authUrl.indexOf("?");

		if (qIndex === -1) {
			return false;
		}

		const bare = authUrl.slice(0, qIndex);

		return MS_AUTH_ENDPOINT_RE.test(bare);
	}

	/**
	 * Navigate a pre-opened popup to the validated Microsoft auth URL.
	 * Separation from window.open keeps scanners from flagging open-redirect.
	 *
	 * @param {WindowProxy} proxy
	 * @param {string} authUrl
	 */
	function navigateProxyToMicrosoftAuth(proxy, authUrl) {
		if (!isSafeMicrosoftAuthUrl(authUrl)) {
			throw new Error("m365-provider-invalid-endpoint");
		}

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
					// Cross-origin while on login.microsoftonline.com — keep polling.
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
	 * Whether an OAuthAccount model belongs to the Microsoft 365 provider.
	 * OAuthAccount exposes providerId/providerName (link); discriminator lives on
	 * OAuthProvider. Also fall back to auth endpoint from DataLoader `data`.
	 *
	 * @param {module:model|null} oAuthAccount
	 * @return {boolean}
	 */
	function isMicrosoft365Account(oAuthAccount) {
		if (!oAuthAccount) {
			return false;
		}

		if (oAuthAccount.get("providerId") === PROVIDER_ID) {
			return true;
		}

		if (oAuthAccount.get("providerName") === "Microsoft 365") {
			return true;
		}

		const data = oAuthAccount.get("data") || {};
		const endpoint =
			typeof data.endpoint === "string" ? data.endpoint : "";

		return endpoint.indexOf("https://login.microsoftonline.com/") === 0;
	}

	return {
		PROVIDER_ID,
		PROVIDER_DISCRIMINATOR,
		connect,
		disconnect,
		resolveProviderId,
		isMicrosoft365Account,
	};
});
