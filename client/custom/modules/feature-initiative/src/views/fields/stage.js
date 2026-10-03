define('feature-initiative:views/fields/stage', ['views/fields/link'], function (Dep) {
    return Dep.extend({
        selectPrimaryFilterName: 'active',
        createDisabled: true,

        setup: function () {
            Dep.prototype.setup.call(this);

            this.listenTo(this.model, 'change:initiativeTypeId', () => {
                if (!this.isEditMode() || !this.model.previous('initiativeTypeId')) {
                    return;
                }

                this.model.set({stageId: null, stageName: null});
            });
        },

        // Espo uses these filters for BOTH autocomplete and the select dialog.
        getSelectFilters: function () {
            const id = this.model.get('initiativeTypeId');

            if (!id) {
                return {noInitiativeType: {type: 'isNull', attribute: 'id'}};
            }

            return {
                initiativeType: {
                    type: 'equals',
                    attribute: 'initiativeTypeId',
                    value: id,
                    data: {type: 'is', idValue: id, nameValue: this.model.get('initiativeTypeName')},
                },
            };
        },

        actionSelect: function () {
            if (!this.model.get('initiativeTypeId')) {
                Espo.Ui.warning(this.translate('selectInitiativeTypeFirst', 'messages', 'Initiative'));

                return;
            }

            return Dep.prototype.actionSelect.call(this);
        },
    });
});
