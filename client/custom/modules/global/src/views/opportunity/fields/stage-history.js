define('global:views/opportunity/fields/stage-history', ['views/fields/base', 'global:helpers/stage-time'], function (Base, Time) {
    return Base.extend({
        detailTemplate: 'global:opportunity/fields/stage-history',
        offset: 0,

        setup: function () {
            Base.prototype.setup.call(this);
            this.history = {list: [], summary: [], total: 0};
            this.requestVersion = 0;
            this.listenTo(this.model, 'sync', () => { this.offset = 0; this.loadHistory(); });
            this.wait(this.loadHistory());
            const interval = setInterval(() => {
                if (this.isRendered() && this.model.get('currentStageVisitId')) this.loadHistory();
            }, 60000);
            this.on('remove', () => { clearInterval(interval); this.requestVersion++; });
        },

        loadHistory: function () {
            if (!this.model.id) return Promise.resolve();
            const version = ++this.requestVersion;
            return Espo.Ajax.getRequest('Opportunity/action/stageHistory', {id: this.model.id, offset: this.offset})
                .then(data => {
                    if (version !== this.requestVersion) return;
                    this.history = data;
                    this.failed = false;
                    if (this.isRendered()) this.reRender();
                }).catch(() => {
                    if (version !== this.requestVersion) return;
                    this.history = {list: [], summary: [], total: 0};
                    this.failed = true;
                    if (this.isRendered()) this.reRender();
                });
        },

        data: function () {
            const label = text => this.translate(text, 'labels', 'Opportunity');
            const displayDate = value => value ? this.getDateTime().toDisplay(value) : '—';
            return {
                ...Base.prototype.data.call(this),
                failed: this.failed,
                partial: this.history.isPartial,
                trackingStartedAt: displayDate(this.history.trackingStartedAt),
                hasRows: this.history.list.length > 0,
                hasPrevious: this.offset > 0,
                hasNext: this.offset + 50 < this.history.total,
                rows: this.history.list.map(row => ({
                    ...row,
                    entered: displayDate(row.enteredAt),
                    exited: row.active ? label('Ongoing') : displayDate(row.exitedAt),
                    elapsed: row.kind === 'Visit' ? Time.format(row.elapsedSeconds) : '—',
                    target: row.targetTimeSeconds == null ? '—' : Time.format(row.targetTimeSeconds),
                    result: row.kind !== 'Visit' ? this.getLanguage().translateOption(row.kind, 'status', 'Opportunity') :
                        row.overdueSeconds > 0 ? `${label('Overdue by')} ${Time.format(row.overdueSeconds)}` :
                        row.targetTimeSeconds == null ? '—' : row.active ?
                            `${label('Remaining')} ${Time.format(row.targetTimeSeconds - row.elapsedSeconds)}` :
                            label(row.isPartial ? 'Observed within target' : 'Within target'),
                    overdue: row.overdueSeconds > 0,
                })),
                summary: this.history.summary.map(row => ({...row, elapsed: Time.format(row.elapsedSeconds)})),
            };
        },

        afterRender: function () {
            Base.prototype.afterRender.call(this);
            this.$el.find('[data-role="previous"]').on('click', () => {
                this.offset = Math.max(0, this.offset - 50); this.loadHistory();
            });
            this.$el.find('[data-role="next"]').on('click', () => {
                this.offset += 50; this.loadHistory();
            });
            this.$el.find('[data-role="refresh"]').on('click', () => this.loadHistory());
        },

        fetch: function () { return {}; },
    });
});
