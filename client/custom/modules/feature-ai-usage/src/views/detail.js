import ModalView from 'views/modal';
import {recordName, link, presentMetrics} from 'feature-ai-usage:helpers/index';

export default class extends ModalView {
    template = 'feature-ai-usage:detail';
    className = 'dialog dialog-record au-detail';

    setup() {
        this.headerText = this.translate('activityDetail', 'labels', 'AiUsage');
        this.buttonList = [{name: 'close', label: 'Close'}];
        this.wait(Espo.Ajax.getRequest('AiUsage/' + encodeURIComponent(this.options.id), {
            tenantId: this.options.tenantId, month: this.options.month,
        }).then(data => { this.payload = data; }).catch(error => {
            this.failed = true;
            error?.setHandled?.();
        }));
    }

    data() {
        if (!this.payload) return {failed: this.failed};
        const t = key => this.translate(key, 'labels', 'AiUsage');
        const f = this.options.format;
        const row = this.payload.activity;
        const group = this.payload.billingGroup;
        return {
            failed: false,
            date: f.date(row.runAt, this.options.timeZone, true), kind: t(row.kind || 'unattributed'),
            actions: row.actions.map(t).join(' · ') || '—',
            billingText: t('billing_' + (row.billingStatus || 'unavailable')),
            billingHint: t('billingHint_' + (row.billingStatus || 'unavailable')),
            records: ['agent', 'conversation', 'opportunity', 'chatwootContact', 'chatwootAccount', 'sourceNote']
                .filter(key => row[key]).map(key => ({label: t(key), name: recordName(row[key], t), href: link(row[key])})),
            group: group ? {
                day: f.date(group.day), runs: f.number(group.runs),
                consumed: f.number(group.billing?.consumed), covered: f.number(group.billing?.covered),
                overage: f.number(group.billing?.overage), charges: f.charges(group.billing?.charges),
            } : null,
            metered: row.usageMetricsVersion === 1,
            showTokenUsage: this.getUser().isAdmin(),
            analytics: this.getUser().isAdmin() ? presentMetrics(row.analytics, f, t) : null,
            model: row.model || '—', requests: f.number(row.modelRequestCount),
            input: f.number(row.inputTokens), output: f.number(row.outputTokens), cached: f.number(row.cachedInputTokens),
            duration: row.durationMs !== null ? (row.durationMs / 1000).toFixed(1) + ' s' : '—',
        };
    }
}
