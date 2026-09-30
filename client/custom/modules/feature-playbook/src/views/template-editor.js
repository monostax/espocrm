import ModalView from 'views/modal';

export default class extends ModalView {
    template = 'feature-playbook:template-editor';
    cssName = 'edit-modal';

    events = {
        'submit form': function (event) { event.preventDefault(); this.actionSave(); },
        'click [data-editor-add]': function () {
            this.capture();
            if (this.draft.steps.length >= 100) return;
            this.draft.steps.push({name: '', kind: 'Check', instructions: '', references: []});
            this.reRender();
        },
        'click [data-editor-remove]': function (event) {
            this.capture();
            this.draft.steps.splice(Number(event.currentTarget.dataset.editorRemove), 1);
            this.reRender();
        },
        'click [data-editor-move]': function (event) {
            this.capture();
            const button = event.currentTarget;
            const index = Number(button.dataset.index);
            const target = index + Number(button.dataset.editorMove);
            if (target < 0 || target >= this.draft.steps.length) return;
            const [step] = this.draft.steps.splice(index, 1);
            this.draft.steps.splice(target, 0, step);
            this.reRender();
        },
    };

    setup() {
        super.setup();
        this.headerText = this.translate('manageTemplates', 'labels', 'Playbook');
        this.buttonList = [{name: 'save', label: 'Save', style: 'primary'}, {name: 'cancel', label: 'Cancel'}];
        const template = this.options.template;
        this.draft = template ? {
            id: template.id, name: template.name, status: template.status,
            expectedRevision: template.revision, steps: Espo.Utils.cloneDeep(template.steps),
        } : {name: '', status: 'Draft', steps: [{name: '', kind: 'Check', instructions: '', references: []}]};
    }

    data() {
        return {
            name: this.draft.name,
            statuses: ['Draft', 'Published', 'Archived'].map(status => ({
                value: status, label: this.translate(status, 'labels', 'Playbook'), selected: status === this.draft.status,
            })),
            steps: this.draft.steps.map((step, index) => ({
                ...step, index, task: step.kind === 'Task', referenceText: step.references.join('\n'),
                first: index === 0, last: index === this.draft.steps.length - 1,
            })),
            full: this.draft.steps.length >= 100,
        };
    }

    capture() {
        const form = this.element.querySelector('form');
        this.draft.name = form.elements.namedItem('name').value;
        this.draft.status = form.elements.namedItem('status').value;
        this.draft.steps = Array.from(form.querySelectorAll('[data-editor-step]')).map(row => ({
            name: row.querySelector('[name="stepName"]').value,
            kind: row.querySelector('[name="kind"]').value,
            instructions: row.querySelector('[name="instructions"]').value,
            references: row.querySelector('[name="references"]').value.split('\n').map(value => value.trim()).filter(Boolean),
        }));
    }

    async actionSave() {
        if (this.busy || !this.element.querySelector('form').reportValidity()) return;
        this.capture();
        this.busy = true;
        this.disableButton('save');
        this.$el.find('form input, form textarea, form select, form button').prop('disabled', true);
        try {
            const endpoint = this.options.accountId
                ? `PlaybookWorkspace/${encodeURIComponent(this.options.accountId)}/templates`
                : `Opportunity/${encodeURIComponent(this.options.opportunityId)}/playbooks/templates`;
            await Espo.Ajax.postRequest(endpoint, this.draft);
            this.trigger('saved');
            this.close();
        } catch (error) {
            error?.setHandled?.();
            Espo.Ui.error(this.translate(error?.status === 409 ? 'templateConflict' : 'error', 'labels', 'Playbook'));
        } finally {
            this.busy = false;
            this.enableButton('save');
            this.$el.find('form input, form textarea, form select, form button').prop('disabled', false);
        }
    }
}
