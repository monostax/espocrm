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
 * InboundEmail detail/edit setup for Microsoft 365 OAuth.
 * Mirrors the EmailAccount handler for group mailboxes.
 */
define("feature-integration-microsoft-365:handlers/inbound-email/detail-setup", [], () => {
	const IMAP_HOST = "outlook.office365.com";
	const IMAP_PORT = 993;
	const IMAP_SECURITY = "SSL";

	const SMTP_HOST = "smtp.office365.com";
	const SMTP_PORT = 587;
	const SMTP_SECURITY = "TLS";

	const PROVIDER_ID = "msx_m365_01";

	return class {
		constructor(view) {
			this.view = view;
		}

		process() {
			const view = this.view;
			const model = view.model;

			view.listenTo(model, "change:oAuthAccountId", () => {
				if (!model.hasChanged("oAuthAccountId")) {
					return;
				}

				const oAuthAccountId = model.get("oAuthAccountId");

				if (!oAuthAccountId) {
					return;
				}

				this.maybeApplyMicrosoft365Defaults(model, oAuthAccountId);
			});
		}

		async maybeApplyMicrosoft365Defaults(model, oAuthAccountId) {
			try {
				const account = await this.view.getModelFactory().create("OAuthAccount");
				account.id = oAuthAccountId;
				await account.fetch();

				if (!this.isMicrosoft365(account)) {
					return;
				}

				this.applyMicrosoft365Defaults(model);
			} catch (_e) {
				// Ignore — server hook will still apply on save if provider matches.
			}
		}

		isMicrosoft365(account) {
			if (account.get("providerId") === PROVIDER_ID) {
				return true;
			}

			if (account.get("providerName") === "Microsoft 365") {
				return true;
			}

			const data = account.get("data") || {};
			const endpoint =
				typeof data.endpoint === "string" ? data.endpoint : "";

			return endpoint.indexOf("https://login.microsoftonline.com/") === 0;
		}

		applyMicrosoft365Defaults(model) {
			const emailAddress = model.get("emailAddress");

			model.set({
				host: IMAP_HOST,
				port: IMAP_PORT,
				security: IMAP_SECURITY,
				smtpHost: SMTP_HOST,
				smtpPort: SMTP_PORT,
				smtpSecurity: SMTP_SECURITY,
				smtpAuth: true,
				useSmtp: true,
			});

			if (emailAddress) {
				if (!model.get("username") || model.get("username") === emailAddress) {
					model.set("username", emailAddress);
				}

				if (
					!model.get("smtpUsername") ||
					model.get("smtpUsername") === emailAddress
				) {
					model.set("smtpUsername", emailAddress);
				}
			}

			model.set({
				password: null,
				smtpPassword: null,
			});
		}
	};
});
