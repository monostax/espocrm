import ModalView from 'views/modal';
import {identity} from 'feature-record-knowledge:content';
import {predicateLabel} from 'feature-record-knowledge:labels';

export default class extends ModalView {
    templateContent = `
        <div class="form-group"><label>{{translate 'predicate' category='fields' scope='RecordRelation'}}</label><select class="form-control" data-name="predicate">{{#each predicates}}<option value="{{key}}">{{label}}</option>{{/each}}</select></div>
        <div class="form-group"><label>{{translate 'Object record' scope='RecordRelation'}}</label><input class="form-control" data-name="search" aria-label="{{translate 'Search records' scope='RecordRelation'}}" placeholder="{{translate 'Search records' scope='RecordRelation'}}"><select class="form-control" data-name="object" aria-label="{{translate 'Object record' scope='RecordRelation'}}"></select></div>
        <div class="form-group"><label>{{translate 'Qualifiers (JSON)' scope='RecordRelation'}}</label><textarea class="form-control" data-name="qualifiers">{}</textarea></div>
        <div class="checkbox"><label><input type="checkbox" data-name="includeEvidence">{{translate 'Include evidence'}}</label></div>
        <div data-name="evidenceFields" hidden>
            <div class="form-group"><label>{{translate 'Exact evidence quote'}}</label><textarea class="form-control" data-name="quote" rows="4"></textarea></div>
            <div class="form-group"><label>{{translate 'Evidence start byte'}}</label><input class="form-control" data-name="start" type="number" min="0"></div>
            <pre data-name="evidenceBody" style="white-space:pre-wrap;max-height:240px;overflow:auto"></pre>
        </div>
        <div class="text-danger" role="alert" data-name="error"></div>
    `
    setup() {
        super.setup();
        this.parentModel = this.options.parentModel;
        this.schema = this.options.schema;
        this.headerText = this.translate('Add relation');
        this.buttonList = [{name: 'save', label: 'Save', style: 'primary'}, {name: 'cancel', label: 'Cancel'}];
        this.addHandler('change', '[data-name="includeEvidence"]', () => this.toggleEvidence());
        this.idempotencyKey = crypto.randomUUID();
        this.addHandler('input', '[data-name="search"]', () => {
            clearTimeout(this.searchTimer);
            const generation = (this.searchGeneration || 0) + 1;
            this.searchGeneration = generation;
            this.searchTimer = setTimeout(() => this.search(generation), 200);
        });
    }
    data() {
        const type = this.parentModel.entityType;
        const predicates = Object.entries(this.schema || {}).filter(([, def]) => def.subjects === '*' || def.subjects.includes(type)).map(([key, def]) => ({key, label: predicateLabel(this, key, def.label)}));
        return {...super.data(), predicates};
    }
    async toggleEvidence() {
        const enabled = this.el.querySelector('[data-name="includeEvidence"]').checked;
        this.el.querySelector('[data-name="evidenceFields"]').hidden = !enabled;
        this.el.querySelector('[data-name="error"]').textContent = '';
        if (!enabled || this.document) return;
        try {
            const data = await Espo.Ajax.getRequest('RecordKnowledge/overview', identity(this.parentModel));
            if (this.isRemoved()) return;
            this.document = data;
            this.el.querySelector('[data-name="evidenceBody"]').textContent = data.body;
        } catch (error) {
            if (!this.isRemoved() && this.el.querySelector('[data-name="includeEvidence"]').checked) {
                this.el.querySelector('[data-name="error"]').textContent = this.translate('knowledgeUnavailable', 'messages');
            }
        }
    }
    async search(generation) {
        const data = await Espo.Ajax.getRequest('EditorReference/search', {q: this.el.querySelector('[data-name="search"]').value});
        if (generation !== this.searchGeneration || this.isRemoved()) return;
        const select = this.el.querySelector('[data-name="object"]');
        select.replaceChildren();
        this.results = data.list;
        const predicate = this.el.querySelector('[data-name="predicate"]').value;
        for (const [index, ref] of this.results.entries()) {
            const objects = this.schema[predicate]?.objects;
            if (objects !== '*' && !objects?.includes(ref.entityType)) continue;
            select.add(new Option(`${ref.label} (${this.translate(ref.entityType, 'scopeNames')})`, index));
        }
    }
    async actionSave() {
        if (this.saving) return;
        const value = name => this.el.querySelector(`[data-name="${name}"]`).value;
        const object = this.results?.[value('object')];
        this.saving = true;
        this.disableButton('save');
        try {
            if (!object) throw new Error(this.translate('relationRecordRequired', 'messages'));
            const evidence = {};
            if (this.el.querySelector('[data-name="includeEvidence"]').checked) {
                const quote = value('quote');
                if (!quote || !this.document?.revision) throw new Error(this.translate('relationEvidenceRequired', 'messages'));
                Object.assign(evidence, {sourceRevisionId: this.document.revision.id, evidenceQuote: quote});
                if (value('start') !== '') Object.assign(evidence, {evidenceStart: Number(value('start')),
                    evidenceEnd: Number(value('start')) + new TextEncoder().encode(quote).length});
            }
            const input = {subjectType: this.parentModel.entityType, subjectId: this.parentModel.id,
                tenantId: this.options.tenantId,
                objectType: object.entityType, objectId: object.recordId, predicate: value('predicate'),
                qualifiers: JSON.parse(value('qualifiers')), ...evidence, idempotencyKey: this.idempotencyKey};
            // Preserve the same payload/key across retries; edits are a new submission.
            const signature = JSON.stringify({...input, idempotencyKey: ''});
            if (this.lastSignature && this.lastSignature !== signature) this.idempotencyKey = input.idempotencyKey = crypto.randomUUID();
            this.lastSignature = signature;
            await Espo.Ajax.postRequest('RecordKnowledge/author', input);
            this.trigger('saved');
            this.close();
        } catch (error) {
            this.el.querySelector('[data-name="error"]').textContent = error.message || this.translate('knowledgeUnavailable', 'messages');
            this.enableButton('save');
        } finally { this.saving = false; }
    }
    onRemove() { clearTimeout(this.searchTimer); this.searchGeneration = (this.searchGeneration || 0) + 1; super.onRemove(); }
}
