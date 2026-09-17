define('global:views/opportunity/fields/stage-timing', ['views/fields/base', 'global:helpers/stage-time'], function (Base, Time) {
    return Base.extend({
        detailTemplate: 'global:opportunity/fields/stage-timing',
        listTemplate: 'global:opportunity/fields/stage-timing',

        setup: function () {
            Base.prototype.setup.call(this);
            this.listenTo(this.model, 'sync change:currentStageVisitId change:status', () => this.updateTiming());
            const interval = setInterval(() => this.updateTiming(), 30000);
            this.on('remove', () => clearInterval(interval));
        },

        getAttributeList: function () {
            return ['status', 'currentStageVisitId', 'stageEnteredAt', 'stageTargetTimeSeconds', 'stageDueAt', 'stageTimingIsPartial'];
        },

        data: function () {
            return {...Base.prototype.data.call(this), ...this.timingData()};
        },

        timingData: function () {
            return Time.describe(this.model.attributes, label => this.translate(label, 'labels', 'Opportunity'));
        },

        updateTiming: function () {
            if (!this.isRendered()) return;
            const data = this.timingData();
            this.$el.find('[data-role="stage-timing"]').text(data.text)
                .toggleClass('text-danger', data.overdue).toggleClass('text-muted', !data.overdue);
        },

        fetch: function () { return {}; },
    });
});
