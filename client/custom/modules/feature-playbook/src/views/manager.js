import MainView from 'views/main';

/** Uses the existing opportunity-scoped API so tenant/team ACL stays authoritative. */
export default class extends MainView {
    template = 'feature-playbook:manager';
    scope = 'PlaybookManager';

    events = {
        'click [data-manager-action="select"]': function () { this.selectOpportunity(); },
        'click [data-manager-action="refresh"]': function () { this.load(); },
        'click [data-manager-action="new"]': function () { this.editTemplate(); },
        'click [data-manager-tab]': function (event) {
            this.tab = event.currentTarget.dataset.managerTab;
            this.clearView('runs');
            this.clearView('templates');
            this.navigate();
            this.reRender();
        },
    };

    setup() {
        super.setup();
        this.tab = this.options.params?.view === 'runs' ? 'runs' : 'templates';
        this.opportunityId = this.options.params?.opportunityId || '';
        this.version = 0;
        this.on('remove', () => { this.disposed = true; this.version++; });
        if (this.opportunityId) this.wait(this.load(false));
    }

    data() {
        return {
            tabTemplates: this.tab === 'templates',
            tabRuns: this.tab === 'runs',
            loading: this.loading,
            error: this.error,
            hasContext: !!this.snapshot,
            opportunityId: this.opportunityId,
            opportunityName: this.opportunity?.get('name'),
            canCreate: this.snapshot?.canCreateTemplate,
        };
    }

    updatePageTitle() {
        this.setPageTitle(this.translate('Playbooks'));
    }

    navigate() {
        const params = new URLSearchParams({view: this.tab});
        if (this.opportunityId) params.set('opportunityId', this.opportunityId);
        this.getRouter().navigate(`PlaybookManager/index/${params}`, {trigger: false, replace: true});
    }

    async selectOpportunity() {
        const view = await this.createView('picker', 'views/modals/select-records', {
            scope: 'Opportunity', multiple: false, createButton: false,
        });
        this.listenToOnce(view, 'select', models => {
            const model = Array.isArray(models) ? models[0] : models;
            view.close();
            this.opportunityId = model.id;
            this.navigate();
            this.load();
        });
        view.render();
    }

    async load(render = true) {
        if (!this.opportunityId) return;
        const version = ++this.version;
        const id = this.opportunityId;
        this.loading = true;
        this.error = false;
        this.snapshot = null;
        this.clearView('runs');
        this.clearView('templates');
        if (this.templateCollection) this.stopListening(this.templateCollection);
        if (render) await this.reRender();
        try {
            const model = await this.getModelFactory().create('Opportunity');
            model.id = id;
            await model.fetch();
            const snapshot = await Espo.Ajax.getRequest(`Opportunity/${encodeURIComponent(id)}/playbooks`);
            if (this.disposed || version !== this.version) return;
            const collection = await this.getCollectionFactory().create('Playbook');
            if (this.disposed || version !== this.version) return;
            collection.reset(snapshot.templates);
            collection.total = collection.length;
            this.templateCollection = collection;
            this.opportunity = model;
            this.snapshot = snapshot;
            this.listenTo(collection, 'edit-template', templateId => {
                this.editTemplate(this.snapshot.templates.find(item => item.id === templateId));
            });
        } catch (error) {
            if (this.disposed || version !== this.version) return;
            error?.setHandled?.();
            this.error = true;
        } finally {
            if (!this.disposed && version === this.version) {
                this.loading = false;
                if (render) this.reRender();
            }
        }
    }

    async afterRender() {
        if (!this.snapshot) return;
        const version = this.version;
        if (this.tab === 'templates') {
            const view = await this.createView('templates', 'views/record/list', {
                selector: '[data-manager-templates]', collection: this.templateCollection,
                layoutName: 'listOpportunity',
                checkboxes: false, massActionsDisabled: true, rowActionsDisabled: true,
                buttonsDisabled: true,
            });
            if (this.disposed || version !== this.version || this.tab !== 'templates') return;
            await view.render();
            return;
        }
        const view = await this.createView('runs', 'feature-playbook:views/opportunity/panels/playbooks', {
            selector: '[data-manager-runs]', model: this.opportunity,
        });
        if (this.disposed || version !== this.version || this.tab !== 'runs') return;
        view.render();
    }

    async editTemplate(template = null) {
        if (!this.snapshot || (template ? !template.canEdit : !this.snapshot.canCreateTemplate)) return;
        const id = this.opportunityId;
        const view = await this.createView('editor', 'feature-playbook:views/template-editor', {
            opportunityId: id, playbookTemplate: template,
        });
        this.listenToOnce(view, 'saved', () => {
            if (!this.disposed && this.opportunityId === id) this.load();
        });
        view.render();
    }
}
