import SelectRecordsModalView from 'views/modals/select-records';
import RecordModal from 'helpers/record-modal';

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

                this.listenToOnce(view, 'after:render', () => {
                    const recordView = view.getRecordView();

                    if (!recordView) {
                        return;
                    }

                    this.applyUserCreateConstraints(recordView);
                });
            },
        });
    }

    applyUserCreateConstraints(recordView) {
        ['isActive', 'phoneNumber', 'avatar', 'userName', 'type', 'teams', 'defaultTeam'].forEach(
            field => recordView.hideField(field, true)
        );

        recordView.model.set('type', 'regular');

        recordView.setFieldRequired('emailAddress');

        const nameFieldView = recordView.getFieldView('name');

        if (nameFieldView && nameFieldView.$el) {
            const $salutationSelect = nameFieldView.$el.find('[data-name="salutationName"]');

            if ($salutationSelect.length) {
                const $salutationColumn = $salutationSelect.closest('.col-sm-3, .col-xs-3');

                $salutationColumn.addClass('hidden');

                const $firstNameCol = nameFieldView.$el.find('[data-name="firstName"]').closest('.col-sm-4, .col-xs-4');
                const $lastNameCol = nameFieldView.$el.find('[data-name="lastName"]').closest('.col-sm-5, .col-xs-5');

                $firstNameCol.removeClass('col-sm-4 col-xs-4').addClass('col-sm-6 col-xs-6');
                $lastNameCol.removeClass('col-sm-5 col-xs-5').addClass('col-sm-6 col-xs-6');
            }
        }

        const avatarFieldView = recordView.getFieldView('avatar');

        if (avatarFieldView && avatarFieldView.$el) {
            const $colorSubField = avatarFieldView.$el.find('[data-sub-field="color"]');

            $colorSubField.addClass('hidden');
        }

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
