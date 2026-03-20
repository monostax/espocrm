define("chatwoot:views/chatwoot-inbox/modals/edit", ["views/modals/edit"], function (Dep) {
    return Dep.extend({
        setup: function () {
            const isCreate = !this.options.id;

            if (isCreate) {
                this.options.entityType = "ChatwootInboxIntegration";
                this.options.scope = "ChatwootInboxIntegration";
                this.options.fullFormUrl = "#ChatwootInboxIntegration/create";
            }

            Dep.prototype.setup.call(this);
        },
    });
});
