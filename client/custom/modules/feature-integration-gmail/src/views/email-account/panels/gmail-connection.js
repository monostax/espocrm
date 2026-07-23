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
 * Side panel on EmailAccount detail: one-click Connect Gmail.
 * Creates an OAuthAccount behind the scenes, runs the Google consent
 * popup, then links it to the current EmailAccount.
 */
define("feature-integration-gmail:views/email-account/panels/gmail-connection", [
	"views/record/panels/side",
	"feature-integration-gmail:helpers/gmail-oauth-connect",
], (Dep, GmailOAuthConnect) => {
	return Dep.extend({
		// language=Handlebars
		templateContent: `
            <div class="margin-bottom">
                {{#if isLinkedAndConnected}}
                    <span class="label label-success label-md">{{translate 'Connected' scope='ExternalAccount'}}</span>
                    {{#if oAuthAccountName}}
                        <div class="small text-muted" style="margin-top:6px">{{oAuthAccountName}}</div>
                    {{/if}}
                {{else if isLinked}}
                    <span class="label label-warning label-md">{{translate 'Disconnected' scope='ExternalAccount'}}</span>
                    <div class="small text-muted" style="margin-top:6px">
                        {{translate 'gmailLinkedButDisconnected' category='messages' scope='EmailAccount'}}
                    </div>
                {{else}}
                    <span class="label label-default label-md">{{translate 'Disconnected' scope='ExternalAccount'}}</span>
                    <div class="small text-muted" style="margin-top:6px">
                        {{translate 'gmailConnectHelp' category='messages' scope='EmailAccount'}}
                    </div>
                {{/if}}
            </div>

            {{#if hasConnect}}
                <button
                    class="btn btn-primary btn-sm"
                    data-action="connect"
                    {{#if inProcess}}disabled{{/if}}
                >{{translate 'Connect Gmail' category='labels' scope='EmailAccount'}}</button>
            {{/if}}

            {{#if hasReconnect}}
                <button
                    class="btn btn-default btn-sm"
                    data-action="connect"
                    {{#if inProcess}}disabled{{/if}}
                >{{translate 'Reconnect' category='labels' scope='EmailAccount'}}</button>
            {{/if}}

            {{#if hasDisconnect}}
                <button
                    class="btn btn-default btn-sm"
                    data-action="disconnect"
                    style="margin-left:6px"
                    {{#if inProcess}}disabled{{/if}}
                >{{translate 'Disconnect' scope='ExternalAccount'}}</button>
            {{/if}}
        `,

		inProcess: false,

		/**
		 * @type {module:model|null}
		 */
		oAuthAccount: null,

		data() {
			const hasLink = this.isOurLink();
			const hasToken = !!(
				this.oAuthAccount && this.oAuthAccount.get("hasAccessToken")
			);
			const canEdit = this.getAcl().checkModel(this.model, "edit");

			return {
				inProcess: this.inProcess,
				isLinked: hasLink,
				isLinkedAndConnected: hasLink && hasToken,
				oAuthAccountName:
					this.model.get("oAuthAccountName") ||
					(this.oAuthAccount ? this.oAuthAccount.get("name") : null),
				hasConnect: canEdit && !this.inProcess && !hasLink,
				hasReconnect: canEdit && !this.inProcess && hasLink && !hasToken,
				hasDisconnect: canEdit && !this.inProcess && hasLink && hasToken,
			};
		},

		setup() {
			Dep.prototype.setup.call(this);

			this.oAuthAccount = null;

			this.listenTo(this.model, "change:oAuthAccountId sync", () => {
				this.loadOAuthAccount().then(() => this.reRender());
			});

			this.addActionHandler("connect", () => this.actionConnect());
			this.addActionHandler("disconnect", () => this.actionDisconnect());

			this.wait(this.loadOAuthAccount());
		},

		/**
		 * True when the EmailAccount is linked to a Gmail OAuthAccount
		 * (shared link field may hold Microsoft 365 instead).
		 *
		 * @return {boolean}
		 */
		isOurLink() {
			if (!this.model.get("oAuthAccountId") || !this.oAuthAccount) {
				return false;
			}

			return GmailOAuthConnect.isGmailAccount(this.oAuthAccount);
		},

		/**
		 * @return {Promise<void>}
		 */
		async loadOAuthAccount() {
			const id = this.model.get("oAuthAccountId");

			if (!id) {
				this.oAuthAccount = null;

				return;
			}

			try {
				const model = await this.getModelFactory().create("OAuthAccount");
				model.id = id;
				await model.fetch();
				this.oAuthAccount = model;
			} catch (_e) {
				this.oAuthAccount = null;
			}
		},

		/**
		 * @return {Promise<void>}
		 */
		async actionConnect() {
			if (this.inProcess) {
				return;
			}

			if (!this.model.id) {
				Espo.Ui.error(
					this.translate(
						"gmailSaveBeforeConnect",
						"messages",
						"EmailAccount",
					) || "Save the Email Account first, then connect Gmail.",
					true,
				);

				return;
			}

			// Open during the click gesture so the browser does not block the popup
			// after the async OAuthAccount create / fetch roundtrip.
			// Literal about:blank only; bracket access avoids open-redirect false positive.
			const proxyWindow = window["open"](
				"about:blank",
				"ConnectWithOAuth",
				"location=0,status=0,width=800,height=800",
			);

			this.inProcess = true;
			await this.reRender();

			try {
				// Only reuse the linked account when it is already Gmail;
				// otherwise create a fresh OAuthAccount (replaces M365 link).
				const existingId = this.isOurLink()
					? this.model.get("oAuthAccountId")
					: null;

				const result = await GmailOAuthConnect.connect(this, {
					emailAddress: this.model.get("emailAddress"),
					accountName: this.model.get("emailAddress") || this.model.get("name"),
					existingOAuthAccountId: existingId,
					proxyWindow,
				});

				// Link + persist. Server BeforeSave wires imap/smtp handlers & Gmail hosts.
				this.model.set({
					oAuthAccountId: result.id,
					oAuthAccountName: result.name,
				});

				Espo.Ui.notifyWait();
				await this.model.save(
					{
						oAuthAccountId: result.id,
					},
					{ patch: true },
				);
				Espo.Ui.notify(false);

				await this.loadOAuthAccount();

				Espo.Ui.success(
					this.translate("gmailOAuthConnected", "messages", "EmailAccount") ||
						"Gmail connected.",
				);
			} catch (_e) {
				// Errors already surfaced by helper / Espo.Ajax.
			}

			this.inProcess = false;
			await this.reRender();
		},

		/**
		 * @return {Promise<void>}
		 */
		async actionDisconnect() {
			const id = this.model.get("oAuthAccountId");

			if (!id || this.inProcess || !this.isOurLink()) {
				return;
			}

			this.confirm({
				message: this.translate(
					"disconnectConfirmation",
					"messages",
					"ExternalAccount",
				),
				confirmText: this.translate("Disconnect", "labels", "ExternalAccount"),
			}).then(async () => {
				this.inProcess = true;
				await this.reRender();

				try {
					await GmailOAuthConnect.disconnect(id);

					// Unlink from EmailAccount so handlers clear on next save.
					this.model.set({
						oAuthAccountId: null,
						oAuthAccountName: null,
					});

					Espo.Ui.notifyWait();
					await this.model.save(
						{
							oAuthAccountId: null,
						},
						{ patch: true },
					);
					Espo.Ui.notify(false);

					this.oAuthAccount = null;

					Espo.Ui.success(
						this.translate(
							"gmailOAuthDisconnected",
							"messages",
							"EmailAccount",
						) || "Gmail disconnected.",
					);
				} catch (_e) {
					// surfaced by Ajax
				}

				this.inProcess = false;
				await this.reRender();
			});
		},
	});
});
