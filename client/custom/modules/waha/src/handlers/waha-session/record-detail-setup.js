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
 * Setup handler for WahaSession detail view.
 * Adds Start/Stop/Restart buttons on the right side of the header.
 */
export default class {

    /**
     * @param {import('views/record/detail').default} view
     */
    constructor(view) {
        this.view = view;
        this._statusPollInterval = null;
    }

    process() {
        this.setupButtons();
        this.listenToChanges();
    }

    setupButtons() {
        const view = this.view;
        const model = view.model;
        const acl = view.getAcl();

        // Only add buttons if user has edit permission
        if (!acl.check(model, 'edit')) {
            return;
        }

        // Add Start button
        view.addButton({
            name: 'startSession',
            label: 'Start Session',
            style: 'success',
            hidden: !this.isStartAvailable(),
            onClick: () => this.actionStart(),
        }, true); // Add to beginning

        // Add Stop button
        view.addButton({
            name: 'stopSession',
            label: 'Stop Session',
            style: 'warning',
            hidden: !this.isStopAvailable(),
            onClick: () => this.actionStop(),
        }, true);

        // Add Restart button
        view.addButton({
            name: 'restartSession',
            label: 'Restart Session',
            style: 'default',
            hidden: !this.isRestartAvailable(),
            onClick: () => this.actionRestart(),
        }, true);
    }

    listenToChanges() {
        this.view.listenTo(this.view.model, 'change:status', () => {
            this.updateButtonVisibility();
        });

        this.view.listenTo(this.view.model, 'sync', () => {
            this.updateButtonVisibility();
        });

        // Clean up polling on view remove
        this.view.once('remove', () => {
            this.stopStatusPolling();
        });
    }

    updateButtonVisibility() {
        const view = this.view;

        // Start button
        if (this.isStartAvailable()) {
            view.showActionItem('startSession');
        } else {
            view.hideActionItem('startSession');
        }

        // Stop button
        if (this.isStopAvailable()) {
            view.showActionItem('stopSession');
        } else {
            view.hideActionItem('stopSession');
        }

        // Restart button
        if (this.isRestartAvailable()) {
            view.showActionItem('restartSession');
        } else {
            view.hideActionItem('restartSession');
        }
    }

    isStartAvailable() {
        const status = this.view.model.get('status');
        return status === 'STOPPED' || status === 'FAILED';
    }

    isStopAvailable() {
        const status = this.view.model.get('status');
        return status !== 'STOPPED';
    }

    isRestartAvailable() {
        const status = this.view.model.get('status');
        return status === 'WORKING' || status === 'SCAN_QR_CODE' || status === 'STARTING';
    }

    /**
     * Start polling for status changes.
     * Polls every 2 seconds until status is stable (WORKING, SCAN_QR_CODE, STOPPED, or FAILED).
     */
    startStatusPolling() {
        this.stopStatusPolling();

        let pollCount = 0;
        const maxPolls = 30; // Max 60 seconds of polling

        this._statusPollInterval = setInterval(() => {
            pollCount++;

            if (pollCount >= maxPolls) {
                this.stopStatusPolling();
                return;
            }

            const currentStatus = this.view.model.get('status');

            // Stop polling if we've reached a stable state
            if (currentStatus === 'WORKING' || currentStatus === 'FAILED' || currentStatus === 'STOPPED') {
                this.stopStatusPolling();
                return;
            }

            // Fetch latest status
            this.view.model.fetch();
        }, 2000); // Poll every 2 seconds
    }

    stopStatusPolling() {
        if (this._statusPollInterval) {
            clearInterval(this._statusPollInterval);
            this._statusPollInterval = null;
        }
    }

    actionStart() {
        const model = this.view.model;

        Espo.Ui.notify(this.view.translate('Starting...', 'labels', 'WahaSession'));

        Espo.Ajax.postRequest(`WahaSession/${model.id}/start`)
            .then(response => {
                // Fetch the model to get updated status
                model.fetch().then(() => {
                    Espo.Ui.success(this.view.translate('sessionStarted', 'messages', 'WahaSession'));

                    // Start polling for status changes (to catch STARTING -> SCAN_QR_CODE transition)
                    const status = model.get('status');
                    if (status === 'STARTING' || status === 'SCAN_QR_CODE') {
                        this.startStatusPolling();
                    }
                });
            })
            .catch(xhr => {
                let errorMsg = this.view.translate('Error');
                if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                Espo.Ui.error(errorMsg);
            });
    }

    actionStop() {
        const model = this.view.model;

        this.view.confirm(
            this.view.translate('confirmStopSession', 'messages', 'WahaSession'),
            () => {
                Espo.Ui.notify(this.view.translate('Stopping...', 'labels', 'WahaSession'));

                Espo.Ajax.postRequest(`WahaSession/${model.id}/stop`)
                    .then(response => {
                        this.stopStatusPolling();
                        model.fetch().then(() => {
                            Espo.Ui.success(this.view.translate('sessionStopped', 'messages', 'WahaSession'));
                        });
                    })
                    .catch(xhr => {
                        let errorMsg = this.view.translate('Error');
                        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        Espo.Ui.error(errorMsg);
                    });
            }
        );
    }

    actionRestart() {
        const model = this.view.model;

        this.view.confirm(
            this.view.translate('confirmRestartSession', 'messages', 'WahaSession'),
            () => {
                Espo.Ui.notify(this.view.translate('Restarting...', 'labels', 'WahaSession'));

                Espo.Ajax.postRequest(`WahaSession/${model.id}/restart`)
                    .then(response => {
                        // Fetch the model to get updated status
                        model.fetch().then(() => {
                            Espo.Ui.success(this.view.translate('sessionRestarted', 'messages', 'WahaSession'));

                            // Start polling for status changes (to catch STARTING -> SCAN_QR_CODE transition)
                            const status = model.get('status');
                            if (status === 'STARTING' || status === 'SCAN_QR_CODE') {
                                this.startStatusPolling();
                            }
                        });
                    })
                    .catch(xhr => {
                        let errorMsg = this.view.translate('Error');
                        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        Espo.Ui.error(errorMsg);
                    });
            }
        );
    }
}
