import LexicalBodyFieldView from 'feature-knowledge-base-editor:views/fields/lexical-body';

export default class DocumentBodyFieldView extends LexicalBodyFieldView {
    data() {
        const data = super.data();

        if ((this.isDetailMode() || this.isListMode()) && this.getBodyFormat() === 'Markdown') {
            data.isPlain = false;
            data.value = this.sanitizeHtml(this.EspoLexical.markdownToHtml(this.model.get(this.name) || ''));
        }

        return data;
    }
}
