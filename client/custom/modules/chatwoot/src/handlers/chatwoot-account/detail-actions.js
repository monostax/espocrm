define(["action-handler"], (Dep) => {
    return class extends Dep {
        createAiAgent() {
            this.view.createView('createAiAgent', 'chatwoot:views/account-user-membership/modals/create-ai-agent', {
                attributes: {
                    chatwootAccountId: this.view.model.id,
                    chatwootAccountName: this.view.model.get('name'),
                },
            }, view => {
                this.view.listenToOnce(view, 'created', () => {
                    this.view.model.trigger('after:relate:accountUserMemberships');
                });
                view.render();
            });
        }

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
