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
 * Detail-view actions for MetaFacebookPage.
 *
 * Exposes "Sync Forms" — calls POST /api/v1/MetaLeadAds/syncForms { pageId },
 * which fetches /{pageId}/leadgen_forms from Meta and upserts MetaLeadForm
 * rows.
 *
 * Newly-created forms ship with isActive=false (admin still needs to assign
 * a Funnel). The result dialog enumerates discovered/created/updated counts.
 */
define('feature-meta-lead-ads:handlers/meta-facebook-page/detail-actions', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        isSyncMetaLeadFormsAvailable() {
            const model = this.view.model;
            // Need a saved page row with an access token + Meta page id.
            return !!(model.id && model.get('pageId'));
        }

        /**
         * Visible only when this page row already has an OAuthAccount linked.
         * For detached/manual rows users should use the list-level "Sync Pages"
         * button (handled by feature-meta-lead-ads:handlers/meta-facebook-page/list-actions).
         */
        isSyncMetaPagesFromAccountAvailable() {
            const model = this.view.model;
            return !!(model.id && model.get('oAuthAccountId'));
        }

        syncMetaPagesFromAccount() {
            const view = this.view;
            const model = view.model;

            const oAuthAccountId = model.get('oAuthAccountId');
            if (!oAuthAccountId) {
                Espo.Ui.warning(
                    view.translate('syncMetaPagesNoAccount', 'messages', 'MetaFacebookPage')
                );
                return;
            }

            Espo.Ui.confirm(
                view.translate('confirmSyncMetaLeadAdsPages', 'messages', 'OAuthAccount'),
                {
                    confirmText: view.translate('Sync', 'labels', 'OAuthAccount'),
                    cancelText: view.translate('Cancel'),
                },
                () => {
                    Espo.Ui.notify(
                        view.translate('Syncing Meta Lead Ads pages...', 'labels', 'OAuthAccount')
                    );

                    Espo.Ajax.postRequest('MetaLeadAds/syncPages', {
                        oAuthAccountId: oAuthAccountId,
                    })
                        .then(response => {
                            this._renderPagesSyncResult(response);
                            // Re-fetch this row so pageAccessToken / lastSyncedAt
                            // / subscribedToLeadgen update without a manual refresh.
                            model.fetch();
                        })
                        .catch(xhr => {
                            let errorMsg = view.translate('syncMetaLeadAdsPagesFailed', 'messages', 'OAuthAccount');
                            if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
                                errorMsg = xhr.responseJSON.error;
                            } else if (xhr && xhr.statusText) {
                                errorMsg = xhr.statusText;
                            }
                            Espo.Ui.error(errorMsg);
                        });
                }
            );
        }

        _renderPagesSyncResult(response) {
            const view = this.view;

            if (!response || response.ok === false) {
                const msg = (response && response.error) ||
                    view.translate('syncMetaLeadAdsPagesFailed', 'messages', 'OAuthAccount');
                Espo.Ui.error(msg);
                return;
            }

            const discovered = response.pagesDiscovered || 0;
            const created    = response.pagesCreated    || 0;
            const updated    = response.pagesUpdated    || 0;
            const subscribed = response.subscribed      || 0;
            const subErrors  = response.subscribeErrors || [];

            const escapeHtml = (s) => String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');

            let body = '<p>'
                + escapeHtml(view.translate('syncMetaLeadAdsPagesDone', 'messages', 'OAuthAccount'))
                + '</p>'
                + '<ul style="padding-left: 18px;">'
                + '<li>' + escapeHtml(view.translate('Discovered', 'labels', 'OAuthAccount')) + ': <strong>' + discovered + '</strong></li>'
                + '<li>' + escapeHtml(view.translate('Created', 'labels', 'OAuthAccount'))    + ': <strong>' + created    + '</strong></li>'
                + '<li>' + escapeHtml(view.translate('Updated', 'labels', 'OAuthAccount'))    + ': <strong>' + updated    + '</strong></li>'
                + '<li>' + escapeHtml(view.translate('Subscribed', 'labels', 'OAuthAccount')) + ': <strong>' + subscribed + '</strong></li>'
                + '</ul>';

            if (subErrors.length > 0) {
                const lines = subErrors
                    .map(r => '<li><code>' + escapeHtml(r.pageId) + '</code>: ' + escapeHtml(r.error || 'unknown error') + '</li>')
                    .join('');

                body += '<hr><p><strong>'
                    + escapeHtml(view.translate('syncMetaLeadAdsPagesSubscribeErrors', 'messages', 'OAuthAccount'))
                    + '</strong></p><ul style="padding-left: 18px;">' + lines + '</ul>';
            }

            Espo.Ui.dialog({
                backdrop: 'static',
                className: 'dialog-confirm',
                headerText: view.translate('Meta Lead Ads — Sync Pages', 'labels', 'OAuthAccount'),
                body: body,
                buttonList: [
                    {
                        name: 'close',
                        label: view.translate('Close'),
                        style: 'primary',
                        onClick: (d) => d.close(),
                    },
                ],
            }).show();

            if (subErrors.length === 0) {
                Espo.Ui.success(view.translate('syncMetaLeadAdsPagesDone', 'messages', 'OAuthAccount'));
            } else {
                Espo.Ui.warning(view.translate('syncMetaLeadAdsPagesPartial', 'messages', 'OAuthAccount'));
            }
        }

        syncMetaLeadForms() {
            const view = this.view;
            const model = view.model;

            Espo.Ui.confirm(
                view.translate('confirmSyncMetaLeadForms', 'messages', 'MetaFacebookPage'),
                {
                    confirmText: view.translate('Sync', 'labels', 'MetaFacebookPage'),
                    cancelText: view.translate('Cancel'),
                },
                () => {
                    Espo.Ui.notify(
                        view.translate('Syncing lead forms...', 'labels', 'MetaFacebookPage')
                    );

                    Espo.Ajax.postRequest('MetaLeadAds/syncForms', {
                        pageId: model.id,
                    })
                        .then(response => {
                            this._renderResult(response);
                            // The hasMany `leadForms` panel might need a refresh.
                            model.trigger('after:relate', 'leadForms');
                        })
                        .catch(xhr => {
                            let errorMsg = view.translate('syncMetaLeadFormsFailed', 'messages', 'MetaFacebookPage');
                            if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
                                errorMsg = xhr.responseJSON.error;
                            } else if (xhr && xhr.statusText) {
                                errorMsg = xhr.statusText;
                            }
                            Espo.Ui.error(errorMsg);
                        });
                }
            );
        }

        _renderResult(response) {
            const view = this.view;

            if (!response || response.ok === false) {
                const msg = (response && response.error) ||
                    view.translate('syncMetaLeadFormsFailed', 'messages', 'MetaFacebookPage');
                Espo.Ui.error(msg);
                return;
            }

            const discovered = response.formsDiscovered || 0;
            const created    = response.formsCreated    || 0;
            const updated    = response.formsUpdated    || 0;

            const escapeHtml = (s) => String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');

            const body = '<p>'
                + escapeHtml(view.translate('syncMetaLeadFormsDone', 'messages', 'MetaFacebookPage'))
                + '</p>'
                + '<ul style="padding-left: 18px;">'
                + '<li>' + escapeHtml(view.translate('Discovered', 'labels', 'MetaFacebookPage')) + ': <strong>' + discovered + '</strong></li>'
                + '<li>' + escapeHtml(view.translate('Created', 'labels', 'MetaFacebookPage'))    + ': <strong>' + created    + '</strong></li>'
                + '<li>' + escapeHtml(view.translate('Updated', 'labels', 'MetaFacebookPage'))    + ': <strong>' + updated    + '</strong></li>'
                + '</ul>'
                + (created > 0
                    ? '<p style="margin-top: 10px;"><em>'
                      + escapeHtml(view.translate('syncMetaLeadFormsNeedConfig', 'messages', 'MetaFacebookPage'))
                      + '</em></p>'
                    : '');

            Espo.Ui.dialog({
                backdrop: 'static',
                className: 'dialog-confirm',
                headerText: view.translate('Meta Lead Ads — Sync Forms', 'labels', 'MetaFacebookPage'),
                body: body,
                buttonList: [
                    {
                        name: 'close',
                        label: view.translate('Close'),
                        style: 'primary',
                        onClick: (d) => d.close(),
                    },
                ],
            }).show();

            Espo.Ui.success(view.translate('syncMetaLeadFormsDone', 'messages', 'MetaFacebookPage'));
        }
    };
});
