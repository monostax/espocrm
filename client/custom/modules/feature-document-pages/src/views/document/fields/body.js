import LexicalBodyFieldView from 'feature-knowledge-base-editor:views/fields/lexical-body';
import BaseFieldView from 'views/fields/base';
import {sourceValue} from 'feature-record-knowledge:content';

export default class DocumentBodyFieldView extends LexicalBodyFieldView {
    isMarkdownSource() { return this.model.get('bodyAuthoringMode') === 'Markdown'; }

    getAttributeList() { return [...super.getAttributeList(), 'bodyAuthoringMode']; }

    setup() {
        if (this.isMarkdownSource()) {
            this.editTemplateContent = `
                <textarea class="main-element form-control" data-name="markdownSource" rows="18" style="resize:vertical;font-family:monospace"></textarea>
                <button type="button" class="btn btn-default btn-sm margin-top" data-action="markdown-preview">{{translate 'Preview'}}</button>
                <div class="html-container margin-top" data-name="markdownPreview"></div>
            `;
        }
        super.setup();
        this.addHandler('input', '[data-name="markdownSource"]', () => this.trigger('change'));
    }

    data() { return {...super.data(), source: this.model.get(this.name) || ''}; }

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
        if (!this.isMarkdownSource()) return super.fetch();
        return {[this.name]: this.isEditMode() && this.isRendered()
            ? sourceValue(this.el.querySelector('[data-name="markdownSource"]'), this.model.get(this.name) || '') : (this.model.get(this.name) || ''),
            bodyEditorState: null, bodyFormat: 'Markdown', bodyAuthoringMode: 'Markdown'};
    }
}
