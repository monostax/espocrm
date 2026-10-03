define('feature-task-recurrence:views/modals/series', ['views/modal'], function (Modal) {
    return Modal.extend({
        templateContent: `
            <p>{{summary}}</p><p>{{state}}</p>{{#if error}}<p class="text-danger">{{error}}</p>{{/if}}
            <label>{{translate 'action' scope='TaskRecurrence'}}</label><select class="form-control" data-name="action">{{#each actions}}<option value="{{value}}">{{label}}</option>{{/each}}</select>
            <label>{{translate 'scope' scope='TaskRecurrence'}}</label><select class="form-control" data-name="scope">{{#each scopes}}<option value="{{value}}">{{label}}</option>{{/each}}</select>
            <p data-impact aria-live="polite"></p><div data-error role="alert" class="text-danger"></div>
        `,
        setup: function () {
            Modal.prototype.setup.call(this);
            this.headerText = this.translate('manageRecurrence', 'labels', 'TaskRecurrence');
            this.buttonList = [{name: 'save', label: 'Apply', style: 'primary'}, {name: 'cancel', label: 'Cancel'}];
            this.recurrence = this.model.get('recurrence');
            this.url = `Task/${encodeURIComponent(this.model.id)}/recurrence/actions`;
            this.sequence = 0;
            this.on('remove', () => this.sequence++);
        },
        data: function () {
            const actions = this.recurrence.canEdit ? [this.recurrence.state === 'Paused' ? 'resume' : 'pause', 'skip', 'end'] : [];
            if (this.recurrence.canDelete) actions.push('delete');
            return {state: this.translate(this.recurrence.state, 'labels', 'TaskRecurrence'), error: this.recurrence.lastError,
                summary: this.translate(this.recurrence.definition.basis, 'labels', 'TaskRecurrence'),
                actions: actions.map(value => ({value, label: this.translate(value, 'labels', 'TaskRecurrence')})),
                scopes: ['ThisOccurrence', 'ThisAndFollowing', 'WholeSeries'].map(value => ({value, label: this.translate(value, 'labels', 'TaskRecurrence')}))};
        },
        afterRender: function () {
            Modal.prototype.afterRender.call(this);
            this.el.querySelectorAll('select').forEach(element => element.addEventListener('change', () => this.preview()));
            this.preview();
        },
        body: function () {
            const action = this.el.querySelector('[data-name="action"]').value;
            const scopeInput = this.el.querySelector('[data-name="scope"]');
            if (['pause', 'resume'].includes(action) || (action === 'end' && scopeInput.value === 'ThisOccurrence')) scopeInput.value = 'WholeSeries';
            if (action === 'skip') scopeInput.value = 'ThisOccurrence';
            scopeInput.disabled = ['pause', 'resume', 'skip'].includes(action);
            return {action, scope: scopeInput.value, version: this.recurrence.version};
        },
        preview: async function () {
            const sequence = ++this.sequence;
            try {
                const result = await Espo.Ajax.postRequest(this.url, {...this.body(), preview: true});
                if (sequence !== this.sequence) return;
                this.el.querySelector('[data-impact]').textContent = `${result.affectedCount} ${this.translate('affected', 'labels', 'TaskRecurrence')} · ${result.retainedExceptions} ${this.translate('exceptions', 'labels', 'TaskRecurrence')}`;
            } catch (error) { this.el.querySelector('[data-error]').textContent = this.translate('invalid', 'labels', 'TaskRecurrence'); }
        },
        actionSave: async function () {
            this.disableButton('save');
            try { await Espo.Ajax.postRequest(this.url, this.body()); this.trigger('saved'); this.close(); }
            catch (error) { this.el.querySelector('[data-error]').textContent = error.responseJSON?.message || error.getResponseHeader?.('X-Status-Reason') || this.translate('invalid', 'labels', 'TaskRecurrence'); }
            finally { this.enableButton('save'); }
        },
    });
});
