define('global:views/opportunity/modals/stage-requirements', ['views/modal'], Dep => Dep.extend({
    templateContent: '<p><strong>{{opportunityName}}</strong></p><p>{{message}} <strong>{{stageName}}</strong></p><div class="stage-required-values">{{{values}}}</div>',

    data() {
        return {
            opportunityName: this.options.opportunityName,
            stageName: this.options.requirements.requirementMode === 'save' ? '' : this.options.requirements.stageName,
            message: this.translate(this.options.requirements.requirementMode === 'save'
                ? 'customFieldRequirementsIntro' : 'stageRequirementsIntro', 'messages', 'Opportunity'),
        };
    },

    setup() {
        Dep.prototype.setup.call(this);
        this.headerText = this.translate('Complete required fields', 'labels', 'Opportunity');
        this.buttonList = [
            { name: 'complete', label: 'Save', style: 'primary' },
            { name: 'cancel', label: 'Cancel' },
        ];
        this.wait(this.getModelFactory().create('Opportunity').then(model => {
            model.set('customFields', Espo.Utils.clone(this.options.values));
            return this.createView('values', 'global:views/opportunity/fields/stage-required-values', {
                model, name: 'customFields', mode: 'edit',
                fields: this.options.requirements.fields,
                selector: '.stage-required-values',
            });
        }));
    },

    actionComplete() {
        const field = this.getView('values');
        if (!field || field.validate()) return;
        this.trigger('complete', field.fetch().customFields);
        this.close();
    },

    actionCancel() { this.close(); },
}));
