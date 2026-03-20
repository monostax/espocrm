define(["action-handler"], (Dep) => {
    return class extends Dep {
        createInboxChannel() {
            const router = this.view.getRouter();

            router.navigate("#ChatwootInbox/create", { trigger: false });
            router.dispatch("ChatwootInbox", "create", {
                attributes: {
                    chatwootAccountId: this.view.model.id,
                    chatwootAccountName: this.view.model.get("name"),
                },
            });
        }
    };
});
