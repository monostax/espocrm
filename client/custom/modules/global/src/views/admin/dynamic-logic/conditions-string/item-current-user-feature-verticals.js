import DynamicLogicConditionsStringItemBaseView from 'views/admin/dynamic-logic/conditions-string/item-base';

export default class extends DynamicLogicConditionsStringItemBaseView {

    createValueFieldView() {
        const key = this.getValueViewKey();

        const verticals = this.getMetadata().get(['app', 'featureVerticals']) || {};
        const options = Object.keys(verticals);

        this.createView('value', 'views/fields/enum', {
            model: this.model,
            name: this.field,
            selector: `[data-view-key="${key}"]`,
            params: {
                options: options,
            },
            readOnly: true,
        });
    }
}
