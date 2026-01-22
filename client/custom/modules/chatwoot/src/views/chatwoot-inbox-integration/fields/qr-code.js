/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('chatwoot:views/chatwoot-inbox-integration/fields/qr-code', ['views/fields/text'], function (Dep) {

    return Dep.extend({

        detailTemplate: 'chatwoot:chatwoot-inbox-integration/fields/qr-code/detail',

        refreshInterval: null,

        REFRESH_INTERVAL_MS: 20000, // 20 seconds

        data: function () {
            const status = this.model.get('status');
            return {
                ...Dep.prototype.data.call(this),
                status: status,
                isPendingQr: status === 'PENDING_QR',
                isActive: status === 'ACTIVE',
                isDisconnected: status === 'DISCONNECTED',
                isFailed: status === 'FAILED',
                isCreating: status === 'CREATING',
                isConnecting: status === 'CONNECTING',
                isDraft: status === 'DRAFT',
                qrCodeDataUrl: null,
                whatsappName: this.model.get('whatsappName'),
                whatsappId: this.model.get('whatsappId'),
            };
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.listenTo(this.model, 'change:status', () => {
                this.reRender();
            });
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            const status = this.model.get('status');

            if (status === 'PENDING_QR') {
                this.loadQrCode();
                this.startAutoRefresh();
            } else {
                this.stopAutoRefresh();
            }
        },

        loadQrCode: function () {
            const id = this.model.id;

            if (!id) {
                return;
            }

            this.$el.find('.qr-code-container').html(
                '<div class="loading">' + 
                '<span class="fas fa-spinner fa-spin"></span> ' +
                this.translate('Loading QR Code', 'labels', 'ChatwootInboxIntegration') +
                '</div>'
            );

            Espo.Ajax.getRequest('ChatwootInboxIntegration/action/qrCode', { id: id })
                .then(response => {
                    if (response && response.dataUrl) {
                        this.$el.find('.qr-code-container').html(
                            '<img src="' + response.dataUrl + '" alt="QR Code" class="qr-code-image" />' +
                            '<p class="qr-code-hint">' + 
                            this.translate('Scan QR Code with WhatsApp', 'labels', 'ChatwootInboxIntegration') +
                            '</p>' +
                            '<p class="text-muted small">' +
                            this.translate('QR Code refreshes automatically', 'labels', 'WahaSession') +
                            '</p>'
                        );
                    }
                })
                .catch(() => {
                    this.$el.find('.qr-code-container').html(
                        '<div class="text-danger">' +
                        '<span class="fas fa-exclamation-triangle"></span> ' +
                        'Failed to load QR Code' +
                        '</div>'
                    );
                });
        },

        startAutoRefresh: function () {
            this.stopAutoRefresh();

            this.refreshInterval = setInterval(() => {
                if (this.model.get('status') === 'PENDING_QR') {
                    this.checkStatusAndRefresh();
                } else {
                    this.stopAutoRefresh();
                }
            }, this.REFRESH_INTERVAL_MS);
        },

        stopAutoRefresh: function () {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
        },

        checkStatusAndRefresh: function () {
            const id = this.model.id;

            Espo.Ajax.postRequest(`ChatwootInboxIntegration/${id}/checkStatus`)
                .then(response => {
                    if (response.status !== this.model.get('status')) {
                        this.model.set(response);
                        if (response.status === 'ACTIVE') {
                            Espo.Ui.success(this.translate('channelConnected', 'messages', 'ChatwootInboxIntegration'));
                        }
                    } else if (response.status === 'PENDING_QR') {
                        this.loadQrCode();
                    }
                })
                .catch(() => {
                    // Silently fail, will retry
                });
        },

        onRemove: function () {
            this.stopAutoRefresh();
            Dep.prototype.onRemove.call(this);
        }
    });
});
