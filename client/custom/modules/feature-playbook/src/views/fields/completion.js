import BaseFieldView from 'views/fields/base';

export default class extends BaseFieldView {
    listTemplate = 'feature-playbook:fields/completion';

    getAttributeList() {
        return ['completed', 'total'];
    }

    data() {
        const completed = this.model.get('completed') || 0;
        const total = this.model.get('total') || 0;

        return {...super.data(), completed, total, progressMax: total || 1};
    }
}
