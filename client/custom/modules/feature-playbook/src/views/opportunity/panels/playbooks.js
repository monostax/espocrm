define('feature-playbook:views/opportunity/panels/playbooks', ['views/record/panels/side'], function (Dep) {
    return Dep.extend({
        template: 'feature-playbook:opportunity/panels/playbooks',

        events: {
            'submit form[data-playbook-form]': function (event) {
                event.preventDefault();
                this.submitForm(event.currentTarget);
            },
            'change input[data-step-id]': function (event) {
                const input = event.currentTarget;
                this.mutate(input.closest('[data-run-id]').dataset.runId, {
                    action: 'step', stepId: input.dataset.stepId,
                    status: input.checked ? 'Completed' : 'Pending',
                });
            },
            'click button[data-playbook-action]': function (event) {
                const button = event.currentTarget;
                const action = button.dataset.playbookAction;
                if (action === 'refresh') return this.load();
                const runId = button.closest('[data-run-id]').dataset.runId;
                const body = { action };
                if (button.dataset.stepId) body.stepId = button.dataset.stepId;
                if (button.dataset.status) body.status = button.dataset.status;
                this.mutate(runId, body);
            },
        },

        setup: function () {
            Dep.prototype.setup.call(this);
            this.snapshot = { runs: [], templates: [] };
            this.url = `Opportunity/${encodeURIComponent(this.model.id)}/playbooks`;
            this.alive = true;
            this.listenTo(this.model, 'sync', () => this.load());
            this.on('remove', () => { this.alive = false; });
            this.wait(this.load(false));
        },

        data: function () {
            const canEdit = this.snapshot.canEdit && !this.busy;
            return {
                ...this.snapshot,
                canEdit,
                error: this.error,
                manageUrl: `#PlaybookManager/index/view=templates&opportunityId=${encodeURIComponent(this.model.id)}`,
                templates: this.snapshot.templates.filter(item => item.status === 'Published'),
                runs: this.snapshot.runs.map(run => {
                    const editable = canEdit && ['Active', 'Completed'].includes(run.status);
                    return {
                        ...run, editable, resumable: canEdit && !editable,
                        canAdd: editable && !run.playbookId,
                        statusLabel: this.translate(run.status, 'labels', 'Playbook'),
                        steps: run.steps.map(step => ({
                            ...step,
                            checked: step.status === 'Completed',
                            disabled: !editable || (step.kind === 'Task' && !step.canEditTask),
                            canActivate: editable && step.kind === 'Task' && !step.hasTask && step.status === 'Pending',
                            canSkip: editable && !['Completed', 'Skipped'].includes(step.status) && (!step.hasTask || step.status === 'Cancelled'),
                            canReopen: editable && ['Skipped', 'Cancelled'].includes(step.status) && (!step.hasTask || step.canEditTask),
                            statusLabel: this.translate(step.status, 'labels', 'Playbook'),
                        })),
                    };
                }),
            };
        },

        load: async function (render = true) {
            try {
                const snapshot = await Espo.Ajax.getRequest(this.url);
                if (!this.alive) return;
                this.snapshot = snapshot;
                this.error = false;
            } catch (error) {
                this.error = true;
            }
            if (render && this.alive) await this.reRender();
        },

        send: async function (url, body) {
            if (this.busy) return;
            this.busy = true;
            this.$el.find('button, input, select, textarea').prop('disabled', true);
            try {
                await Espo.Ajax.postRequest(url, body);
                this.application = null;
                await this.load(false);
            } catch (error) {
                this.error = true;
                Espo.Ui.error(this.translate('error', 'labels', 'Playbook'));
            } finally {
                this.busy = false;
                if (this.alive) this.reRender();
            }
        },

        mutate: function (runId, body) {
            return this.send(`${this.url}/${encodeURIComponent(runId)}`, body);
        },

        submitForm: function (form) {
            const fields = new FormData(form);
            const action = form.dataset.playbookForm;
            if (action === 'apply') {
                const templateId = fields.get('templateId');
                const body = templateId ? { templateId } : { name: fields.get('name'), steps: [] };
                const signature = JSON.stringify(body);
                if (this.application?.signature !== signature) {
                    this.application = { signature, requestKey: crypto.randomUUID() };
                }
                return this.send(this.url, { ...body, requestKey: this.application.requestKey });
            }
            const runId = form.closest('[data-run-id]').dataset.runId;
            if (action === 'addStep') {
                return this.mutate(runId, { action, step: {
                    name: fields.get('name'), kind: fields.get('kind'), instructions: fields.get('instructions'),
                    references: String(fields.get('references')).split('\n').map(value => value.trim()).filter(Boolean),
                } });
            }
            return this.mutate(runId, { action: fields.get('action'), reason: fields.get('reason') });
        },
    });
});
