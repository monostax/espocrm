import ModalView from 'views/modal';
import {identity} from 'feature-record-knowledge:content';

export default class extends ModalView {
    templateContent = `
        <div class="form-group"><label>Predicate</label><select class="form-control" data-name="predicate">{{#each predicates}}<option value="{{this}}">{{this}}</option>{{/each}}</select></div>
        <div class="form-group"><label>Object record</label><input class="form-control" data-name="search" aria-label="Search record" placeholder="Search records…"><select class="form-control" data-name="object" aria-label="Object record"></select></div>
        <div class="form-group"><label>Qualifiers (JSON)</label><textarea class="form-control" data-name="qualifiers">{}</textarea></div>
        <div class="form-group"><label>Exact evidence quote from the current overview</label><textarea class="form-control" data-name="quote" rows="4"></textarea></div>
        <div class="form-group"><label>Evidence start byte (optional, for repeated quotes)</label><input class="form-control" data-name="start" type="number" min="0"></div>
        <pre style="white-space:pre-wrap;max-height:240px;overflow:auto">{{body}}</pre>
        <div class="text-danger" role="alert" data-name="error"></div>
    `
    setup() {
        super.setup();
        this.parentModel = this.options.parentModel;
        this.schema = this.options.schema;
        this.headerText = this.translate('Add relation');
        this.buttonList = [{name: 'save', label: 'Save', style: 'primary'}, {name: 'cancel', label: 'Cancel'}];
        this.wait(Espo.Ajax.getRequest('RecordKnowledge/overview', identity(this.parentModel)).then(data => { this.document = data; }));
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
        const predicates = Object.entries(this.schema || {}).filter(([, def]) => def.subjects === '*' || def.subjects.includes(type)).map(([name]) => name);
        return {...super.data(), predicates, body: this.document?.body};
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
            select.add(new Option(`${ref.label} (${ref.entityType})`, index));
        }
    }
    async actionSave() {
        if (this.saving) return;
        const value = name => this.el.querySelector(`[data-name="${name}"]`).value;
        const object = this.results?.[value('object')];
        const quote = value('quote');
        this.saving = true;
        this.disableButton('save');
        try {
            if (!object || !quote || !this.document?.revision) throw new Error('Select a record and an exact evidence quote.');
            const span = value('start') === '' ? {} : {evidenceStart: Number(value('start')),
                evidenceEnd: Number(value('start')) + new TextEncoder().encode(quote).length};
            const input = {subjectType: this.parentModel.entityType, subjectId: this.parentModel.id,
                objectType: object.entityType, objectId: object.recordId, predicate: value('predicate'),
                qualifiers: JSON.parse(value('qualifiers')), evidenceQuote: quote, ...span,
                sourceRevisionId: this.document.revision.id, idempotencyKey: this.idempotencyKey};
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
