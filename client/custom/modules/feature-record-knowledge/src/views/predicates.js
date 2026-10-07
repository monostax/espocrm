import MainView from 'views/main';
import {predicateLabel} from 'feature-record-knowledge:labels';

export default class extends MainView {
    templateContent = `
        <h3>{{translate 'Knowledge Predicates' scope='Configurations'}}</h3>
        <div class="form-inline margin-bottom">
            <label>{{translate 'tenant' category='fields' scope='RecordPredicate'}}
            <select class="form-control" data-name="tenant">{{#each tenants}}<option value="{{id}}">{{name}}</option>{{/each}}</select></label>
            {{#if editable}}<button type="button" class="btn btn-primary" data-action="create">{{translate 'Create'}}</button>{{/if}}
        </div>
        {{#if needsSelection}}<p>Select a workspace to view its predicates.</p>{{/if}}
        {{#if error}}<p role="alert" class="text-danger">{{error}}</p>{{/if}}
        <ul class="list-unstyled">
            {{#each predicates}}<li class="panel panel-default"><div class="panel-body">
                <strong>{{label}}</strong> / {{inverse}} <code style="overflow-wrap:anywhere">{{key}}</code>
                <p>{{description}}</p><small>{{endpointText}}</small>
                <pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{schemaText}}</pre>
                {{#if builtin}}<span class="text-muted">Platform · read-only</span>{{else}}
                    <span class="text-muted">{{#if active}}Active{{else}}Inactive{{/if}} {{#if referenced}}· Semantics locked{{/if}}</span>
                    {{#if ../editable}}
                        <button type="button" class="btn btn-default btn-sm" data-action="edit" data-id="{{id}}">{{translate 'Edit'}}</button>
                        {{#unless referenced}}<button type="button" class="btn btn-default btn-sm" data-action="delete" data-id="{{id}}">{{translate 'Remove'}}</button>{{/unless}}
                    {{/if}}
                {{/if}}
            </div></li>{{/each}}
        </ul>
    `

    setup() {
        super.setup();
        this.predicates = [];
        this.tenants = [];
        this.wait(this.initialize());
        this.addHandler('change', '[data-name="tenant"]', (event, target) => { this.tenantId = target.value; this.load(); });
        this.addHandler('click', '[data-action="create"]', () => this.edit());
        this.addHandler('click', '[data-action="edit"]', (event, target) => this.edit(this.predicates.find(p => p.id === target.dataset.id)));
        this.addHandler('click', '[data-action="delete"]', (event, target) => this.removePredicate(target.dataset.id));
    }

    async initialize() {
        const [contexts, schema] = await Promise.all([Espo.Ajax.getRequest('RecordPredicate/contexts'), Espo.Ajax.getRequest('RecordKnowledge/schema')]);
        this.tenants = contexts.list;
        this.scopes = schema.supportedScopes;
        this.tenantId = this.tenants.length === 1 ? this.tenants[0].id : '';
        if (this.tenantId) await this.load();
    }

    async load() {
        const generation = (this.generation || 0) + 1;
        this.generation = generation;
        this.error = null;
        try {
            const data = await Espo.Ajax.getRequest('RecordPredicate/registry', {tenantId: this.tenantId});
            if (generation !== this.generation) return;
            this.predicates = Object.values(data.predicates);
            this.editable = this.tenants.find(t => t.id === this.tenantId)?.editable && this.getAcl().checkScope('RecordPredicate', 'edit');
        } catch (error) {
            if (generation !== this.generation) return;
            this.predicates = []; this.editable = false;
            this.error = error.message || 'Predicate registry unavailable.';
        }
        if (this.isRendered()) this.reRender();
    }

    data() {
        return {...super.data(), tenants: this.tenants, editable: this.editable, needsSelection: !this.tenantId, error: this.error,
            predicates: this.predicates.map(p => ({...p, label: predicateLabel(this, p.key, p.label),
                inverse: predicateLabel(this, p.key, p.inverse, true), schemaText: JSON.stringify(p.qualifierSchema, null, 2),
                endpointText: `${p.subjects === '*' ? '*' : p.subjects.map(type => this.translate(type, 'scopeNames')).join(', ')} → ${p.objects === '*' ? '*' : p.objects.map(type => this.translate(type, 'scopeNames')).join(', ')}`}))};
    }
    afterRender() { super.afterRender(); this.el.querySelector('[data-name="tenant"]').value = this.tenantId || ''; }
    async edit(predicate = null) {
        if (!this.editable || predicate?.builtin) return;
        const view = await this.createView('editor', 'feature-record-knowledge:views/modals/predicate', {predicate, tenantId: this.tenantId, scopes: this.scopes});
        this.listenToOnce(view, 'saved', () => this.load());
        view.render();
    }
    async removePredicate(id) {
        if (!this.editable) return;
        await this.confirm(this.translate('confirmRemove', 'messages'));
        await Espo.Ajax.deleteRequest(`RecordPredicate/${id}`);
        await this.load();
    }
    onRemove() { this.generation = (this.generation || 0) + 1; super.onRemove(); }
}
