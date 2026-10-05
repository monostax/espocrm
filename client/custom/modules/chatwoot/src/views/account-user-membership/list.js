import ListView from 'global:views/list';

export default class extends ListView {
    setupCreateButton() {
        if (this._primaryFilter !== 'aiOnly') super.setupCreateButton();
        this.addMenuItem('buttons', {
            action: 'createAiAgent',
            text: this.translate('Create AI Agent', 'labels', this.scope),
            iconHtml: '<span class="ti ti-sparkles"></span>',
            style: 'primary',
            acl: 'create',
            aclScope: this.scope,
        }, true);
    }

    actionCreateAiAgent() {
        this.createView('createAiAgent', 'chatwoot:views/account-user-membership/modals/create-ai-agent', {}, view => {
            this.listenToOnce(view, 'created', () => this.collection.fetch());
            view.render();
        });
    }

    actionCreate(data) {
        if (this._primaryFilter === 'aiOnly') return this.actionCreateAiAgent();
        return super.actionCreate(data);
    }

    actionQuickCreate(data) {
        if (this._primaryFilter === 'aiOnly') return this.actionCreateAiAgent();
        return super.actionQuickCreate(data);
    }
}
