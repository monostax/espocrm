import ModalView from 'views/modal';

export default class extends ModalView {
    templateContent = `
        <div class="form-group"><label>{{translate 'code' category='fields' scope='RecordPredicate'}}</label><input class="form-control" data-name="code" value="{{predicate.code}}"{{#if existing}} disabled{{/if}}></div>
        <div class="form-group"><label>{{translate 'name' category='fields' scope='RecordPredicate'}}</label><input class="form-control" data-name="name" value="{{predicate.label}}"></div>
        <div class="form-group"><label>{{translate 'inverseLabel' category='fields' scope='RecordPredicate'}}</label><input class="form-control" data-name="inverse" value="{{predicate.inverse}}"></div>
        <div class="form-group"><label>{{translate 'description' category='fields' scope='RecordPredicate'}}</label><textarea class="form-control" data-name="description">{{predicate.description}}</textarea></div>
        <div class="form-group"><label>{{translate 'subjectTypes' category='fields' scope='RecordPredicate'}}</label><select multiple class="form-control" data-name="subjects"{{#if predicate.referenced}} disabled{{/if}}>{{#each scopes}}<option value="{{this}}">{{this}}</option>{{/each}}</select></div>
        <div class="form-group"><label>{{translate 'objectTypes' category='fields' scope='RecordPredicate'}}</label><select multiple class="form-control" data-name="objects"{{#if predicate.referenced}} disabled{{/if}}>{{#each scopes}}<option value="{{this}}">{{this}}</option>{{/each}}</select></div>
        <div class="form-group"><label>{{translate 'aliases' category='fields' scope='RecordPredicate'}} (comma-separated)</label><input class="form-control" data-name="aliases" value="{{aliases}}"></div>
        <div class="form-group"><label>{{translate 'qualifierSchema' category='fields' scope='RecordPredicate'}}</label><textarea class="form-control" data-name="schema" rows="8"{{#if predicate.referenced}} readonly{{/if}}></textarea></div>
        <label><input type="checkbox" data-name="active"{{#if predicate.active}} checked{{/if}}> {{translate 'isActive' category='fields' scope='RecordPredicate'}}</label>
        <p class="text-muted small">Object schema: properties use string, integer, number, boolean; optional enum, string date/maxLength, numeric minimum/maximum. additionalProperties must be false.</p>
        <div class="text-danger" role="alert" data-name="error"></div>
    `
    setup() {
        super.setup();
        this.predicate = this.options.predicate || {active: true, aliases: [], subjects: [], objects: [], qualifierSchema: {type: 'object', properties: {}, required: [], additionalProperties: false}};
        this.headerText = this.translate('Knowledge Predicates', 'labels', 'Configurations');
        this.buttonList = [{name: 'save', label: 'Save', style: 'primary'}, {name: 'cancel', label: 'Cancel'}];
    }
    data() { return {...super.data(), predicate: this.predicate, existing: !!this.predicate.id, scopes: this.options.scopes, aliases: this.predicate.aliases.join(', ')}; }
    afterRender() {
        super.afterRender();
        for (const field of ['subjects', 'objects']) {
            const values = this.predicate[field];
            for (const option of this.el.querySelector(`[data-name="${field}"]`).options) option.selected = values.includes(option.value);
        }
        this.el.querySelector('[data-name="schema"]').value = JSON.stringify(this.predicate.qualifierSchema, null, 2);
    }
    async actionSave() {
        if (this.saving) return;
        this.saving = true; this.disableButton('save');
        const value = field => this.el.querySelector(`[data-name="${field}"]`).value;
        const selected = field => [...this.el.querySelector(`[data-name="${field}"]`).selectedOptions].map(option => option.value);
        try {
            const data = {name: value('name'), inverseLabel: value('inverse'), description: value('description'),
                aliases: value('aliases').split(',').map(v => v.trim()).filter(Boolean), isActive: this.el.querySelector('[data-name="active"]').checked};
            if (!this.predicate.referenced) Object.assign(data, {subjectTypes: selected('subjects'), objectTypes: selected('objects'), qualifierSchema: JSON.parse(value('schema'))});
            if (this.predicate.id) await Espo.Ajax.putRequest(`RecordPredicate/${this.predicate.id}`, data, {headers: {'X-Version-Number': this.predicate.versionNumber}});
            else await Espo.Ajax.postRequest('RecordPredicate', {...data, tenantId: this.options.tenantId, code: value('code')});
            this.trigger('saved'); this.close();
        } catch (error) {
            this.el.querySelector('[data-name="error"]').textContent = error.message || 'Predicate update failed.';
            this.enableButton('save');
        } finally { this.saving = false; }
    }
}
