import Controller from 'controller';

export default class extends Controller {
    actionView(options = {}) {
        this.routeVersion = (this.routeVersion || 0) + 1;
        this.main('feature-playbook:views/template-detail', {params: options});
    }

    checkAccessGlobal() {
        return this.getAcl().check('Opportunity', 'read') || this.getAcl().check('Playbook', 'read');
    }

    async actionIndex(options = {}) {
        this.routeVersion = (this.routeVersion || 0) + 1;
        const version = this.routeVersion;
        if (!options.accountId) {
            this.main('feature-playbook:views/manager', {params: options});
            return;
        }

        const scope = options.view === 'runs' ? 'PlaybookRun' : 'Playbook';
        const collection = await this.collectionFactory.create(scope);
        if (version !== this.routeVersion) return;
        collection.url = `PlaybookWorkspace/${encodeURIComponent(options.accountId)}`;
        collection.data = {view: options.view === 'runs' ? 'runs' : 'templates'};
        if (options.opportunityId) collection.data.opportunityId = options.opportunityId;
        if (options.templateId) collection.data.templateId = options.templateId;
        this.main('feature-playbook:views/workspace', {scope, collection, params: options});
    }
}
