import BottomPanelView from 'views/record/panels/bottom';
import {identity} from 'feature-record-knowledge:content';

export default class extends BottomPanelView {
    templateContent = `
        <div class="form-inline margin-bottom">
            <select class="form-control input-sm" aria-label="Direction" data-name="direction">
                <option value="all">All directions</option><option value="outgoing">Outgoing</option><option value="incoming">Incoming</option>
            </select>
            <select class="form-control input-sm" aria-label="Status" data-name="status">
                <option value="">All statuses</option><option>suggested</option><option>confirmed</option><option>rejected</option><option>stale</option>
            </select>
            {{#if canAuthor}}<button type="button" class="btn btn-default btn-sm" data-action="add">{{translate 'Add relation'}}</button>{{/if}}
        </div>
        {{#if error}}<span role="status">{{translate 'knowledgeUnavailable' category='messages'}}</span>{{/if}}
        <ul class="list-unstyled">
        {{#each items}}<li class="margin-bottom">
            <a href="#{{displaySubjectType}}/view/{{displaySubjectId}}">{{displaySubjectLabel}}</a>
            <strong>{{displayPredicate}}</strong>
            <a href="#{{displayObjectType}}/view/{{displayObjectId}}">{{displayObjectLabel}}</a>
            <small>{{direction}} · {{status}} · {{origin}}</small>
            <div class="text-muted small">{{qualifierText}}</div>
            {{#if sourceRevisionId}}<a href="#RecordKnowledge/revision/{{sourceRevisionId}}" data-action="evidence" data-id="{{sourceRevisionId}}">{{translate 'Evidence'}}</a>
                <blockquote class="small">{{evidenceQuote}}</blockquote>{{/if}}
            {{#if provenance}}<small>Opportunity/{{provenance.recordId}} · {{provenance.field}}</small>{{/if}}
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
        this.canAuthor = !this.model.get('knowledgeRecordType') && this.getAcl().checkModel(this.model, 'edit') && this.getUser().get('type') !== 'api';
        this.listenTo(this.model, 'sync knowledge:updated', () => this.load(false));
        this.addHandler('change', 'select', () => {
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
            Espo.Ajax.getRequest('RecordKnowledge/schema').then(data => { this.schema = data.predicates; }),
            this.load(false),
        ]));
    }
    async load(more) {
        if (!this.model.id) return;
        const generation = (this.generation || 0) + 1;
        this.generation = generation;
        this.loading = true;
        this.error = false;
        try {
            const data = await Espo.Ajax.getRequest('RecordKnowledge/relations', {...identity(this.model), direction: this.direction,
                status: this.status, cursor: more ? this.cursor : ''});
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
            return {...item, displayPredicate: incoming ? (this.schema?.[item.predicate]?.inverse || item.predicate) : item.predicate.replaceAll('_', ' '),
                displaySubjectType: incoming ? item.objectType : item.subjectType, displaySubjectId: incoming ? item.objectId : item.subjectId,
                displaySubjectLabel: incoming ? item.objectLabel : item.subjectLabel,
                displayObjectType: incoming ? item.subjectType : item.objectType, displayObjectId: incoming ? item.subjectId : item.objectId,
                displayObjectLabel: incoming ? item.subjectLabel : item.objectLabel, qualifierText: JSON.stringify(item.qualifiers || {})};
        });
        return {...super.data(), items, canAuthor: this.canAuthor, cursor: this.cursor, error: this.error,
            empty: !this.loading && !this.error && !items.length};
    }
    afterRender() {
        super.afterRender();
        this.el.querySelector('[data-name="direction"]').value = this.direction;
        this.el.querySelector('[data-name="status"]').value = this.status;
    }
    async author() {
        const view = await this.createView('author', 'feature-record-knowledge:views/modals/relation', {parentModel: this.model, schema: this.schema});
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
    onRemove() { this.generation = (this.generation || 0) + 1; super.onRemove(); }
}
