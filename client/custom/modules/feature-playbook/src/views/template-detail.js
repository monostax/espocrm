import MainView from 'views/main';

/** A linkable template page backed exclusively by the workspace API. */
export default class extends MainView {
    template = 'feature-playbook:template-detail';
    scope = 'Playbook';

    setup() {
        super.setup();
        this.accountId = this.options.params.accountId;
        this.templateId = this.options.params.templateId;
        this.listUrl = `#PlaybookManager/index/${new URLSearchParams({accountId: this.accountId, view: 'templates'})}`;
        this.version = 0;
        this.createView('header', 'views/header', {selector: '.page-header', scope: this.scope});
        this.addMenuItem('dropdown', {
            name: 'refresh', action: 'refresh', text: this.translate('refresh', 'labels', 'Playbook'),
        });
        this.on('remove', () => {
            this.disposed = true;
            this.version++;
            this.runCollection?.abortLastFetch();
        });
        this.wait(this.load(false));
    }

    getHeader() {
        return this.buildHeaderHtml([
            $('<a>').attr('href', this.listUrl).text(this.translate('manageTemplates', 'labels', 'Playbook')),
            $('<span>').text(this.snapshot?.name || this.translate('templateDetails', 'labels', 'Playbook')),
        ]);
    }

    updatePageTitle() {
        this.setPageTitle(this.snapshot?.name || this.translate('templateDetails', 'labels', 'Playbook'));
    }

    data() {
        return {
            snapshot: this.snapshot,
            error: this.error,
            runsError: this.runsError,
            steps: (this.snapshot?.steps || []).map((step, index) => ({
                ...step, number: index + 1,
                kindLabel: this.translate(step.kind, 'labels', 'Playbook'),
                references: (step.references || []).filter(url => /^https?:\/\//i.test(url)),
            })),
            statusLabel: this.translate(this.snapshot?.status || '', 'labels', 'Playbook'),
        };
    }

    async load(render = true) {
        const version = ++this.version;
        this.clearView('runs');
        this.runCollection?.abortLastFetch();
        this.snapshot = null;
        this.runCollection = null;
        this.error = this.runsError = false;
        this.removeMenuItem('edit');
        try {
            if (!this.accountId || !this.templateId) throw new Error('Missing template context.');
            const snapshot = await Espo.Ajax.getRequest(
                `PlaybookWorkspace/${encodeURIComponent(this.accountId)}/templates/${encodeURIComponent(this.templateId)}`
            );
            if (this.disposed || version !== this.version) return;
            this.snapshot = snapshot;
            if (snapshot.canEdit) this.addMenuItem('buttons', {name: 'edit', action: 'edit', label: 'Edit'});
            try {
                const collection = await this.getCollectionFactory().create('PlaybookRun');
                if (this.disposed || version !== this.version) return;
                collection.url = `PlaybookWorkspace/${encodeURIComponent(this.accountId)}`;
                collection.data = {view: 'runs', templateId: this.templateId};
                this.runCollection = collection;
                await collection.fetch();
            } catch (error) {
                error?.setHandled?.();
                if (this.disposed || version !== this.version) return;
                this.runsError = true;
            }
        } catch (error) {
            error?.setHandled?.();
            if (this.disposed || version !== this.version) return;
            this.error = true;
        }
        if (this.disposed || version !== this.version) return;
        this.updatePageTitle();
        if (render) await this.reRender();
    }

    async afterRender() {
        if (!this.snapshot || !this.runCollection || this.runsError) return;
        const version = this.version;
        const view = await this.createView('runs', 'views/record/list', {
            selector: '[data-template-runs]', collection: this.runCollection,
            layoutName: 'list', pagination: true, settingsEnabled: false,
            checkboxes: false, massActionsDisabled: true, rowActionsDisabled: true,
            inlineEditDisabled: true, buttonsDisabled: true,
        });
        if (!this.disposed && version === this.version) await view.render();
    }

    actionRefresh() {
        return this.load();
    }

    async actionEdit() {
        if (!this.snapshot?.canEdit || this.openingEditor) return;
        this.openingEditor = true;
        try {
            const view = await this.createView('editor', 'feature-playbook:views/template-editor', {
                accountId: this.accountId, playbookTemplate: this.snapshot,
            });
            if (this.disposed) return;
            this.listenToOnce(view, 'saved', () => { if (!this.disposed) this.load(); });
            view.render();
        } finally {
            this.openingEditor = false;
        }
    }
}
