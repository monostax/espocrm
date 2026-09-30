import MainView from 'views/main';

export default class extends MainView {
    template = 'feature-playbook:workspace';
    scope = 'PlaybookManager';

    events = {
        'submit [data-workspace-filters]': function (event) {
            event.preventDefault();
            const form = event.currentTarget;
            this.search = form.elements.namedItem('search').value.trim();
            this.status = form.elements.namedItem('status').value;
            this.cursor = '';
            this.navigate();
            this.load();
        },
        'click [data-workspace-tab]': function (event) {
            this.tab = event.currentTarget.dataset.workspaceTab;
            this.status = this.search = this.cursor = this.opportunityId = '';
            this.navigate();
            this.updatePageTitle();
            this.load();
        },
        'click [data-workspace-action="refresh"]': function () { this.load(); },
        'click [data-workspace-action="new"]': function () { this.editTemplate(); },
        'click [data-workspace-action="clear"]': function () {
            this.opportunityId = this.cursor = '';
            this.navigate();
            this.load();
        },
        'click [data-workspace-action="next"]': function () {
            this.cursor = this.snapshot.cursor;
            this.navigate();
            this.load();
        },
        'click [data-workspace-action="first"]': function () {
            this.cursor = '';
            this.navigate();
            this.load();
        },
        'click [data-workspace-template]': function (event) {
            this.editTemplate(event.currentTarget.dataset.workspaceTemplate);
        },
    };

    setup() {
        super.setup();
        const params = this.options.params || {};
        this.accountId = params.accountId;
        this.tab = params.view === 'runs' ? 'runs' : 'templates';
        this.search = params.search || '';
        this.status = params.status || '';
        this.cursor = params.cursor || '';
        this.opportunityId = params.opportunityId || '';
        this.version = 0;
        this.on('remove', () => { this.disposed = true; this.version++; });
        this.wait(this.load(false));
    }

    data() {
        const runs = this.tab === 'runs';
        return {
            runs, loading: this.loading, error: this.error,
            title: this.translate(runs ? 'manageRuns' : 'manageTemplates', 'labels', 'Playbook'),
            description: this.translate(runs ? 'runsDescription' : 'templatesDescription', 'labels', 'Playbook'),
            search: this.search, opportunityId: this.opportunityId,
            canCreate: !this.loading && this.snapshot?.canCreate,
            hasNext: !!this.snapshot?.cursor, hasPrevious: !!this.cursor,
            hasSnapshot: !!this.snapshot,
            statuses: (runs ? ['Active', 'Completed', 'Stopped', 'Cancelled'] : ['Draft', 'Published', 'Archived'])
                .map(value => ({value, label: this.translate(value, 'labels', 'Playbook'), selected: this.status === value})),
            rows: (this.snapshot?.items || []).map(row => ({
                ...row,
                statusLabel: this.translate(row.status, 'labels', 'Playbook'),
                date: this.getDateTime().toDisplay(row.createdAt || row.modifiedAt),
                opportunityUrl: `#Opportunity/view/${encodeURIComponent(row.opportunityId || '')}`,
                progressMax: row.total || 1,
            })),
        };
    }

    updatePageTitle() {
        this.setPageTitle(this.translate(this.tab === 'runs' ? 'manageRuns' : 'manageTemplates', 'labels', 'Playbook'));
    }

    navigate() {
        const params = new URLSearchParams({accountId: this.accountId, view: this.tab});
        for (const key of ['search', 'status', 'opportunityId', 'cursor']) {
            // Router decodes the whole segment, then each individual option.
            if (this[key]) params.set(key, encodeURIComponent(this[key]));
        }
        this.getRouter().navigate(`PlaybookManager/index/${params}`, {trigger: false, replace: true});
    }

    async load(render = true) {
        const version = ++this.version;
        this.loading = true;
        this.error = false;
        this.snapshot = null;
        if (render) await this.reRender();
        try {
            const snapshot = await Espo.Ajax.getRequest(`PlaybookWorkspace/${encodeURIComponent(this.accountId)}`, {
                view: this.tab, search: this.search, status: this.status,
                opportunityId: this.opportunityId, cursor: this.cursor,
            });
            if (!this.disposed && version === this.version) this.snapshot = snapshot;
        } catch (error) {
            error?.setHandled?.();
            if (!this.disposed && version === this.version) this.error = true;
        } finally {
            if (!this.disposed && version === this.version) {
                this.loading = false;
                if (render) this.reRender();
            }
        }
    }

    async editTemplate(id = null) {
        if (this.openingEditor) return;
        this.openingEditor = true;
        try {
            const template = id ? await Espo.Ajax.getRequest(
                `PlaybookWorkspace/${encodeURIComponent(this.accountId)}/templates/${encodeURIComponent(id)}`
            ) : null;
            if (this.disposed || (template ? !template.canEdit : !this.snapshot?.canCreate)) return;
            const view = await this.createView('editor', 'feature-playbook:views/template-editor', {
                accountId: this.accountId, template,
            });
            if (this.disposed) return;
            this.listenToOnce(view, 'saved', () => {
                if (!this.disposed) this.load();
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
