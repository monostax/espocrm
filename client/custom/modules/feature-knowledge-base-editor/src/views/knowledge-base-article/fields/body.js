import BaseFieldView from 'views/fields/base';

/**
 * KnowledgeBaseArticle body — Lexical dual-mode (Html | Markdown).
 *
 * Storage model:
 * - bodyEditorState: canonical Lexical JSON (mentions/custom nodes safe)
 * - body: projected Html or Markdown for consumers (email, portal, search)
 * - bodyFormat: which projection consumers/detail use (default Html; existing rows untouched)
 */
class KnowledgeBaseBodyFieldView extends BaseFieldView {
    type = 'wysiwyg'

    listTemplate = 'fields/wysiwyg/detail'
    detailTemplateContent = `
        {{#unless isPlain}}
            <div class="html-container">{{{value}}}</div>
        {{else}}
            <div class="plain complex-text">{{complexText value}}</div>
        {{/unless}}
        {{#if isNone}}<span class="none-value">{{translate 'None'}}</span>{{/if}}
    `
    editTemplateContent = `
        <div class="kb-lexical-field" data-name="{{name}}">
            <div class="kb-lexical-toolbar btn-group btn-group-sm" role="toolbar">
                <button type="button" class="btn btn-default" data-action="undo" title="Undo"><span class="fas fa-undo fa-sm"></span></button>
                <button type="button" class="btn btn-default" data-action="redo" title="Redo"><span class="fas fa-redo fa-sm"></span></button>
                <span class="btn-group-divider"></span>
                <button type="button" class="btn btn-default" data-action="bold" title="Bold"><span class="fas fa-bold fa-sm"></span></button>
                <button type="button" class="btn btn-default" data-action="italic" title="Italic"><span class="fas fa-italic fa-sm"></span></button>
                <button type="button" class="btn btn-default" data-action="underline" title="Underline"><span class="fas fa-underline fa-sm"></span></button>
                <button type="button" class="btn btn-default" data-action="strike" title="Strike"><span class="fas fa-strikethrough fa-sm"></span></button>
                <button type="button" class="btn btn-default" data-action="code" title="Code"><span class="fas fa-code fa-sm"></span></button>
                <span class="btn-group-divider"></span>
                <button type="button" class="btn btn-default" data-action="ul" title="Bullet list"><span class="fas fa-list-ul fa-sm"></span></button>
                <button type="button" class="btn btn-default" data-action="ol" title="Numbered list"><span class="fas fa-list-ol fa-sm"></span></button>
                <button type="button" class="btn btn-default" data-action="link" title="Link"><span class="fas fa-link fa-sm"></span></button>
                <span class="btn-group-divider"></span>
                <button type="button" class="btn btn-default" data-action="source" title="Toggle source">
                    <span class="fas fa-file-code fa-sm"></span>
                </button>
            </div>
            <div class="kb-lexical-editor-wrap">
                <div class="kb-lexical-editor form-control" contenteditable="true"></div>
            </div>
            <textarea class="main-element form-control kb-lexical-source hidden auto-height"
                data-name="{{name}}"
                rows="16"
                style="resize:vertical;"
            ></textarea>
            <div class="kb-lexical-format-hint text-muted small">
                {{formatLabel}}
            </div>
        </div>
    `

    fetchEmptyValueAsNull = true
    seeMoreDisabled = true

    /** @type {any} */
    kbEditor = null
    sourceMode = false
    skipNextFormatConvert = false

    getAttributeList() {
        return [this.name, 'bodyEditorState', 'bodyFormat'];
    }

    setup() {
        super.setup();

        this.wait(
            Espo.loader.requirePromise('lib!lexical-kb').then(() => {
                this.EspoLexical = window.EspoLexical;
            })
        );

        this.listenTo(this.model, 'change:bodyFormat', (model, value, o) => {
            if (this.skipNextFormatConvert) {
                this.skipNextFormatConvert = false;
                if (this.isEditMode() && this.isRendered()) {
                    this.reRender();
                }
                return;
            }

            if (!o || !o.ui) {
                if (this.isRendered()) {
                    this.reRender();
                }
                return;
            }

            this.handleFormatSwitch(value);
        });

        this.addHandler('click', '[data-action]', (e, target) => {
            const action = target.getAttribute('data-action');
            this.onToolbarAction(action);
        });
    }

    data() {
        const data = super.data();
        const format = this.getBodyFormat();
        const value = this.model.get(this.name);

        data.isPlain = format === 'Markdown';
        data.isNone = !data.isNotEmpty && data.valueIsSet && this.isDetailMode();
        data.formatLabel = this.translate(format, 'options', 'KnowledgeBaseArticle') ||
            this.translate(format, 'labels') ||
            format;

        if (this.isDetailMode() || this.isListMode()) {
            if (format === 'Html') {
                data.value = this.sanitizeHtml(value || '');
            } else {
                data.value = value || '';
            }
        }

        return data;
    }

    getBodyFormat() {
        return this.model.get('bodyFormat') || 'Html';
    }

    isHtmlMode() {
        return this.getBodyFormat() !== 'Markdown';
    }

    sanitizeHtml(value) {
        if (!value) {
            return '';
        }

        return this.getHelper().sanitizeHtml(value);
    }

    afterRender() {
        super.afterRender();

        if (!this.isEditMode()) {
            return;
        }

        this.$source = this.$el.find('textarea.kb-lexical-source');
        this.$editorEl = this.$el.find('.kb-lexical-editor');
        this.sourceMode = false;
        this.$source.addClass('hidden');
        this.$el.find('.kb-lexical-editor-wrap').removeClass('hidden');

        this.initEditor();
    }

    initEditor() {
        this.destroyEditor();

        if (!this.EspoLexical || !this.$editorEl.length) {
            return;
        }

        const element = this.$editorEl.get(0);

        this.kbEditor = this.EspoLexical.createKbEditor({
            element,
            namespace: 'EspoKB-' + this.cid,
            onChange: () => {
                this.trigger('change');
            },
        });

        this.loadContentIntoEditor();

        const minHeight = this.params.minHeight || 220;
        this.$editorEl.css({minHeight: minHeight + 'px'});
    }

    /**
     * Prefer Lexical JSON; fall back to body projection (legacy Summernote HTML / MD).
     */
    loadContentIntoEditor() {
        if (!this.kbEditor) {
            return;
        }

        const state = this.model.get('bodyEditorState');

        if (state && this.kbEditor.setEditorStateJSON(state)) {
            return;
        }

        const raw = this.model.get(this.name) || '';

        if (this.isHtmlMode()) {
            this.kbEditor.setHtml(raw);
        } else {
            this.kbEditor.setMarkdown(raw);
        }
    }

    destroyEditor() {
        if (this.kbEditor) {
            this.kbEditor.destroy();
            this.kbEditor = null;
        }
    }

    onRemove() {
        this.destroyEditor();
        super.onRemove();
    }

    onToolbarAction(action) {
        if (action === 'source') {
            this.toggleSourceMode();
            return;
        }

        if (this.sourceMode || !this.kbEditor) {
            return;
        }

        switch (action) {
            case 'bold':
                this.kbEditor.formatBold();
                break;
            case 'italic':
                this.kbEditor.formatItalic();
                break;
            case 'underline':
                this.kbEditor.formatUnderline();
                break;
            case 'strike':
                this.kbEditor.formatStrikethrough();
                break;
            case 'code':
                this.kbEditor.formatCode();
                break;
            case 'ul':
                this.kbEditor.insertUnorderedList();
                break;
            case 'ol':
                this.kbEditor.insertOrderedList();
                break;
            case 'undo':
                this.kbEditor.undo();
                break;
            case 'redo':
                this.kbEditor.redo();
                break;
            case 'link':
                this.promptLink();
                break;
        }

        this.trigger('change');
    }

    promptLink() {
        const url = window.prompt(this.translate('URL', 'fields', 'Email') || 'URL', 'https://');

        if (url === null) {
            return;
        }

        this.kbEditor.toggleLink(url.trim() || null);
    }

    toggleSourceMode() {
        if (!this.kbEditor) {
            return;
        }

        if (!this.sourceMode) {
            // Source shows projected body (Html/MD), not raw Lexical JSON.
            const value = this.readProjectedBody();
            this.$source.val(value);
            this.$el.find('.kb-lexical-editor-wrap').addClass('hidden');
            this.$source.removeClass('hidden');
            this.sourceMode = true;
            this.kbEditor.setEditable(false);
            return;
        }

        const source = this.$source.val() || '';
        this.$source.addClass('hidden');
        this.$el.find('.kb-lexical-editor-wrap').removeClass('hidden');
        this.sourceMode = false;
        this.kbEditor.setEditable(true);

        if (this.isHtmlMode()) {
            this.kbEditor.setHtml(source);
        } else {
            this.kbEditor.setMarkdown(source);
        }

        this.trigger('change');
    }

    normalizeHtmlUrls(html) {
        const imageTagString =
            `<img src="${window.location.origin}${window.location.pathname}?entryPoint=attachment`;

        return html.replace(
            new RegExp(imageTagString.replace(/([.*+?^=!:${}()|\[\]\/\\])/g, '\\$1'), 'g'),
            '<img src="?entryPoint=attachment'
        );
    }

    readProjectedBody() {
        if (this.sourceMode) {
            return this.$source.val() || '';
        }

        if (!this.kbEditor) {
            return this.model.get(this.name) || '';
        }

        if (this.isHtmlMode()) {
            return this.normalizeHtmlUrls(this.kbEditor.getHtml() || '');
        }

        return this.kbEditor.getMarkdown() || '';
    }

    readEditorStateJSON() {
        if (!this.kbEditor) {
            return this.model.get('bodyEditorState') || null;
        }

        // If user is in source mode, import source first so state matches.
        if (this.sourceMode) {
            const source = this.$source.val() || '';

            if (this.isHtmlMode()) {
                this.kbEditor.setHtml(source);
            } else {
                this.kbEditor.setMarkdown(source);
            }
        }

        if (this.kbEditor.isEmpty()) {
            return null;
        }

        return this.kbEditor.getEditorStateJSON();
    }

    handleFormatSwitch(newFormat) {
        const previous = newFormat === 'Markdown' ? 'Html' : 'Markdown';

        // Prefer live Lexical doc (preserves structure) when editing.
        const hasLiveEditor = this.isEditMode() && this.isRendered() && this.kbEditor && !this.sourceMode;

        const currentBody = this.isEditMode() && this.isRendered()
            ? this.readProjectedBody()
            : (this.model.get(this.name) || '');

        if (!currentBody && !hasLiveEditor) {
            if (this.isEditMode() && this.isRendered()) {
                this.reRender();
            }
            return;
        }

        const msg = this.translate('bodyFormatSwitchConfirm', 'messages', 'KnowledgeBaseArticle');

        if (!window.confirm(msg)) {
            this.skipNextFormatConvert = true;
            this.model.set('bodyFormat', previous, {ui: false});
            return;
        }

        // Live editor: same Lexical document (bodyEditorState). Only body projection changes.
        // https://lexical.dev/docs/packages/lexical-markdown#import-and-export
        if (hasLiveEditor) {
            // bodyFormat already updated on the model; re-project with new format.
            this.model.set({
                [this.name]: this.readProjectedBody() || null,
                bodyEditorState: this.readEditorStateJSON(),
            }, {skipReRender: true});
            this.reRender();
            return;
        }

        let converted = currentBody;
        let nextState = null;

        if (newFormat === 'Markdown' && this.EspoLexical) {
            converted = this.EspoLexical.htmlToMarkdown(currentBody);
            // Rebuild Lexical state from MD so it wins on next edit.
            const hold = document.createElement('div');
            hold.style.display = 'none';
            document.body.appendChild(hold);
            try {
                const tmp = this.EspoLexical.createKbEditor({
                    element: hold,
                    editable: false,
                    markdownShortcuts: false,
                });
                tmp.setMarkdown(converted);
                nextState = tmp.getEditorStateJSON();
                tmp.destroy();
            } finally {
                hold.remove();
            }
        } else if (newFormat === 'Html' && this.EspoLexical) {
            converted = this.EspoLexical.markdownToHtml(currentBody);
            const hold = document.createElement('div');
            hold.style.display = 'none';
            document.body.appendChild(hold);
            try {
                const tmp = this.EspoLexical.createKbEditor({
                    element: hold,
                    editable: false,
                    markdownShortcuts: false,
                });
                tmp.setHtml(converted);
                nextState = tmp.getEditorStateJSON();
                tmp.destroy();
            } finally {
                hold.remove();
            }
        }

        this.model.set({
            [this.name]: converted || null,
            bodyEditorState: nextState,
        }, {skipReRender: true});

        if (this.isEditMode() && this.isRendered()) {
            this.reRender();
        }
    }

    fetch() {
        const data = {};
        let body = this.isEditMode() ? this.readProjectedBody() : this.model.get(this.name);
        let state = this.isEditMode() ? this.readEditorStateJSON() : this.model.get('bodyEditorState');

        if (typeof body === 'string') {
            body = body.trim();
        }

        if (this.fetchEmptyValueAsNull && !body) {
            body = null;
            state = null;
        }

        data[this.name] = body;
        data.bodyEditorState = state;

        return data;
    }

    validateRequired() {
        if (!this.isRequired()) {
            return false;
        }

        const value = this.isEditMode() ? this.readProjectedBody() : this.model.get(this.name);

        if (!value || !String(value).trim()) {
            const msg = this.translate('fieldIsRequired', 'messages')
                .replace('{field}', this.getLabelText());
            this.showValidationMessage(msg);
            return true;
        }

        return false;
    }
}

export default KnowledgeBaseBodyFieldView;
