export const reports = {
    operations: {endpoint: 'CreditOperations', columns: ['admittedAt', 'operationType', 'state', 'reservedCredits', 'accruedCreditsExact', 'settledCredits']},
    grants: {endpoint: 'CreditGrants', columns: ['sourceType', 'grantedCredits', 'remainingCredits', 'reservedCredits', 'availableCredits', 'pendingExpirationCredits', 'expiresAt']},
    reservations: {endpoint: 'CreditReservations', columns: ['createdAt', 'operationType', 'state', 'reservedCredits', 'accruedCreditsExact', 'settledCredits']},
    requests: {endpoint: 'CreditRequests', columns: ['authorizedAt', 'outcome', 'meteringState', 'billingState', 'waiverReason', 'authorizedCredits', 'pricedCreditsExact']},
    history: {endpoint: 'CreditHistory', columns: ['postedAt', 'type', 'credits']},
};

const enums = new Set(['operationType', 'state', 'sourceType', 'outcome', 'meteringState', 'billingState', 'waiverReason', 'type']);
const creditFields = new Set(['availableCredits', 'reservedCredits', 'pendingExpirationCredits', 'balance',
    'accruedCreditsExact', 'settledCredits', 'grantedCredits', 'remainingCredits', 'authorizedCredits', 'pricedCreditsExact', 'credits']);

// Display only: keep API/model decimal strings untouched for accounting and exports.
export function formatCredits(value) {
    if (typeof value !== 'string') return value;
    const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(value);
    if (!match) return value;
    const [, sign, whole, fraction = ''] = match;
    const decimals = fraction.padEnd(3, '0');
    let cents = BigInt(whole + decimals.slice(0, 2));
    if (decimals[2] >= '5') cents += BigInt(1);
    const negative = sign ? '−' : '';
    if (cents === BigInt(0)) {
        return /[1-9]/.test(whole + fraction) ? negative + '<0.01' : '0.00';
    }
    const digits = cents.toString().padStart(3, '0');
    return negative + digits.slice(0, -2) + '.' + digits.slice(-2);
}

export function present(balance, page, tab, t) {
    const columns = reports[tab].columns;
    return {
        cards: balance ? ['availableCredits', 'reservedCredits', 'pendingExpirationCredits', 'balance'].map(key => ({label: t(key), value: formatCredits(balance[key])})) : [],
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
                return {value: enums.has(key) ? t(value) : creditFields.has(key) ? formatCredits(value) : value};
            }),
        })),
        showSource: ['operations', 'reservations', 'requests'].includes(tab),
    };
}

export function sourceRoute(payload, tenantId, usageId) {
    const source = payload?.source;
    if (payload?.tenantId !== tenantId || payload?.id !== usageId || !source ||
        !['ChatwootAiAgentRun', 'Opportunity', 'Contact', 'ChatwootConversation', 'Note', 'ChatwootAccountUserMembership'].includes(source.scope) ||
        typeof source.id !== 'string' || !/^[a-zA-Z0-9_-]{1,128}$/.test(source.id)) return null;
    return source.scope + '/view/' + encodeURIComponent(source.id);
}
