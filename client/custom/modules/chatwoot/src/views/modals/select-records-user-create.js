import SelectRecordsModalView from 'views/modals/select-records';
import RecordModal from 'helpers/record/modal';

class SelectRecordsUserCreateModalView extends SelectRecordsModalView {
    noCreateScopeList = ['Team', 'Role', 'Portal']

    async create() {
        if (this.onCreate) {
            this.onCreate();

            return;
        }

        if (this.options.triggerCreateEvent) {
            this.trigger('create');

            return;
        }

        let attributes;

        if (this.options.createAttributesProvider) {
            attributes = await this.createAttributesProvider();
        } else {
            attributes = this.options.createAttributes || {};
        }

        const helper = new RecordModal();

        await helper.showCreate(this, {
            entityType: this.entityType,
            fullFormDisabled: true,
            attributes: attributes,
            afterSave: model => {
                this.trigger('select', model);

                if (this.onSelect) {
                    this.onSelect([model]);
                }

                setTimeout(() => this.close(), 10);
            },
            beforeRender: view => {
                this.listenToOnce(view, 'leave', () => {
                    view.close();
                    this.close();
                });

                const recordView = view.getRecordView();

                if (!recordView) {
                    return;
                }

                ['isActive', 'phoneNumber', 'avatar', 'avatarColor', 'salutationName', 'userName'].forEach(
                    field => recordView.hideField(field, true)
                );

                ['teams', 'defaultTeam'].forEach(field => recordView.setFieldReadOnly(field, true));

                recordView.setFieldRequired('emailAddress');

                const syncUserNameFromEmail = () => {
                    const email = this.extractPrimaryEmail(recordView.model);

                    if (!email) {
                        return;
                    }

                    recordView.model.set('userName', email);
                };

                syncUserNameFromEmail();

                this.listenTo(recordView.model, 'change:emailAddress', () => syncUserNameFromEmail());
                this.listenTo(recordView.model, 'change:emailAddressData', () => syncUserNameFromEmail());
            },
        });
    }

    extractPrimaryEmail(model) {
        const direct = model.get('emailAddress');

        if (direct) {
            return direct;
        }

        const list = model.get('emailAddressData') || [];
        const first = Array.isArray(list) && list.length ? list[0] : null;

        if (!first) {
            return null;
        }

        if (typeof first === 'object') {
            return first.emailAddress || null;
        }

        return null;
    }
}

export default SelectRecordsUserCreateModalView;
