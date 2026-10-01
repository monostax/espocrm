import ListView from 'views/list';

/** Standard list page; only scoped navigation and template editing are feature-specific. */
export default class extends ListView {
    setup() {
        this.scope = this.collection.entityType;
        this.accountId = this.options.params.accountId;
        this.createButton = this.scope === 'Playbook';
        super.setup();

        const otherView = this.scope === 'Playbook' ? 'runs' : 'templates';
        const params = new URLSearchParams({accountId: this.accountId, view: otherView});
        this.addMenuItem('dropdown', {
            name: 'switchPlaybookList',
            text: this.translate(otherView === 'runs' ? 'manageRuns' : 'manageTemplates', 'labels', 'Playbook'),
            link: `#PlaybookManager/index/${params}`,
        });
        if (this.collection.data.opportunityId) {
            this.addMenuItem('dropdown', {
                name: 'showAllRuns',
                text: this.translate('showAllRuns', 'labels', 'Playbook'),
                link: `#PlaybookManager/index/${new URLSearchParams({accountId: this.accountId, view: 'runs'})}`,
            });
        }
        this.listenTo(this.collection, 'edit-template', id => this.editTemplate(id));
        this.listenTo(this.collection, 'sync', () => {
            this.removeMenuItem('create');
            this.setupCreateButton();
        });
        this.on('remove', () => {
            this.disposed = true;
            this.collection.abortLastFetch();
        });
    }

    setupCreateButton() {
        if (!this.createButton || !this.collection.canCreate) return;
        this.addMenuItem('buttons', {
            name: 'create', action: 'create',
            text: this.translate('newTemplate', 'labels', 'Playbook'),
            iconHtml: '<span class="fas fa-plus fa-sm"></span>',
            style: 'default',
        });
    }

    setupSearchManager() {
        super.setupSearchManager();
        // Preserve legacy deep links while displaying their filters in the native search panel.
        const {search, status} = this.options.params;
        if (search !== undefined) this.searchManager.data.textFilter = search;
        if (status) {
            this.searchManager.setAdvanced({status: {
                type: 'in', value: [status], data: {type: 'anyOf', valueList: [status]},
            }});
        }
        this.collection.where = this.searchManager.getWhere();
    }

    prepareRecordViewOptions(options) {
        super.prepareRecordViewOptions(options);
        Object.assign(options, {
            layoutName: 'list', pagination: true, settingsEnabled: false,
            checkboxes: false, massActionsDisabled: true, rowActionsDisabled: true,
            inlineEditDisabled: true,
        });
    }

    actionCreate() {
        return this.editTemplate();
    }

    async editTemplate(id = null) {
        if (this.openingEditor || (!id && !this.collection.canCreate)) return;
        this.openingEditor = true;
        try {
            const template = id ? await Espo.Ajax.getRequest(
                `PlaybookWorkspace/${encodeURIComponent(this.accountId)}/templates/${encodeURIComponent(id)}`
            ) : null;
            if (this.disposed || (template && !template.canEdit)) return;
            const view = await this.createView('editor', 'feature-playbook:views/template-editor', {
                accountId: this.accountId, playbookTemplate: template,
            });
            if (this.disposed) return;
            this.listenToOnce(view, 'saved', saved => {
                if (this.disposed) return;
                if (!id && saved?.id) {
                    this.getRouter().navigate(`PlaybookManager/view/${new URLSearchParams({
                        accountId: this.accountId, templateId: saved.id,
                    })}`, {trigger: true});
                    return;
                }
                this.collection.fetch();
            });
            view.render();
        } catch (error) {
            error?.setHandled?.();
            if (!this.disposed) Espo.Ui.error(this.translate('error', 'labels', 'Playbook'));
        } finally {
            this.openingEditor = false;
        }
    }
}
