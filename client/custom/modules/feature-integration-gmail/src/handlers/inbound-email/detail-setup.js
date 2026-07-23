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
 * InboundEmail detail/edit setup for Gmail OAuth.
 * Mirrors the EmailAccount handler for group mailboxes.
 */
define("feature-integration-gmail:handlers/inbound-email/detail-setup", [], () => {
	const IMAP_HOST = "imap.gmail.com";
	const IMAP_PORT = 993;
	const IMAP_SECURITY = "SSL";

	const SMTP_HOST = "smtp.gmail.com";
	const SMTP_PORT = 587;
	const SMTP_SECURITY = "TLS";

	const PROVIDER_ID = "msx_gmail_01";
	const GOOGLE_AUTH_URL_PREFIX = "https://accounts.google.com/o/oauth2/v2/auth";

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

				this.maybeApplyGmailDefaults(model, oAuthAccountId);
			});
		}

		async maybeApplyGmailDefaults(model, oAuthAccountId) {
			try {
				const account = await this.view.getModelFactory().create("OAuthAccount");
				account.id = oAuthAccountId;
				await account.fetch();

				if (!this.isGmail(account)) {
					return;
				}

				this.applyGmailDefaults(model);
			} catch (_e) {
				// Ignore — server hook will still apply on save if provider matches.
			}
		}

		isGmail(account) {
			if (account.get("providerId") === PROVIDER_ID) {
				return true;
			}

			if (account.get("providerName") === "Google Gmail") {
				return true;
			}

			const data = account.get("data") || {};
			const endpoint =
				typeof data.endpoint === "string" ? data.endpoint : "";

			return endpoint.indexOf(GOOGLE_AUTH_URL_PREFIX) === 0;
		}

		applyGmailDefaults(model) {
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
