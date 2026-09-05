define('feature-simple-journey:views/fields/stage', ['views/fields/link'], function (Dep) {
    return Dep.extend({
        selectPrimaryFilterName: 'active',
        createDisabled: true,

        setup: function () {
            Dep.prototype.setup.call(this);

            this.listenTo(this.model, 'change:journeyId', () => {
                if (!this.isEditMode() || !this.model.previous('journeyId')) {
                    return;
                }

                this.model.set({stageId: null, stageName: null});
            });
        },

        // Espo uses these filters for BOTH autocomplete and the select dialog.
        getSelectFilters: function () {
            const id = this.model.get('journeyId');

            if (!id) {
                return {noJourney: {type: 'isNull', attribute: 'id'}};
            }

            return {
                journey: {
                    type: 'equals',
                    attribute: 'journeyId',
                    value: id,
                    data: {type: 'is', idValue: id, nameValue: this.model.get('journeyName')},
                },
            };
        },

        actionSelect: function () {
            if (!this.model.get('journeyId')) {
                Espo.Ui.warning(this.translate('selectJourneyFirst', 'messages', 'SimpleJourneyRecord'));

                return;
            }

            return Dep.prototype.actionSelect.call(this);
        },
    });
});
