import BottomPanelView from 'views/record/panels/bottom';
import {identity} from 'feature-record-knowledge:content';
import {predicateLabel} from 'feature-record-knowledge:labels';

export default class extends BottomPanelView {
    templateContent = `
        <div class="form-inline margin-bottom">
            {{#if tenantOptions}}<select class="form-control input-sm" aria-label="{{translate 'tenant' category='fields' scope='RecordRelation'}}" data-name="tenant"><option value="">{{translate 'Select workspace' scope='RecordRelation'}}</option>{{#each tenantOptions}}<option value="{{id}}">{{name}}</option>{{/each}}</select>{{/if}}
            <select class="form-control input-sm" aria-label="{{translate 'direction' category='fields' scope='RecordRelation'}}" data-name="direction">
                <option value="all">{{translate 'All directions' scope='RecordRelation'}}</option><option value="outgoing">{{translateOption 'outgoing' field='direction' scope='RecordRelation'}}</option><option value="incoming">{{translateOption 'incoming' field='direction' scope='RecordRelation'}}</option>
            </select>
            <select class="form-control input-sm" aria-label="{{translate 'status' category='fields' scope='RecordRelation'}}" data-name="status">
                <option value="">{{translate 'All statuses' scope='RecordRelation'}}</option><option value="suggested">{{translateOption 'suggested' field='status' scope='RecordRelation'}}</option><option value="confirmed">{{translateOption 'confirmed' field='status' scope='RecordRelation'}}</option><option value="rejected">{{translateOption 'rejected' field='status' scope='RecordRelation'}}</option><option value="stale">{{translateOption 'stale' field='status' scope='RecordRelation'}}</option>
            </select>
            {{#if canAuthor}}<button type="button" class="btn btn-default btn-sm" data-action="add">{{translate 'Add relation'}}</button>{{/if}}
        </div>
        {{#if error}}<span role="status">{{translate 'knowledgeUnavailable' category='messages'}}</span>{{/if}}
        <ul class="list-unstyled">
        {{#each items}}<li class="margin-bottom">
            <a href="#{{displaySubjectType}}/view/{{displaySubjectId}}">{{displaySubjectLabel}}</a>
            <strong>{{displayPredicate}}</strong>
            <a href="#{{displayObjectType}}/view/{{displayObjectId}}">{{displayObjectLabel}}</a>
            <small>{{translateOption direction field='direction' scope='RecordRelation'}} · {{translateOption status field='status' scope='RecordRelation'}} · {{translateOption origin field='origin' scope='RecordRelation'}}</small>
            <div class="text-muted small">{{qualifierText}}</div>
            {{#if sourceRevisionId}}<a href="#RecordKnowledge/revision/{{sourceRevisionId}}" data-action="evidence" data-id="{{sourceRevisionId}}">{{translate 'Evidence'}}</a>
                <blockquote class="small">{{evidenceQuote}}</blockquote>{{/if}}
            {{#if provenance}}<small>{{translate 'Opportunity' category='scopeNames'}}/{{provenance.recordId}} · {{translate provenance.field category='fields' scope='Opportunity'}}</small>{{/if}}
            {{#if editable}}
                <button class="btn btn-default btn-sm" type="button" data-action="confirm" data-id="{{id}}">{{translate 'Confirm'}}</button>
                <button class="btn btn-default btn-sm" type="button" data-action="reject" data-id="{{id}}">{{translate 'Reject'}}</button>
            {{/if}}
        </li>{{/each}}
        </ul>
        {{#if empty}}<span class="text-muted">{{translate 'noRelations' category='messages'}}</span>{{/if}}
        {{#if cursor}}<button class="btn btn-default btn-sm" type="button" data-action="more">{{translate 'More'}}</button>{{/if}}
    `
    setup() {
        super.setup();
        this.items = [];
        this.direction = 'all';
        this.status = '';
        this.authorAllowed = !this.model.get('knowledgeRecordType') && this.getAcl().checkModel(this.model, 'edit') && this.getUser().get('type') !== 'api';
        this.listenTo(this.model, 'sync knowledge:updated', () => this.loadSchema().then(() => this.load(false)));
        this.addHandler('change', 'select', (event, target) => {
            if (target.dataset.name === 'tenant') {
                this.tenantId = target.value || null;
                this.loadSchema().then(() => this.load(false));
                return;
            }
            this.direction = this.el.querySelector('[data-name="direction"]').value;
            this.status = this.el.querySelector('[data-name="status"]').value;
            this.load(false);
        });
        this.addHandler('click', '[data-action="more"]', () => this.load(true));
        this.addHandler('click', '[data-action="add"]', () => this.author());
        this.addHandler('click', '[data-action="confirm"], [data-action="reject"]', (event, target) => this.decide(target));
        this.addHandler('click', '[data-action="evidence"]', (event, target) => {
            event.preventDefault();
            this.createView('revision', 'feature-record-knowledge:views/modals/revision', {revisionId: target.dataset.id}).then(view => view.render());
        });
        this.wait(Promise.all([
            this.loadSchema(),
            this.load(false),
        ]));
    }
    async loadSchema() {
        const generation = (this.schemaGeneration || 0) + 1;
        this.schemaGeneration = generation;
        try {
            const data = await Espo.Ajax.getRequest('RecordKnowledge/schema', {...identity(this.model), tenantId: this.tenantId || ''});
            if (this.isRemoved() || generation !== this.schemaGeneration) return;
            this.schema = data.predicates; this.tenantId = data.tenantId; this.tenantOptions = data.tenantOptions;
            this.canAuthor = this.authorAllowed && !!data.tenantId;
        } catch (error) {
            if (generation !== this.schemaGeneration) return;
            this.schema = {}; this.canAuthor = false; this.error = true;
        }
        if (this.isRendered()) this.reRender();
    }
    async load(more) {
        if (!this.model.id) return;
        const generation = (this.generation || 0) + 1;
        this.generation = generation;
        this.loading = true;
        this.error = false;
        try {
            const data = await Espo.Ajax.getRequest('RecordKnowledge/relations', {...identity(this.model), direction: this.direction,
                status: this.status, cursor: more ? this.cursor : '', tenantId: this.tenantId || ''});
            if (generation !== this.generation) return;
            this.items = more ? [...this.items, ...data.list] : data.list;
            this.cursor = data.cursor;
        } catch (e) {
            if (generation !== this.generation) return;
            this.items = []; this.cursor = null; this.error = true;
        }
        this.loading = false;
        if (this.isRendered()) this.reRender();
    }
    data() {
        const items = this.items.map(item => {
            const incoming = item.direction === 'incoming';
            return {...item, displayPredicate: predicateLabel(this, item.predicate, incoming ? item.inverseLabel : item.predicateLabel, incoming),
                displaySubjectType: incoming ? item.objectType : item.subjectType, displaySubjectId: incoming ? item.objectId : item.subjectId,
                displaySubjectLabel: incoming ? item.objectLabel : item.subjectLabel,
                displayObjectType: incoming ? item.subjectType : item.objectType, displayObjectId: incoming ? item.subjectId : item.objectId,
                displayObjectLabel: incoming ? item.subjectLabel : item.objectLabel, qualifierText: JSON.stringify(item.qualifiers || {})};
        });
        return {...super.data(), items, canAuthor: this.canAuthor, tenantOptions: this.tenantOptions?.length ? this.tenantOptions : null, cursor: this.cursor, error: this.error,
            empty: !this.loading && !this.error && !items.length};
    }
    afterRender() {
        super.afterRender();
        this.el.querySelector('[data-name="direction"]').value = this.direction;
        this.el.querySelector('[data-name="status"]').value = this.status;
        const tenant = this.el.querySelector('[data-name="tenant"]');
        if (tenant) tenant.value = this.tenantId || '';
    }
    async author() {
        const view = await this.createView('author', 'feature-record-knowledge:views/modals/relation', {parentModel: this.model, schema: this.schema, tenantId: this.tenantId});
        this.listenToOnce(view, 'saved', () => this.model.trigger('knowledge:updated'));
        view.render();
    }
    async decide(target) {
        target.disabled = true;
        try {
            await Espo.Ajax.postRequest('RecordKnowledge/decide', {id: target.dataset.id,
                status: target.dataset.action === 'confirm' ? 'confirmed' : 'rejected'});
            this.model.trigger('knowledge:updated');
        } finally { target.disabled = false; }
    }
    onRemove() { this.generation = (this.generation || 0) + 1; this.schemaGeneration = (this.schemaGeneration || 0) + 1; super.onRemove(); }
}
