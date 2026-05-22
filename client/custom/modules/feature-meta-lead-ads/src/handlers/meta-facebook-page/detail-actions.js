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
