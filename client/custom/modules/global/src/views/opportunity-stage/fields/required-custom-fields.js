define('global:views/opportunity-stage/fields/required-custom-fields', ['views/fields/multi-enum', 'global:helpers/custom-field-conditions'], (Dep, Conditions) => Dep.extend({
    setup() {
        Dep.prototype.setup.call(this);
        this.wait(this.loadOptions());
        this.listenTo(this.model, 'change:funnelId', () => {
            this.loadOptions().then(() => this.reRender());
        });
    },

    async loadOptions() {
        const funnelId = this.model.get('funnelId');
        this.optionRequest = (this.optionRequest || 0) + 1;
        const request = this.optionRequest;
        let fields = [];
        if (funnelId) {
            const funnel = await Espo.Ajax.getRequest('Funnel/' + encodeURIComponent(funnelId));
            const meta = await Espo.Ajax.getRequest('CustomField/action/meta', {
                entityType: 'Opportunity', tenantId: funnel.tenantId,
            });
            const context = { funnelId, opportunityStageId: this.model.id || null };
            fields = meta.groups.flatMap(group => group.fields.filter(field =>
                Conditions.evaluate(field.appliesWhen, context, true) !== false).map(field => ({
                ...field, label: group.name === '_general' ? field.label : `${group.label} / ${field.label}`,
            })));
        }
        if (request !== this.optionRequest) return;
        this.params.options = [...new Set([...fields.map(field => field.valueKey), ...(this.model.get(this.name) || [])])];
        this.translatedOptions = Object.fromEntries(fields.map(field => [field.valueKey, field.label]));
    },
}));
