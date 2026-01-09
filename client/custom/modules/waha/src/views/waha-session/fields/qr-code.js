/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('waha:views/waha-session/fields/qr-code', ['views/fields/text'], function (Dep) {

    return Dep.extend({

        detailTemplate: 'waha:waha-session/fields/qr-code/detail',

        data: function () {
            return {
                qrCodeDataUrl: this.qrCodeDataUrl,
                isLoading: this.isLoading,
                errorMessage: this.errorMessage,
                showQrCode: this.showQrCode,
                status: this.model.get('status')
            };
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.qrCodeDataUrl = null;
            this.isLoading = false;
            this.errorMessage = null;
            this.showQrCode = false;
            this._isRendering = false;
            this._lastStatus = null;

            // Listen to status changes
            this.listenTo(this.model, 'change:status', () => {
                this.updateQrCodeState();
            });

            // Listen to sync events (when model is fetched)
            this.listenTo(this.model, 'sync', () => {
                this.onModelSync();
            });
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);
            
            // Only check on first render, not on re-renders
            if (!this._initialCheckDone) {
                this._initialCheckDone = true;
                this._lastStatus = this.model.get('status');
                this.updateQrCodeState();
            }
        },

        onModelSync: function () {
            // Safety check - make sure view is still valid
            if (!this.model || this._removed) {
                return;
            }

            const status = this.model.get('status');

            // If status changed to SCAN_QR_CODE, refresh the QR code
            if (status === 'SCAN_QR_CODE') {
                if (!this.showQrCode || this._lastStatus !== 'SCAN_QR_CODE') {
                    // Status just changed to SCAN_QR_CODE, load QR
                    this.showQrCode = true;
                    this.loadQrCode();
                }
            } else if (this.showQrCode) {
                // Status is no longer SCAN_QR_CODE, hide QR
                this.showQrCode = false;
                this.qrCodeDataUrl = null;
                this.errorMessage = null;
                this.stopAutoRefresh();
                this.safeReRender();
            }

            this._lastStatus = status;
        },

        updateQrCodeState: function () {
            // Safety check - make sure view is still valid
            if (!this.model || this._removed) {
                return;
            }

            const status = this.model.get('status');
            const shouldShowQr = (status === 'SCAN_QR_CODE');

            if (shouldShowQr && !this.showQrCode) {
                this.showQrCode = true;
                this.loadQrCode();
            } else if (!shouldShowQr && this.showQrCode) {
                this.showQrCode = false;
                this.qrCodeDataUrl = null;
                this.errorMessage = null;
                this.stopAutoRefresh();
                this.safeReRender();
            } else if (!shouldShowQr && !this._initialRenderDone) {
                this._initialRenderDone = true;
                // Initial render for non-QR states - no reRender needed
            }

            this._lastStatus = status;
        },

        loadQrCode: function () {
            // Safety check
            if (!this.model || this._removed) {
                return;
            }

            if (this.isLoading) {
                return;
            }

            this.isLoading = true;
            this.errorMessage = null;
            this.safeReRender();

            const id = this.model.get('id');

            Espo.Ajax.getRequest('WahaSession/action/qrCode', { id: id })
                .then(response => {
                    if (this._removed) return;

                    this.isLoading = false;
                    this.qrCodeDataUrl = response.dataUrl;
                    this.safeReRender();

                    // Auto-refresh QR code every 20 seconds while in SCAN_QR_CODE status
                    this.startAutoRefresh();
                })
                .catch(xhr => {
                    if (this._removed) return;

                    this.isLoading = false;
                    let errorMsg = 'Failed to load QR code';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    this.errorMessage = errorMsg;
                    this.safeReRender();

                    // Retry after 3 seconds if still in SCAN_QR_CODE status
                    // (QR code might not be ready immediately after session start)
                    setTimeout(() => {
                        if (this._removed) return;

                        const currentStatus = this.model.get('status');
                        if (currentStatus === 'SCAN_QR_CODE' && this.showQrCode) {
                            this.isLoading = false;
                            this.loadQrCode();
                        }
                    }, 3000);
                });
        },

        safeReRender: function () {
            // Safety check - make sure view is still valid and rendered
            if (this._removed || !this.isRendered()) {
                return;
            }

            if (this._isRendering) {
                return;
            }
            this._isRendering = true;
            
            // Use setTimeout to break the call stack
            setTimeout(() => {
                if (this._removed) {
                    this._isRendering = false;
                    return;
                }

                try {
                    this.reRender();
                } catch (e) {
                    console.warn('QR code reRender failed:', e);
                }
                this._isRendering = false;
            }, 0);
        },

        startAutoRefresh: function () {
            this.stopAutoRefresh();

            this.refreshInterval = setInterval(() => {
                if (this._removed) {
                    this.stopAutoRefresh();
                    return;
                }

                const status = this.model.get('status');
                if (status === 'SCAN_QR_CODE') {
                    this.isLoading = false; // Reset so loadQrCode will run
                    this.loadQrCode();
                } else {
                    this.stopAutoRefresh();
                    this.updateQrCodeState();
                }
            }, 20000); // Refresh every 20 seconds
        },

        stopAutoRefresh: function () {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
        },

        onRemove: function () {
            this._removed = true;
            this.stopAutoRefresh();
            Dep.prototype.onRemove.call(this);
        }
    });
});
