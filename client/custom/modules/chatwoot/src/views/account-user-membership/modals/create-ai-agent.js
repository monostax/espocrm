define(['views/modal', 'model'], (ModalView, Model) => {
    return class extends ModalView {
        className = 'dialog dialog-record';
        templateContent = `
            <p class="text-muted">{{translate 'createAiAgentHelp' category='messages' scope='ChatwootAccountUserMembership'}}</p>
            <div class="record no-side-margin">{{{record}}}</div>
            <div class="alert alert-danger hidden" role="alert" data-name="error"></div>
        `;

        setup() {
            super.setup();
            this.headerText = this.translate('Create AI Agent', 'labels', 'ChatwootAccountUserMembership');
            this.buttonList = [
                {name: 'create', text: this.headerText, style: 'primary'},
                {name: 'cancel', label: 'Cancel'},
            ];
            this.shortcutKeys = {'Control+Enter': () => this.actionCreate()};
            // One operation per dialog. Keep it (and the submitted values) on a failed request.
            this.operationId = crypto.randomUUID();
            this.model = new Model({}, {entityType: 'ChatwootAccountUserMembership'});
            this.model.setDefs({fields: {
                chatwootAccount: {type: 'link', entity: 'ChatwootAccount', required: true},
                name: {type: 'varchar', required: true, maxLength: 100},
                instructions: {type: 'text', rows: 4, maxLength: 10000},
            }});
            this.model.set(this.options.attributes || {});
            this.createView('record', 'views/record/edit-for-modal', {
                model: this.model,
                selector: '.record',
                detailLayout: [{rows: [
                    [{name: 'chatwootAccount', readOnly: !!this.options.attributes?.chatwootAccountId}, false],
                    [{name: 'name'}, false],
                    [{name: 'instructions'}],
                ]}],
            });
        }

        async actionCreate() {
            if (this.saving) return;
            const record = this.getView('record');
            this.model.set(record.fetch());
            this.model.set('name', (this.model.get('name') || '').trim());
            if (record.validate()) return;

            this.payload ||= {
                id: this.model.get('chatwootAccountId'),
                operationId: this.operationId,
                name: this.model.get('name'),
                instructions: this.model.get('instructions') || '',
            };
            this.saving = true;
            this.disableButton('create');
            this.disableButton('cancel');
            this.$el.find('[data-name="error"]').addClass('hidden');
            record.setReadOnly();
            Espo.Ui.notifyWait();

            try {
                const result = await Espo.Ajax.postRequest('ChatwootAccount/action/createAiAgent', this.payload);
                Espo.Ui.success(this.translate('aiAgentCreated', 'messages', this.model.name));
                this.trigger('created', result);
                this.close();
                this.getRouter().navigate(`#ChatwootAccountUserMembership/view/${result.id}`, {trigger: true});
            } catch (e) {
                e.errorIsHandled = true;
                const key = e.responseJSON?.message || e.message;
                const knownKeys = ['aiAgentAccountNotReady', 'aiAgentTenantRequired', 'aiAgentOperationConflict'];
                this.$el.find('[data-name="error"]').text(this.translate(
                    knownKeys.includes(key) ? key : 'aiAgentCreateFailed', 'messages', this.model.name
                )).removeClass('hidden');
                // A timeout can mean the remote identity was created. Retry exactly the same operation.
                this.enableButton('create');
                this.enableButton('cancel');
            } finally {
                this.saving = false;
                Espo.Ui.notify(false);
            }
        }
    };
});
