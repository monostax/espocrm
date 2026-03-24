import DynamicLogicConditionFieldTypeBaseView from 'views/admin/dynamic-logic/conditions/field-types/base';
import Model from 'model';

export default class extends DynamicLogicConditionFieldTypeBaseView {

    translateLeftString() {
        return '$' + this.translate('User', 'scopeNames') + '.' +
            this.translate('featureVerticals', 'fields', 'User');
    }

    getValueViewName() {
        return 'views/fields/enum';
    }

    async createModel() {
        const model = new Model();

        const verticals = this.getMetadata().get(['app', 'featureVerticals']) || {};
        const options = Object.keys(verticals);

        model.setDefs({
            fields: {
                featureVerticals: {
                    type: 'enum',
                    options: options,
                },
            },
        });

        return model;
    }

    populateValues() {
        if (this.itemData.value) {
            this.model.set('featureVerticals', this.itemData.value);
        }
    }

    getValueFieldName() {
        return 'featureVerticals';
    }

    fetch() {
        /** @type {import('views/fields/base').default} */
        const valueView = this.getView('value');

        if (valueView) {
            valueView.fetchToModel();
        }

        return {
            type: this.type,
            attribute: '$user.featureVerticals',
            value: this.model.get('featureVerticals'),
            data: {
                field: 'featureVerticals',
                type: this.type,
            },
        };
    }
}
