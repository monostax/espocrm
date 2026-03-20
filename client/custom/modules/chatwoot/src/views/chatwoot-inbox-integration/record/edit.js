define('chatwoot:views/chatwoot-inbox-integration/record/edit', ['views/record/edit'], function (Dep) {
    return Dep.extend({
        exitAfterCreate: function () {
            const chatwootInboxRecordId = this.model.get('chatwootInboxRecordId');

            if (!chatwootInboxRecordId) {
                return Dep.prototype.exitAfterCreate.call(this);
            }

            this.getSessionStorage().set('tab_middle', 1);
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
