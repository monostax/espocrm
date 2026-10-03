define('feature-initiative:views/initiative-relation/row-actions', [
    'views/record/row-actions/relationship',
], function (RelationshipActions) {
    return RelationshipActions.extend({
        getActionList: function () {
            const list = RelationshipActions.prototype.getActionList.call(this);

            if (
                this.model.entityType === 'InitiativeRelation' &&
                !this.options.removeDisabled &&
                this.getAcl().checkModel(this.model, 'delete')
            ) {
                list.push({
                    action: 'quickRemove',
                    text: this.translate('Remove Link', 'labels', 'InitiativeRelation'),
                    data: {id: this.model.id},
                    groupIndex: 1,
                    iconClass: 'fas fa-unlink',
                });
            }

            // Native quickRemove confirms, deletes this association model and refreshes
            // the relationship list. It never deletes model.parentId / the parent record.
            return list;
        },
    });
});
