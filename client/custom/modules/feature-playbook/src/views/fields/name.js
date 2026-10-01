import VarcharFieldView from 'views/fields/varchar';

export default class extends VarcharFieldView {
    listTemplate = 'feature-playbook:fields/name';

    getAttributeList() {
        return [...super.getAttributeList(), 'canEdit', 'opportunityId'];
    }

    setup() {
        super.setup();
        this.addHandler('click', '[data-edit-template]', () => {
            this.model.collection.trigger('edit-template', this.model.id);
        });
        this.addHandler('keydown', '[data-edit-template]', event => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            this.model.collection.trigger('edit-template', this.model.id);
        });
    }

    data() {
        return {
            ...super.data(),
            canEdit: this.model.get('canEdit'),
            templateUrl: this.model.entityType === 'Playbook' && this.model.collection?.url?.startsWith('PlaybookWorkspace/')
                ? `#PlaybookManager/view/${new URLSearchParams({
                    accountId: decodeURIComponent(this.model.collection.url.split('/')[1]),
                    templateId: this.model.id,
                })}` : null,
            opportunityUrl: this.model.get('opportunityId')
                ? `#Opportunity/view/${encodeURIComponent(this.model.get('opportunityId'))}` : null,
        };
    }
}
