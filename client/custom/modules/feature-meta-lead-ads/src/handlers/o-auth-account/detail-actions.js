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
 * Detail-view actions for OAuthAccount, scoped to Meta Lead Ads.
 *
 * Exposes a "Sync Meta Pages" button that:
 *   1. Calls POST /api/v1/MetaLeadAds/syncPages { oAuthAccountId }
 *   2. Renders a dialog summarizing how many pages were discovered, how many
 *      were newly created vs. updated, and how many were successfully
 *      subscribed to the leadgen webhook field.
 *
 * The button is only visible when the OAuthAccount belongs to an
 * OAuthProvider whose `provider` discriminator is `meta-leadads`.
 *
 * The check uses `model.get('providerName')` because Espo's relationship
 * fields expose the linked entity name (not the discriminator), so we
 * also defensively check `model.get('providerId')` against known seeded
 * provider ids — adjust if needed.
 *
 * Simpler approach: just check `model.get('providerName')` contains "Lead Ads"
 * (matches the seeded provider name "Meta (Lead Ads)").
 */
define('feature-meta-lead-ads:handlers/o-auth-account/detail-actions', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        /**
         * Button is visible when:
         *  - The account row is persisted (has an id).
         *  - The linked provider name suggests Lead Ads.
         */
        isSyncMetaLeadAdsPagesAvailable() {
            const model = this.view.model;

            if (!model.id) {
                return false;
            }

            const providerName = (model.get('providerName') || '').toLowerCase();

            return providerName.indexOf('lead ads') !== -1;
        }

        syncMetaLeadAdsPages() {
            const view = this.view;
            const model = view.model;

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
                        oAuthAccountId: model.id,
                    })
                        .then(response => {
                            this._renderResult(response);
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
