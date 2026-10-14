define('feature-meta-whatsapp-business:views/template-editor', [
    'views/modal', 'feature-meta-whatsapp-business:template-definition',
], function (Dep, Definition) {
    const scope = 'WhatsAppBusinessAccountMessageTemplate';
    return Dep.extend({
        className: 'dialog dialog-record',
        events: {
            'click [data-action="addButton"]': function () { this.actionAddButton(); },
            'click [data-action="removeButton"]': function (event) { this.actionRemoveButton(event.currentTarget.dataset); },
        },
        templateContent: `
            <p class="text-warning">{{warning}}</p>
            <p>{{context.businessAccountId}} / {{record.name}} / {{record.language}} / {{record.status}}</p>
            <a href="{{managerUrl}}" target="_blank" rel="noopener noreferrer">{{t.metaManager}}</a>
            {{#if error}}<p class="text-danger" role="alert">{{error}}</p>{{/if}}
            {{#if inspect}}<pre>{{inspection}}</pre>{{else}}
            <fieldset {{#if busy}}disabled{{/if}}>
                <div class="form-group"><label>{{t.name}}<input class="form-control" data-field="name" value="{{form.name}}" maxlength="512" {{#if editing}}readonly{{/if}}></label></div>
                <div class="form-group"><label>{{t.language}}<select class="form-control" data-field="language" {{#if editing}}disabled{{/if}}>{{#each languages}}<option value="{{this}}">{{this}}</option>{{/each}}</select></label></div>
                <div class="form-group"><label>{{t.category}}<select class="form-control" data-field="category" {{#if approved}}disabled{{/if}}><option>MARKETING</option><option>UTILITY</option></select></label></div>
                <div class="form-group"><label>{{t.header}}<select class="form-control" data-field="headerFormat"><option value="">{{t.none}}</option><option>TEXT</option><option>IMAGE</option><option>VIDEO</option><option>DOCUMENT</option></select></label></div>
                {{#if textHeader}}<div class="form-group"><label>{{t.header}}<input class="form-control" data-field="header" value="{{form.header}}" maxlength="60"></label></div>{{/if}}
                {{#if mediaHeader}}<div class="form-group"><label>{{t.sample}}<input type="file" class="form-control" data-sample accept="{{accept}}"></label><p class="help-block">{{t.sampleHint}} {{sampleName}}</p></div>{{/if}}
                <div class="form-group"><label>{{t.body}}<textarea class="form-control" data-field="body" maxlength="1024" rows="6">{{form.body}}</textarea></label></div>
                <p class="help-block">{{t.variablesHint}}</p>
                <div class="template-examples"></div>
                <div class="form-group"><label>{{t.footer}}<input class="form-control" data-field="footer" value="{{form.footer}}" maxlength="60"></label></div>
                <h4>{{t.buttons}}</h4>
                {{#each form.buttons}}<div class="form-group template-button" data-index="{{@index}}">
                    <select class="form-control" data-button="type"><option>QUICK_REPLY</option><option>URL</option><option>PHONE_NUMBER</option></select>
                    <label>{{../t.buttonLabel}}<input class="form-control" data-button="text" value="{{text}}" maxlength="25"></label>
                    <label>{{../t.buttonValue}}<input class="form-control" data-button="value" value="{{value}}" maxlength="2000"></label>
                    <button class="btn btn-link" data-action="removeButton" data-index="{{@index}}">{{../t.remove}}</button>
                </div>{{/each}}
                <button class="btn btn-default" data-action="addButton">{{t.addButton}}</button>
            </fieldset>
            <h4>{{t.preview}}</h4><pre class="template-preview"></pre>
            {{/if}}
        `,
        t: function (key, category = 'labels') { return this.translate(key, category, scope); },
        setup: function () {
            Dep.prototype.setup.call(this);
            this.record = this.options.templateRecord;
            this.inspect = this.options.inspect || (this.record && !this.record.canEdit);
            this.context = this.options.context;
            this.headerText = this.t(this.inspect ? 'inspect' : this.record ? 'edit' : 'create');
            this.buttonList = this.inspect ? [{name: 'cancel', label: 'Close'}] : [
                {name: 'submit', label: this.t('submit'), style: 'primary'}, {name: 'cancel', label: 'Cancel'},
            ];
            const components = this.record ? JSON.parse(this.record.components || '[]') : [];
            const body = components.find(c => c.type === 'BODY') || {};
            const header = components.find(c => c.type === 'HEADER') || {};
            this.form = {
                name: this.record?.name || '', language: this.record?.language || 'pt_BR',
                category: this.record?.category || 'UTILITY', body: body.text || '',
                headerFormat: header.format || '', header: header.text || '',
                footer: components.find(c => c.type === 'FOOTER')?.text || '',
                buttons: (components.find(c => c.type === 'BUTTONS')?.buttons || []).map(b => ({
                    type: b.type, text: b.text, value: b.url || b.phone_number || '',
                })),
            };
            this.examples = {BODY: body.example?.body_text?.[0] || [], HEADER: header.example?.header_text || []};
            this.sample = null;
        },
        data: function () {
            return {
                form: this.form, record: this.record, context: this.context, inspect: this.inspect,
                editing: !!this.record, approved: this.record?.status === 'APPROVED', busy: this.busy,
                error: this.error, warning: this.t('editWarning', 'messages'),
                inspection: JSON.stringify(this.record, null, 2), languages: Definition.languages,
                textHeader: this.form.headerFormat === 'TEXT',
                mediaHeader: ['IMAGE', 'VIDEO', 'DOCUMENT'].includes(this.form.headerFormat),
                accept: {IMAGE: 'image/jpeg,image/png', VIDEO: 'video/mp4', DOCUMENT: 'application/pdf'}[this.form.headerFormat],
                sampleName: this.sample?.name || '',
                managerUrl: 'https://business.facebook.com/wa/manage/message-templates/?waba_id=' + encodeURIComponent(this.context.businessAccountId),
                t: Object.fromEntries(['name', 'language', 'category', 'header', 'none', 'sample', 'sampleHint', 'body',
                    'variablesHint', 'footer', 'buttons', 'buttonLabel', 'buttonValue', 'remove', 'addButton', 'preview',
                    'metaManager'].map(key => [key, this.t(key)])),
            };
        },
        afterRender: function () {
            Dep.prototype.afterRender.call(this);
            if (this.inspect) return;
            for (const key of ['language', 'category', 'headerFormat']) this.$el.find('[data-field="' + key + '"]').val(this.form[key]);
            this.$el.find('.template-button').each((i, row) => $(row).find('[data-button="type"]').val(this.form.buttons[i].type));
            this.$el.find('[data-field], [data-button]').on('input change', event => {
                this.capture();
                if (event.target.dataset.field === 'headerFormat') {
                    this.sample = null;
                    this.reRender();
                } else {
                    this.renderExamples();
                    this.renderPreview();
                }
            });
            this.$el.find('[data-sample]').on('change', event => { this.sample = event.target.files[0] || null; });
            this.renderExamples();
            this.renderPreview();
        },
        capture: function () {
            this.$el.find('[data-field]').each((i, el) => { this.form[el.dataset.field] = el.value; });
            this.$el.find('.template-button').each((i, row) => {
                $(row).find('[data-button]').each((j, el) => { this.form.buttons[i][el.dataset.button] = el.value; });
            });
        },
        renderExamples: function () {
            const container = this.$el.find('.template-examples');
            const fields = {BODY: this.form.body, HEADER: this.form.headerFormat === 'TEXT' ? this.form.header : ''};
            const signature = JSON.stringify(fields);
            if (signature === this.exampleSignature && container.children().length) return;
            this.exampleSignature = signature;
            container.empty();
            for (const [type, value] of Object.entries(fields)) {
                let numbers;
                try { numbers = Definition.variables(value); } catch (e) { continue; }
                numbers.forEach(number => {
                    const label = $('<label>').text(this.t('example') + ' ' + type + ' {{' + number + '}}');
                    const input = $('<input>').addClass('form-control').attr('maxlength', type === 'BODY' ? 1024 : 60)
                        .val(this.examples[type][number - 1] || '')
                        .on('input', event => {
                            this.examples[type][number - 1] = event.target.value;
                            this.renderPreview();
                        });
                    container.append($('<div>').addClass('form-group').append(label.append(input)));
                });
            }
        },
        renderPreview: function () {
            const replace = (text, type) => text.replace(/\{\{(\d+)\}\}/g, (match, n) => this.examples[type][n - 1] || match);
            const header = this.form.headerFormat === 'TEXT' ? replace(this.form.header, 'HEADER') : this.form.headerFormat;
            this.$el.find('.template-preview').text([header, replace(this.form.body, 'BODY'), this.form.footer,
                ...this.form.buttons.map(b => '[' + b.text + '] ' + b.value)].filter(Boolean).join('\n\n'));
        },
        actionAddButton: function () {
            if (this.busy || this.form.buttons.length >= 10) return;
            this.capture();
            this.form.buttons.push({type: 'QUICK_REPLY', text: '', value: ''});
            this.reRender();
        },
        actionRemoveButton: function (data) {
            if (this.busy) return;
            this.capture();
            this.form.buttons.splice(Number(data.index), 1);
            this.reRender();
        },
        definition: function () {
            const f = this.form;
            const components = [];
            if (f.headerFormat) {
                const header = {type: 'HEADER', format: f.headerFormat};
                if (f.headerFormat === 'TEXT') {
                    header.text = f.header;
                    const count = Definition.variables(f.header).length;
                    if (count) header.example = {header_text: this.examples.HEADER.slice(0, count)};
                }
                components.push(header);
            }
            const body = {type: 'BODY', text: f.body};
            const count = Definition.variables(f.body).length;
            if (count) body.example = {body_text: [this.examples.BODY.slice(0, count)]};
            components.push(body);
            if (f.footer) components.push({type: 'FOOTER', text: f.footer});
            if (f.buttons.length) components.push({type: 'BUTTONS', buttons: f.buttons.map(b => ({
                type: b.type, text: b.text, ...(b.type === 'URL' ? {url: b.value} : {}),
                ...(b.type === 'PHONE_NUMBER' ? {phone_number: b.value} : {}),
            }))});
            return {name: f.name, language: f.language, category: f.category, parameter_format: 'POSITIONAL', components};
        },
        actionSubmit: async function () {
            if (this.busy || this.confirming || this.inspect) return;
            this.capture();
            let definition;
            try {
                definition = this.definition();
                Definition.validate(definition, this.sample);
            } catch (error) {
                this.error = this.t(error.message, 'messages');
                this.reRender();
                return;
            }
            this.confirming = true;
            await this.confirm({message: this.t('submitWarning', 'messages'), cancelCallback: () => { this.confirming = false; }});
            this.confirming = false;
            this.busy = true;
            this.error = null;
            this.disableButton('submit');
            this.reRender();
            const payload = {...this.context, definition};
            if (this.record) Object.assign(payload, {templateId: this.record.templateId, name: this.record.name, language: this.record.language});
            let body = payload;
            if (this.sample) {
                body = new FormData();
                body.append('payload', JSON.stringify(payload));
                body.append('sample', this.sample);
            }
            try {
                await Espo.Ajax.postRequest(scope + '/action/' + (this.record ? 'updateTemplate' : 'createTemplate'), body, {timeout: 125000});
                this.trigger('accepted');
                this.close();
            } catch (error) {
                this.error = error.responseJSON?.message || this.t('unknownOutcome', 'messages');
                this.busy = false;
                this.sample = null; // A retry always requires fresh sample selection/upload.
                this.enableButton('submit');
                this.reRender();
            }
        },
    });
});
