const DIMENSIONS = ['kind', 'action', 'conversation', 'opportunity', 'agent', 'account'];
const BILLING_STATUSES = ['billed', 'partiallyBilled', 'included', 'notBilled', 'excluded', 'unavailable', 'failed'];
export const KINDS = ['customer-message', 'private-mention', 'public-mention', 'scheduled-message', 'followup-trigger', 'opportunity-mention'];

export function allowance(billing) {
    if (billing.status !== 'ready') return {ready: false, state: 'configurationRequired', percentage: null, coveredWidth: 0, overageWidth: 0};
    const total = Math.max(billing.allowance, billing.consumed, 1);
    const percentage = billing.allowance > 0 ? Math.round(100 * billing.consumed / billing.allowance) : null;
    return {
        ready: true, percentage,
        state: billing.allowance === 0 ? 'payAsYouGo' : billing.overage > 0 ? 'overLimit' : percentage >= 80 ? 'nearLimit' : 'withinPlan',
        coveredWidth: 100 * billing.covered / total, overageWidth: 100 * billing.overage / total,
    };
}

export function recordName(record, t) {
    return record ? record.name || t('record') : t('restrictedRecord');
}

export function link(record) {
    return record ? '#' + record.scope + '/view/' + encodeURIComponent(record.id) : null;
}

export function present(payload, state, format, t) {
    if (!payload) return {};
    const b = payload.billing;
    const progress = allowance(b);
    const unit = t('unit_' + (b.model || 'unknown'));
    const period = format.month(payload.period.month);
    const cards = [
        {key: 'consumed', value: b.consumed, caption: unit},
        {key: 'remaining', value: b.remaining, caption: t('ofIncluded').replace('{n}', format.number(b.allowance))},
        {key: 'overage', value: b.overage, caption: format.charges(b.charges) + ' · ' + t('estimated')},
        {key: 'charges', money: true, caption: t('usageChargesOnly')},
    ].map(card => ({
        ...card, title: t(card.key), period,
        value: card.money ? format.charges(b.charges, true) : format.number(card.value, true),
        exact: card.money ? format.charges(b.charges) : format.number(card.value),
        emphasis: card.key === 'overage' && b.overage > 0,
    }));
    const max = Math.max(1, ...(payload.daily || []).map(d => d.consumed ?? d.runs));
    const daily = (payload.daily || []).map(day => {
        const total = day.consumed ?? day.runs;
        return {
            ...day, short: day.day.slice(-2), date: format.date(day.day),
            height: Math.max(total ? 3 : 0, 100 * total / max),
            coveredHeight: total ? 100 * (day.covered ?? total) / total : 0,
            overageHeight: total ? 100 * (day.overage ?? 0) / total : 0,
            consumedText: format.number(day.consumed), coveredText: format.number(day.covered),
            overageText: format.number(day.overage), chargesText: format.charges(day.charges),
            description: `${format.date(day.day)}: ${format.number(total)} ${progress.ready ? unit : t('engagements')}`,
        };
    });
    const comparison = payload.comparison;
    let comparisonText = null;
    if (comparison) {
        const previous = comparison.usage.runs;
        const delta = previous > 0 ? Math.round(100 * (payload.usage.runs - previous) / previous) : null;
        comparisonText = `${t('previousPeriod')}: ${format.number(previous)} ${t('engagements')}` +
            (delta !== null ? ` · ${delta > 0 ? '+' : ''}${delta}%` : '') +
            ` (${format.date(comparison.period.from)} – ${format.date(comparison.period.through)})`;
    }
    const rows = (payload.breakdown?.list || []).map(row => ({
        ...row,
        name: ['kind', 'action'].includes(state.dimension) ? t(row.key || 'unattributed') : row.key ? recordName(row.record, t) : t('unattributed'),
        runsText: format.number(row.runs), daysText: format.number(row.days),
        failedRunsText: format.number(row.failedRuns),
        consumedText: format.number(row.billing?.consumed), chargesText: format.charges(row.billing?.charges),
        coveredText: format.number(row.billing?.covered), overageText: format.number(row.billing?.overage),
        width: Math.min(100, row.share), href: link(row.record),
    }));
    const activities = (payload.activity?.list || []).map(row => {
        const status = BILLING_STATUSES.includes(row.billingStatus) ? row.billingStatus : 'unavailable';
        return {
            ...row, date: format.date(row.runAt, payload.period.timeZone, true), kindText: t(row.kind || 'unattributed'),
            agentText: recordName(row.agent, t), source: row.opportunity || row.conversation,
            sourceText: recordName(row.opportunity || row.conversation, t),
            actionsText: row.actions.map(t).join(' · ') || '—',
            billingText: t('billing_' + status), billingHint: t('billingHint_' + status),
            billingClass: ['billed', 'partiallyBilled'].includes(status) ? 'au-state-overLimit' : status === 'included' ? 'au-state-withinPlan' : '',
        };
    });
    const total = payload.breakdown?.total ?? payload.activity?.total ?? 0;
    return {
        ready: progress.ready, progress,
        showBreakdownBilling: ['conversation', 'opportunity'].includes(state.dimension),
        stateText: t(progress.state), configurationText: t(b.reason || 'missingRate'),
        modelText: t('model_' + (b.model || 'unknown')), unit, period, cards, daily, rows, activities,
        billingModelExplanation: progress.ready ? t('billingExplanation_' + b.model) : null,
        allowanceText: format.number(b.allowance), consumedText: format.number(b.consumed),
        coveredText: format.number(b.covered), remainingText: format.number(b.remaining), overageText: format.number(b.overage),
        percentageText: progress.percentage !== null ? `${progress.percentage}%` : '—',
        resetDate: format.date(payload.period.resetAt.slice(0, 10)), comparisonText,
        generatedAt: format.date(payload.generatedAt, payload.period.timeZone, true),
        timeZone: payload.period.timeZone,
        minDate: payload.period.from, maxDate: payload.period.through,
        runsText: format.number(payload.usage.runs), conversationText: format.number(payload.usage.conversations),
        opportunityText: format.number(payload.usage.opportunities), filteredRunsText: format.number(payload.filteredUsage.runs),
        failedRunsText: format.number(payload.filteredUsage.failedRuns), hasFailedRuns: payload.filteredUsage.failedRuns > 0,
        coverageText: payload.usage.runs ? `${Math.round(100 * payload.usage.meteredRuns / payload.usage.runs)}%` : '—',
        unassigned: payload.usage.unassignedRuns > 0 ? format.number(payload.usage.unassignedRuns) : null,
        rates: payload.rates.map(rate => ({
            ...rate, fromText: format.date(rate.from), toText: rate.to ? format.date(rate.to) : t('ongoing'),
            unitPriceText: rate.unitPrice !== undefined ? format.money(rate.unitPrice, rate.currency) : null,
            basePriceText: rate.basePrice !== undefined ? format.money(rate.basePrice, rate.currency) : null,
            extraPriceText: rate.extraPrice !== undefined ? format.money(rate.extraPrice, rate.currency) : null,
        })),
        chargeLines: (b.charges || []).map(charge => ({
            currency: charge.currency, total: format.money(charge.amount, charge.currency),
            base: b.model === 'extra049' ? format.money(charge.base, charge.currency) : null,
            extras: b.model === 'extra049' ? format.money(charge.extras, charge.currency) : null,
        })),
        dimensions: DIMENSIONS.map(value => ({value, label: t(value), selected: value === state.dimension})),
        kinds: KINDS.map(value => ({value, label: t(value), selected: value === state.filters.kind})),
        overview: state.view === 'overview', breakdown: state.view === 'breakdown', activity: state.view === 'activity',
        previousDisabled: !state.offset, nextDisabled: state.offset + 25 >= total,
        pagination: total ? `${format.number(state.offset + 1)}–${format.number(Math.min(state.offset + 25, total))} / ${format.number(total)}` : '0',
        hasRows: rows.length > 0, hasActivities: activities.length > 0,
    };
}
