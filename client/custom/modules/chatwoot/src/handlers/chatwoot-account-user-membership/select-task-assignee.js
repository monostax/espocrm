import SelectRelatedHandler from 'handlers/select-related';

/** Applies to both autocomplete and the picker. Runtime revalidates the roster. */
export default class extends SelectRelatedHandler {
    async getFilters(model) {
        const empty = { advanced: { id: { attribute: 'id', type: 'in', value: [] } } };
        const accountId = model.get('chatwootAccountId');
        if (!accountId) return empty;

        try {
            const account = await Espo.Ajax.getRequest('ChatwootAccount/' + accountId);
            if (!account.tenantId || !account.teamsIds?.length) return empty;

            return {
                advanced: {
                    isActive: { attribute: 'isActive', type: 'isTrue' },
                    type: { attribute: 'type', type: 'in', value: ['regular', 'admin'] },
                    tenants: { attribute: 'tenants', type: 'linkedWith', value: [account.tenantId] },
                    teams: { attribute: 'teams', type: 'linkedWith', value: account.teamsIds },
                },
            };
        } catch (e) {
            return empty;
        }
    }
}
