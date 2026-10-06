import VarcharFieldView from 'views/fields/varchar';

export default class extends VarcharFieldView {
    setup() {
        super.setup();
        this.listenTo(this.model, 'sync', () => {
            if (this.isRendered()) this.refreshBudget();
        });
    }

    afterRender() {
        super.afterRender();
        this.refreshBudget();
    }

    async refreshBudget() {
        if (!this.model.id) return;
        const label = key => this.translate(key, 'labels', 'TenantAiBillingRate');
        try {
            const status = await Espo.Ajax.getRequest(`TenantAiBillingRate/${this.model.id}/budgetStatus`);
            if (!status.period) throw new Error('Budget configuration unavailable');
            const lines = [
                `${status.period}: ${label('budgetConsumed')} ${status.consumed} / ${status.included}`,
                `${label('budgetReserved')}: ${status.reserved} · ${label('budgetRemaining')}: ${status.remaining}`,
                label(status.policy === 'block' ? 'budgetBlock' : 'budgetAllow'),
                `${label('budgetResets')}: ${new Date(status.resetsAt).toLocaleString()}`,
            ];
            if (!status.allowed) lines.unshift(label('budgetPaused'));
            this.$el.css('white-space', 'pre-line').text(lines.join('\n'));
        } catch {
            this.$el.text(label('budgetUnavailable'));
        }
    }
}
