define('global:views/opportunity/fields/stage-required-values', ['global:views/fields/custom-fields'], Dep => Dep.extend({
    loadMeta() {
        this.meta = { groups: [{ name: '_general', fields: this.options.fields }] };
        return Promise.resolve();
    },

    toInputName(valueKey) {
        return 'requiredField' + this.options.fields.findIndex(field => field.valueKey === valueKey);
    },

    validate() {
        if (this._fieldViewKeys.length !== this.options.fields.length) return true;
        let invalid = false;
        for (const key of this._fieldViewKeys || []) {
            const field = this.getView(key);
            if (!field?.isFullyRendered()) return true;
            field.model.set(field.fetch(), { silent: true });
            invalid = field.validate() || invalid;
            const value = field.model.get(field.name);
            if (typeof value === 'string' && value.trim() === '') {
                field.showValidationMessage(this.translate('required', 'messages'));
                invalid = true;
            }
        }
        return this.validateCustomFieldsRequired() || invalid;
    },
}));
