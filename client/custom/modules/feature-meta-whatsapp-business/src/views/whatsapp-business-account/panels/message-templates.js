define('feature-meta-whatsapp-business:views/whatsapp-business-account/panels/message-templates',
    ['views/record/panels/bottom'], function (Dep) {
    const scope = 'WhatsAppBusinessAccountMessageTemplate';
    return Dep.extend({
        name: 'messageTemplates',
        scope,
        events: {
            'click [data-action]': function (event) {
                event.preventDefault();
                event.stopPropagation();
                const data = event.currentTarget.dataset;
                const method = 'action' + data.action.charAt(0).toUpperCase() + data.action.slice(1);
                if (typeof this[method] === 'function') this[method](data);
            },
        },
        templateContent: `
            <div class="btn-group">
                <button class="btn btn-default" data-action="createTemplate" {{#if busy}}disabled{{/if}}>{{t.create}}</button>
                <button class="btn btn-default" data-action="refreshMessageTemplates" {{#if busy}}disabled{{/if}}>{{t.refresh}}</button>
                <a class="btn btn-link" href="{{managerUrl}}" target="_blank" rel="noopener noreferrer">{{t.metaManager}}</a>
            </div>
            {{#if busy}}<p role="status">{{t.loading}}</p>{{/if}}
            {{#if notice}}<p class="text-success" role="status">{{notice}}</p>{{/if}}
            {{#if error}}<p class="text-danger" role="alert">{{error}}</p>{{/if}}
            <table class="table table-striped"><thead><tr>
                <th>{{t.name}}</th><th>{{t.language}}</th><th>{{t.status}}</th><th>{{t.category}}</th><th></th>
            </tr></thead><tbody>{{#each rows}}<tr>
                <td>{{name}}</td><td>{{language}}</td><td>{{status}}</td><td>{{category}}</td>
                <td><button class="btn btn-link" data-action="inspectTemplate" data-id="{{id}}">{{../t.inspect}}</button>
                {{#if canEdit}}<button class="btn btn-link" data-action="editTemplate" data-id="{{id}}" {{#if ../busy}}disabled{{/if}}>{{../t.edit}}</button>{{/if}}
                {{#if canDelete}}<button class="btn btn-link text-danger" data-action="deleteTemplate" data-id="{{id}}" {{#if ../busy}}disabled{{/if}}>{{../t.delete}}</button>{{/if}}</td>
            </tr>{{/each}}</tbody></table>
            {{#if empty}}<p>{{t.empty}}</p>{{/if}}
            {{#if hasMore}}<button class="btn btn-default" data-action="nextPage" {{#if busy}}disabled{{/if}}>{{t.loadMore}}</button>{{/if}}
        `,
        setup: function () {
            Dep.prototype.setup.call(this);
            this.rows = [];
            this.cursors = new Set();
            this.context = {
                oAuthAccountId: this.model.get('oAuthAccountId'), businessAccountId: this.model.get('wabaId'),
            };
            this.listenTo(this.model, 'sync', () => this.loadPage());
            this.loadPage();
        },
        t: function (key, category = 'labels') { return this.translate(key, category, scope); },
        data: function () {
            return {
                rows: this.rows, busy: this.busy, error: this.error, notice: this.notice,
                hasMore: this.hasMore, empty: this.loaded && !this.error && !this.rows.length,
                managerUrl: 'https://business.facebook.com/wa/manage/message-templates/?waba_id=' + encodeURIComponent(this.context.businessAccountId),
                t: Object.fromEntries(['create', 'refresh', 'metaManager', 'loading', 'name', 'language', 'status',
                    'category', 'inspect', 'edit', 'delete', 'empty', 'loadMore'].map(key => [key, this.t(key)])),
            };
        },
        loadPage: async function (append = false) {
            if (this.busy) return;
            this.busy = true;
            this.error = null;
            if (this.isRendered()) this.reRender();
            try {
                const result = await Espo.Ajax.getRequest(scope + '/action/listPage', {
                    ...this.context, ...(append ? {cursor: this.nextCursor} : {}),
                }, {timeout: 125000});
                if (!Array.isArray(result.list) || (result.hasMore &&
                    (!result.nextCursor || (append && this.cursors.has(result.nextCursor))))) throw new Error('catalogIncomplete');
                if (!append) this.cursors.clear();
                if (result.nextCursor) this.cursors.add(result.nextCursor);
                const rows = new Map((append ? this.rows : []).map(row => [row.id, row]));
                result.list.forEach(row => rows.set(row.id, row));
                this.rows = [...rows.values()];
                this.nextCursor = result.nextCursor;
                this.hasMore = result.hasMore;
                this.loaded = true;
            } catch (error) {
                this.error = error.responseJSON?.message || this.t('catalogIncomplete', 'messages');
            } finally {
                this.busy = false;
                if (this.isRendered()) this.reRender();
            }
        },
        actionRefreshMessageTemplates: function () { return this.loadPage(); },
        actionNextPage: function () { return this.loadPage(true); },
        actionCreateTemplate: function () { this.openEditor(null, false); },
        actionEditTemplate: function (data) { this.openEditor(this.rows.find(row => row.id === data.id), false); },
        actionInspectTemplate: function (data) { this.openEditor(this.rows.find(row => row.id === data.id), true); },
        openEditor: function (template, inspect) {
            if (this.busy) return;
            this.createView('editor', 'feature-meta-whatsapp-business:views/template-editor', {
                context: this.context, templateRecord: template, inspect,
            }, view => {
                this.listenToOnce(view, 'accepted', () => {
                    this.notice = this.t('accepted', 'messages');
                    this.loadPage();
                });
                view.render();
            });
        },
        actionDeleteTemplate: async function (data) {
            const template = this.rows.find(row => row.id === data.id);
            if (this.busy || this.confirming || !template?.canDelete) return;
            const warning = this.t('deleteWarning', 'messages') + '\n' +
                this.context.businessAccountId + ' / ' + template.name + ' / ' + template.language;
            this.confirming = true;
            await this.confirm({message: warning, cancelCallback: () => { this.confirming = false; }});
            this.confirming = false;
            this.busy = true;
            this.error = this.notice = null;
            this.reRender();
            try {
                await Espo.Ajax.postRequest(scope + '/action/deleteTemplate', {
                    ...this.context, templateId: template.templateId, name: template.name, language: template.language,
                }, {timeout: 125000});
                this.notice = this.t('accepted', 'messages');
            } catch (error) {
                this.error = error.responseJSON?.message || this.t('unknownOutcome', 'messages');
            } finally {
                this.busy = false;
                this.reRender();
            }
            if (this.notice) this.loadPage();
        },
    });
});
