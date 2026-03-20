import UserFieldView from 'views/fields/user';

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
            teamsIds: teamsIds,
            teamsNames: teamsNames,
            defaultTeamId: firstTeamId,
            defaultTeamName: firstTeamId ? teamsNames[firstTeamId] || null : null,
        };
    }
}

export default UserWithCreateFieldView;
