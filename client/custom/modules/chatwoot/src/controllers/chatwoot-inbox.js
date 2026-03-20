define("chatwoot:controllers/chatwoot-inbox", ["controllers/record"], function (Dep) {
    return Dep.extend({
        entityType: "ChatwootInbox",

        actionCreate: function (options) {
            options = options || {};

            const router = this.getRouter();

            router.navigate("#ChatwootInboxIntegration/create", { trigger: false });
            router.dispatch("ChatwootInboxIntegration", "create", options);
        },
    });
});
