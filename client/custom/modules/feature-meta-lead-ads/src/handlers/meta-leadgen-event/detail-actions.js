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
 * Detail-view actions for MetaLeadgenEvent.
 *
 * Exposes "Retry Ingestion" — only when status=Failed.
 * Calls POST /api/v1/MetaLeadAds/retryIngest { eventId }, which resets the
 * event to Received and re-enqueues IngestLeadgen.
 */
define('feature-meta-lead-ads:handlers/meta-leadgen-event/detail-actions', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        isRetryLeadgenIngestAvailable() {
            const model = this.view.model;

            if (!model.id) {
                return false;
            }

            return model.get('status') === 'Failed';
        }

        retryLeadgenIngest() {
            const view = this.view;
            const model = view.model;

            Espo.Ui.confirm(
                view.translate('confirmRetryLeadgenIngest', 'messages', 'MetaLeadgenEvent'),
                {
                    confirmText: view.translate('Retry', 'labels', 'MetaLeadgenEvent'),
                    cancelText: view.translate('Cancel'),
                },
                () => {
                    Espo.Ui.notify(
                        view.translate('Re-enqueueing ingestion...', 'labels', 'MetaLeadgenEvent')
                    );

                    Espo.Ajax.postRequest('MetaLeadAds/retryIngest', {
                        eventId: model.id,
                    })
                        .then(response => {
                            if (!response || response.ok === false) {
                                const msg = (response && response.error) ||
                                    view.translate('retryLeadgenIngestFailed', 'messages', 'MetaLeadgenEvent');
                                Espo.Ui.error(msg);
                                return;
                            }
                            Espo.Ui.success(
                                view.translate('retryLeadgenIngestQueued', 'messages', 'MetaLeadgenEvent')
                            );
                            // Reload the row so admin sees the new Received state.
                            model.fetch();
                        })
                        .catch(xhr => {
                            let errorMsg = view.translate('retryLeadgenIngestFailed', 'messages', 'MetaLeadgenEvent');
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
    };
});
