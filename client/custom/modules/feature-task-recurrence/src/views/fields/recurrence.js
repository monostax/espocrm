define('feature-task-recurrence:views/fields/recurrence', ['views/fields/base'], function (Base) {
    return Base.extend({
        type: 'jsonObject',
        inlineEditDisabled: true,
        events: {
            'click [data-action="editRecurrence"]': function (event) { event.preventDefault(); event.stopPropagation(); this.actionEditRecurrence(); },
            'click [data-action="manageRecurrence"]': function (event) { event.preventDefault(); event.stopPropagation(); this.actionManageRecurrence(); },
        },
        detailTemplateContent: `
            <span class="fas fa-repeat text-muted" aria-hidden="true"></span> {{summary}}
            {{#if state}}<span class="label label-default">{{state}}</span>{{/if}}
            {{#if canEdit}}<button type="button" class="btn btn-link btn-sm" data-action="editRecurrence">{{translate 'editRecurrence' scope='TaskRecurrence'}}</button>{{/if}}
            {{#if series}}<button type="button" class="btn btn-link btn-sm" data-action="manageRecurrence">{{translate 'manageRecurrence' scope='TaskRecurrence'}}</button>{{/if}}
        `,
        editTemplateContent: `<button type="button" class="btn btn-default btn-sm" data-action="editRecurrence"><span class="fas fa-repeat" aria-hidden="true"></span> {{summary}}</button>`,
        listTemplateContent: `{{#if series}}<span class="fas fa-repeat" title="{{translate 'repeat' scope='TaskRecurrence'}}"></span>{{/if}}`,

        setup: function () {
            Base.prototype.setup.call(this);
            this.submissionKey = crypto.randomUUID();
        },

        data: function () {
            const recurrence = this.model.get(this.name);
            const definition = this.draft || recurrence?.definition;
            let summary = this.translate(definition ? 'repeat' : 'none', 'labels', 'TaskRecurrence');
            if (definition?.basis === 'CompletedDate') {
                summary = `${definition.interval.value} ${this.translate(definition.interval.unit, 'labels', 'TaskRecurrence')} · ${this.translate('CompletedDate', 'labels', 'TaskRecurrence')}`;
            } else if (definition?.schedule) {
                const frequency = definition.schedule.match(/FREQ=([A-Z]+)/)?.[1] || 'specific';
                summary = this.translate(frequency, 'labels', 'TaskRecurrence');
            }
            return {...Base.prototype.data.call(this), summary,
                series: !!this.model.get('recurrenceSeriesId'),
                state: recurrence?.state ? this.translate(recurrence.state, 'labels', 'TaskRecurrence') : '',
                canEdit: this.getAcl().checkModel(this.model, 'edit') && this.getAcl().checkField('Task', 'recurrence', 'edit')};
        },

        actionEditRecurrence: async function () {
            const view = await this.createView('editor', 'feature-task-recurrence:views/modals/editor', {
                model: this.model, definition: this.draft || this.model.get(this.name)?.definition,
                recurrence: this.model.get(this.name),
            });
            this.listenToOnce(view, 'saved', definition => {
                if (!this.model.id) { this.draft = definition; this.trigger('change'); this.reRender(); }
                else this.model.fetch().then(() => this.reRender());
            });
            view.render();
        },

        actionManageRecurrence: async function () {
            const view = await this.createView('manager', 'feature-task-recurrence:views/modals/series', {model: this.model});
            this.listenToOnce(view, 'saved', () => this.model.fetch().then(() => this.reRender()));
            view.render();
        },

        fetch: function () {
            if (this.model.id || !this.draft) return {};
            return {[this.name]: {definition: this.draft, idempotencyKey: this.submissionKey}};
        },
    });
});
