define('global:views/fields/time-budget', ['views/fields/base', 'global:helpers/stage-time'], function (Base, Time) {
    return Base.extend({
        type: 'int',
        detailTemplate: 'global:fields/time-budget/detail',
        listTemplate: 'global:fields/time-budget/detail',
        editTemplate: 'global:fields/time-budget/edit',
        validations: ['budget'],

        data: function () {
            const seconds = this.model.get(this.name);
            return {
                ...Base.prototype.data.call(this),
                formatted: seconds == null ? '—' : Time.format(seconds),
                days: seconds == null ? '' : Math.floor(seconds / 86400),
                hours: seconds == null ? '' : Math.floor(seconds % 86400 / 3600),
                minutes: seconds == null ? '' : Math.floor(seconds % 3600 / 60),
            };
        },

        afterRender: function () {
            Base.prototype.afterRender.call(this);
            this.$el.find('input[data-unit]').on('input', () => this.trigger('change'));
        },

        readBudget: function () {
            const inputs = ['days', 'hours', 'minutes'].map(unit => this.$el.find(`input[data-unit="${unit}"]`));
            if (inputs.some(input => input.get(0)?.validity.badInput)) return NaN;
            const values = inputs.map(input => String(input.val() ?? '').trim());
            if (values.every(value => value === '')) return null;
            if (values.some(value => value !== '' && !/^\d+$/.test(value))) return NaN;
            return Number(values[0]) * 86400 + Number(values[1]) * 3600 + Number(values[2]) * 60;
        },

        fetch: function () {
            return {[this.name]: this.readBudget()};
        },

        validateBudget: function () {
            const value = this.readBudget();
            if (value === null || (Number.isSafeInteger(value) && value >= 60 && value <= 2147483640)) return false;
            this.showValidationMessage(this.translate('Invalid target time', 'messages', 'OpportunityStage'));
            return true;
        },
    });
});
