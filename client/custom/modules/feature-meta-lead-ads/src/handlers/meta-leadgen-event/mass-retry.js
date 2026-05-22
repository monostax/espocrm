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
 * Mass-action handler — Retry Ingestion for selected MetaLeadgenEvent rows.
 *
 * The mass-action dispatcher (`actions/mass-action-buttons` view) invokes the
 * configured `actionFunction` with `{entityType, action, params}` where
 * `params` is `{ids: [...]}` for explicit selection or `{where, searchParams}`
 * for "Select All Results".
 *
 * We forward that envelope to the generic POST /api/v1/MassAction endpoint
 * which routes to `recordDefs.{Entity}.massActions.{name}.implementationClassName`
 * — in this case, `MassRetryIngest::process()`.
 *
 * Confirmation is handled by the dispatcher via `confirmationMessage` in
 * the clientDefs entry; we just provide the action.
 */
define('feature-meta-lead-ads:handlers/meta-leadgen-event/mass-retry', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        actionMassRetry(data) {
            const view = this.view;

            Espo.Ui.notify(
                view.translate('Re-enqueueing ingestion...', 'labels', 'MetaLeadgenEvent')
            );

            Espo.Ajax.postRequest('MassAction', {
                entityType: data.entityType,
                action:     'retryLeadgenIngest',
                params:     data.params,
            })
                .then(result => {
                    view.collection.fetch().then(() => {
                        const n = (result && result.count) || 0;
                        Espo.Ui.success(
                            view.translate('massRetryDone', 'messages', 'MetaLeadgenEvent')
                                .replace('{count}', String(n))
                        );
                    });
                })
                .catch(xhr => {
                    let errorMsg = view.translate('massRetryFailed', 'messages', 'MetaLeadgenEvent');
                    if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    } else if (xhr && xhr.statusText) {
                        errorMsg = xhr.statusText;
                    }
                    Espo.Ui.error(errorMsg);
                });
        }
    };
});
