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

				this.applyGmailDefaults(model);
			});
		}

		applyGmailDefaults(model) {
			const emailAddress = model.get("emailAddress");

			const host = model.get("host");
			if (!host || host === IMAP_HOST) {
				model.set({
					host: IMAP_HOST,
					port: IMAP_PORT,
					security: IMAP_SECURITY,
				});
			}

			const smtpHost = model.get("smtpHost");
			if (!smtpHost || smtpHost === SMTP_HOST) {
				model.set({
					smtpHost: SMTP_HOST,
					smtpPort: SMTP_PORT,
					smtpSecurity: SMTP_SECURITY,
					smtpAuth: true,
					useSmtp: true,
				});
			}

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
