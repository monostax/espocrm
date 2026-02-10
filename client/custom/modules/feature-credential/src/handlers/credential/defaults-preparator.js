import DefaultsPreparator from 'handlers/model/defaults-preparator';
import { inject } from 'di';
import User from 'models/user';

/**
 * Defaults preparator for Credential.
 * Prefills teams with the logged-in user's default team.
 */
export default class extends DefaultsPreparator {
    /**
     * @private
     * @type {User}
     */
    @inject(User)
    user;

    /**
     * @param {import('model').default} model
     * @return {Promise<Object.<string, *>>}
     */
    async prepare(model) {
        const defaultTeamId = this.user.get('defaultTeamId');

        if (!defaultTeamId) {
            return {};
        }

        return {
            teamsIds: [defaultTeamId],
            teamsNames: { [defaultTeamId]: this.user.get('defaultTeamName') || '' },
        };
    }
}
