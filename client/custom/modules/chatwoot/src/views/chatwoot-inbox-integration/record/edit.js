define('chatwoot:views/chatwoot-inbox-integration/record/edit', ['views/record/edit'], function (Dep) {
    return Dep.extend({
        exitAfterCreate: function () {
            const chatwootInboxRecordId = this.model.get('chatwootInboxRecordId');
            const channelType = (this.model.get('channelType') || '').toLowerCase();
            const isQrCodeIntegration = channelType.includes('whatsapp') && channelType.includes('qrcode');

            if (!chatwootInboxRecordId) {
                return Dep.prototype.exitAfterCreate.call(this);
            }

            this.getSessionStorage().set('tab_middle', isQrCodeIntegration ? 2 : 1);
            this.getSessionStorage().set('tab_middle_record', `ChatwootInbox_${chatwootInboxRecordId}`);

            const url = `#ChatwootInbox/view/${chatwootInboxRecordId}`;

            this.getRouter().navigate(url, { trigger: false });
            this.getRouter().dispatch('ChatwootInbox', 'view', {
                id: chatwootInboxRecordId,
                rootUrl: this.options.rootUrl,
                isReturn: true,
                isAfterCreate: true,
            });

            return true;
        },
    });
});
