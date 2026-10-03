import BottomPanelView from 'views/record/panels/bottom';
import {identity, endpoint, preview, download} from 'feature-record-knowledge:content';

export default class extends BottomPanelView {
    templateContent = `
        {{#if loading}}<span role="status">{{translate 'Loading...'}}</span>{{/if}}
        {{#if error}}<span role="status">{{translate 'knowledgeUnavailable' category='messages'}}</span>{{/if}}
        {{#if document}}
        <div class="btn-group btn-group-sm margin-bottom">
            {{#if document.editable}}<button class="btn btn-default" type="button" data-action="edit">{{translate 'Edit'}}</button>{{/if}}
            <a class="btn btn-default" href="#Document/view/{{document.documentId}}">{{translate 'Open Document'}}</a>
            <button class="btn btn-default" type="button" data-action="export">{{translate 'Export Markdown'}}</button>
            {{#if document.editable}}<button class="btn btn-default" type="button" data-action="import">{{translate 'Import Markdown'}}</button>{{/if}}
        </div>
        <input type="file" class="hidden" data-name="markdownImport" accept=".md,text/markdown,text/plain">
        <div class="html-container" data-name="overviewPreview"></div>
        {{#if empty}}<span class="text-muted">{{translate 'emptyOverview' category='messages'}}</span>{{/if}}
        <small class="text-muted">Revision {{document.revision.revisionNumber}}</small>
        {{/if}}
    `

    setup() {
        super.setup();
        this.listenTo(this.model, 'sync knowledge:updated', () => this.load());
        this.addHandler('click', '[data-action="edit"]', () => this.edit());
        this.addHandler('click', '[data-action="export"]', () => this.export());
        this.addHandler('click', '[data-action="import"]', () => this.el.querySelector('[data-name="markdownImport"]').click());
        this.addHandler('change', '[data-name="markdownImport"]', (event, input) => this.import(input));
        this.wait(this.load());
    }

    async load() {
        if (!this.model.id || this.model.get('knowledgeRecordType')) return;
        const generation = (this.generation || 0) + 1;
        this.generation = generation;
        this.loading = true;
        this.error = false;
        try {
            const data = await Espo.Ajax.getRequest('RecordKnowledge/overview', identity(this.model));
            if (generation !== this.generation) return;
            this.document = data;
        } catch (e) {
            if (generation !== this.generation) return;
            this.document = null;
            this.error = true;
        }
        this.loading = false;
        if (this.isRendered()) this.reRender();
    }

    data() { return {...super.data(), loading: this.loading, error: this.error, document: this.document, empty: this.document && !this.document.body}; }
    afterRender() {
        super.afterRender();
        const element = this.el.querySelector('[data-name="overviewPreview"]');
        if (element) preview(this, element, this.document?.html);
    }

    async edit() {
        if (!this.document?.editable) return;
        const view = await this.createView('knowledgeEditor', 'feature-record-knowledge:views/modals/editor', {parentModel: this.model, document: this.document});
        this.listenToOnce(view, 'saved', () => this.model.trigger('knowledge:updated'));
        view.render();
    }
    async export() {
        const data = await Espo.Ajax.getRequest('RecordKnowledge/export', identity(this.model));
        download(data.filename, data.content);
    }
    async import(input) {
        const file = input.files[0];
        input.value = '';
        if (!file || !this.document?.editable) return;
        if (file.size > 2001000) { Espo.Ui.error('Markdown exceeds 2 MB.'); return; }
        try {
            await Espo.Ajax.postRequest(endpoint('import', this.model), {content: await file.text()},
                {headers: {'X-Version-Number': this.document.versionNumber}});
            this.model.trigger('knowledge:updated');
        } catch (error) {
            Espo.Ui.error(this.translate(error.status === 409 ? 'knowledgeConflict' : 'knowledgeUnavailable', 'messages'));
        }
    }
    onRemove() { this.generation = (this.generation || 0) + 1; super.onRemove(); }
}
