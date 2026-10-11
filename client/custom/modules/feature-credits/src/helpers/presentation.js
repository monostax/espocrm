export const reports = {
    operations: {endpoint: 'CreditOperations', columns: ['admittedAt', 'operationType', 'state', 'reservedCredits', 'accruedCreditsExact', 'settledCredits']},
    grants: {endpoint: 'CreditGrants', columns: ['sourceType', 'grantedCredits', 'remainingCredits', 'reservedCredits', 'availableCredits', 'pendingExpirationCredits', 'expiresAt']},
    reservations: {endpoint: 'CreditReservations', columns: ['createdAt', 'operationType', 'state', 'reservedCredits', 'accruedCreditsExact', 'settledCredits']},
    requests: {endpoint: 'CreditRequests', columns: ['authorizedAt', 'outcome', 'meteringState', 'billingState', 'waiverReason', 'authorizedCredits', 'pricedCreditsExact']},
    history: {endpoint: 'CreditHistory', columns: ['postedAt', 'type', 'credits']},
};

const enums = new Set(['operationType', 'state', 'sourceType', 'outcome', 'meteringState', 'billingState', 'waiverReason', 'type']);

export function present(balance, page, tab, t) {
    const columns = reports[tab].columns;
    return {
        cards: balance ? ['availableCredits', 'reservedCredits', 'pendingExpirationCredits', 'balance'].map(key => ({label: t(key), value: balance[key]})) : [],
        walletMissing: balance?.walletExists === false,
        exhausted: balance?.walletExists === true && (balance.availableCredits === '0.0000' || balance.availableCredits?.startsWith('-')),
        observedAt: balance?.observedAt,
        pageObservedAt: page?.observedAt,
        headers: columns.map(key => t(key)),
        rows: (page?.list || []).map(row => ({
            id: row.id,
            sourceId: tab === 'operations' ? row.id : (['reservations', 'requests'].includes(tab) ? row.usageId : null),
            cells: columns.map(key => {
                const value = row[key];
                if (value === null || value === undefined) {
                    return {value: t(['settledCredits', 'pricedCreditsExact'].includes(key) ? 'notFinal' : key === 'expiresAt' ? 'neverExpires' : 'unavailable')};
                }
                // Decimal strings remain exact, including sub-quantum accrued prices. Never use Number.
                return {value: enums.has(key) ? t(value) : value};
            }),
        })),
        showSource: ['operations', 'reservations', 'requests'].includes(tab),
    };
}

export function sourceRoute(payload, tenantId, usageId) {
    const source = payload?.source;
    if (payload?.tenantId !== tenantId || payload?.id !== usageId || !source ||
        !['ChatwootAiAgentRun', 'Opportunity'].includes(source.scope) ||
        typeof source.id !== 'string' || !/^[a-zA-Z0-9_-]{1,128}$/.test(source.id)) return null;
    return source.scope + '/view/' + encodeURIComponent(source.id);
}
