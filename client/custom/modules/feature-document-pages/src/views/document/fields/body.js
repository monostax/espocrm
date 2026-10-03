import LexicalBodyFieldView from 'feature-knowledge-base-editor:views/fields/lexical-body';
import BaseFieldView from 'views/fields/base';
import {sourceValue} from 'feature-record-knowledge:content';

export default class DocumentBodyFieldView extends LexicalBodyFieldView {
    markdownEditTemplateContent = `
        <textarea class="main-element form-control" data-name="markdownSource" rows="18" style="resize:vertical;font-family:monospace"></textarea>
        <button type="button" class="btn btn-default btn-sm margin-top" data-action="markdown-preview">{{translate 'Preview'}}</button>
        <div class="html-container margin-top" data-name="markdownPreview"></div>
    `
    isOverview() { return !!this.model.get('knowledgeRecordType'); }
    isMarkdownSource() { return !this.isOverview() && this.model.get('bodyAuthoringMode') === 'Markdown'; }
    getBodyFormat() { return this.isOverview() ? 'Markdown' : super.getBodyFormat(); }

    getAttributeList() { return [...super.getAttributeList(), 'bodyAuthoringMode', 'knowledgeRecordType', 'knowledgeRecordId']; }

    setup() {
        this.lexicalEditTemplateContent = this.editTemplateContent;
        super.setup();
        this.addHandler('input', '[data-name="markdownSource"]', () => this.trigger('change'));
    }

    prepareRender() {
        // Quick-detail can start with a partial list model. Choose the template
        // after authoring metadata has loaded, not just during initial setup.
        if (this.isEditMode()) this.setTemplateContent(this.isMarkdownSource()
            ? this.markdownEditTemplateContent : this.lexicalEditTemplateContent);
        return super.prepareRender();
    }

    data() {
        const data = super.data();
        return {...data, hasFormatField: !this.isOverview() && data.hasFormatField, source: this.model.get(this.name) || ''};
    }

    loadContentIntoEditor() {
        super.loadContentIntoEditor();
        if (this.isOverview() && this.kbEditor) this.initialEditorState = this.contentState(this.readEditorStateJSON());
    }

    contentState(state) {
        if (!state) return state;
        // Reference resolution refreshes labels without changing authored content.
        return JSON.stringify(JSON.parse(state), (key, value) => value?.type === 'crm-mention'
            ? {...value, text: '', reference: {...value.reference, label: ''}} : value);
    }

    afterRender() {
        if (!this.isMarkdownSource()) { super.afterRender(); return; }
        BaseFieldView.prototype.afterRender.call(this);
        if (this.isEditMode()) this.el.querySelector('[data-name="markdownSource"]').value = this.model.get(this.name) || '';
        else this.resolveDetailReferences();
    }

    onToolbarAction(action) {
        if (action !== 'markdown-preview') { super.onToolbarAction(action); return; }
        const source = this.el.querySelector('[data-name="markdownSource"]').value;
        this.el.querySelector('[data-name="markdownPreview"]').innerHTML = this.sanitizeHtml(this.EspoLexical.markdownToHtml(source));
        this.resolveDetailReferences();
    }

    fetch() {
        if (this.isOverview()) {
            const data = super.fetch();
            // Opening a legacy Markdown overview must not normalize its source on
            // an unchanged save. Only edits project the rich document to Markdown.
            if (this.contentState(data.bodyEditorState) === this.initialEditorState) {
                return {[this.name]: this.model.get(this.name) || '', bodyEditorState: this.model.get('bodyEditorState') || null,
                    bodyFormat: 'Markdown', bodyAuthoringMode: this.model.get('bodyAuthoringMode') || 'Markdown'};
            }
            return {...data, bodyAuthoringMode: 'Lexical'};
        }
        if (!this.isMarkdownSource()) return super.fetch();
        return {[this.name]: this.isEditMode() && this.isRendered()
            ? sourceValue(this.el.querySelector('[data-name="markdownSource"]'), this.model.get(this.name) || '') : (this.model.get(this.name) || ''),
            bodyEditorState: null, bodyFormat: 'Markdown', bodyAuthoringMode: 'Markdown'};
    }
}
