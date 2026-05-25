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
 * List-view actions for MetaFacebookPage.
 *
 * Exposes "Sync Pages" — opens an OAuthAccount picker, then calls
 * POST /api/v1/MetaLeadAds/syncPages { oAuthAccountId } which discovers
 * all Pages reachable through that OAuth user token, upserts MetaFacebookPage
 * rows with their per-page access tokens, and subscribes the Meta App to
 * each page's `leadgen` field.
 *
 * Until Sync Pages runs against an OAuthAccount with provider=meta-leadads,
 * MetaFacebookPage rows will not have a `pageAccessToken` and "Sync Forms"
 * on the page detail will fail with "Page has no access token configured".
 */
define('feature-meta-lead-ads:handlers/meta-facebook-page/list-actions', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        syncMetaPages() {
            this._openOAuthAccountPicker();
        }

        _openOAuthAccountPicker() {
            const view = this.view;

            view.createView('dialog', 'views/modals/select-records', {
                scope: 'OAuthAccount',
                multiple: false,
                createButton: false,
                // Server-side validates provider === 'meta-leadads' too
                // (PageSyncService::syncForOAuthAccount), this filter just
                // prevents users from picking an invalid account in the UI.
                primaryFilterName: 'metaLeadAds',
            }, (modal) => {
                modal.render();

                Espo.Ui.notify(false);

                this.listenToOnce(modal, 'select', (model) => {
                    const oAuthAccountId = Array.isArray(model) ? (model[0] && model[0].id) : model.id;
                    if (!oAuthAccountId) {
                        return;
                    }
                    this._runSync(oAuthAccountId);
                });
            });
        }

        _runSync(oAuthAccountId) {
            const view = this.view;

            Espo.Ui.notify(
                view.translate('Syncing Meta Lead Ads pages...', 'labels', 'OAuthAccount')
            );

            Espo.Ajax.postRequest('MetaLeadAds/syncPages', {
                oAuthAccountId: oAuthAccountId,
            })
                .then(response => {
                    this._renderResult(response);
                    if (view.collection) {
                        view.collection.fetch();
                    }
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

        _renderResult(response) {
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
    };
});
