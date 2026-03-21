import UserFieldView from 'views/fields/user';
import RecordModal from 'helpers/record-modal';

class UserWithCreateFieldView extends UserFieldView {
    selectRecordsView = 'chatwoot:views/modals/select-records-user-create'

    createButton = true

    getCreateAttributes() {
        const attributes = super.getCreateAttributes() || {};

        const teamsIds = this.params.accountTeamsIds || [];
        const teamsNames = this.params.accountTeamsNames || {};

        if (!Array.isArray(teamsIds) || !teamsIds.length) {
            return attributes;
        }

        const firstTeamId = teamsIds[0] || null;

        return {
            ...attributes,
            type: 'regular',
            teamsIds: teamsIds,
            teamsNames: teamsNames,
            defaultTeamId: firstTeamId,
            defaultTeamName: firstTeamId ? teamsNames[firstTeamId] || null : null,
        };
    }

    async actionCreateLink() {
        const helper = new RecordModal();

        const attributes = await this.getCreateAttributesProvider()();

        await helper.showCreate(this, {
            entityType: this.foreignScope,
            fullFormDisabled: true,
            attributes: attributes,
            afterSave: model => this.select(model),
            beforeRender: view => {
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

export default UserWithCreateFieldView;
