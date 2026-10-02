import BaseFieldView from 'views/fields/base';

/**
 * Shared Lexical body field — dual-mode (Html | Markdown).
 *
 * Storage model:
 * - bodyEditorState: canonical Lexical JSON (mentions/custom nodes safe)
 * - body: projected Html or Markdown for consumers (email, portal, search)
 * - bodyFormat: which projection consumers/detail use (default Html; existing rows untouched)
 */
class LexicalBodyFieldView extends BaseFieldView {
    type = 'wysiwyg'
    editorStateField = 'bodyEditorState'
    formatField = 'bodyFormat'

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
            <div class="kb-lexical-toolbar" role="toolbar">
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-default" data-action="undo" title="Undo"><span class="fas fa-undo fa-sm"></span></button>
                    <button type="button" class="btn btn-default" data-action="redo" title="Redo"><span class="fas fa-redo fa-sm"></span></button>
                </div>
                <span class="btn-group-divider"></span>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-default" data-action="bold" title="Bold"><span class="fas fa-bold fa-sm"></span></button>
                    <button type="button" class="btn btn-default" data-action="italic" title="Italic"><span class="fas fa-italic fa-sm"></span></button>
                    <button type="button" class="btn btn-default" data-action="underline" title="Underline"><span class="fas fa-underline fa-sm"></span></button>
                    <button type="button" class="btn btn-default" data-action="strike" title="Strike"><span class="fas fa-strikethrough fa-sm"></span></button>
                    <button type="button" class="btn btn-default" data-action="code" title="Code"><span class="fas fa-code fa-sm"></span></button>
                </div>
                <span class="btn-group-divider"></span>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-default" data-action="ul" title="Bullet list"><span class="fas fa-list-ul fa-sm"></span></button>
                    <button type="button" class="btn btn-default" data-action="ol" title="Numbered list"><span class="fas fa-list-ol fa-sm"></span></button>
                    <button type="button" class="btn btn-default" data-action="link" title="Link"><span class="fas fa-link fa-sm"></span></button>
                    <button type="button" class="btn btn-default" data-action="table" title="Insert table"><span class="fas fa-table fa-sm"></span></button>
                    <button type="button" class="btn btn-default" data-action="date-separator" title="Date separator"><span class="fas fa-calendar-alt fa-sm"></span></button>
                </div>
                <span class="btn-group-divider"></span>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-default" data-action="source" title="Toggle source">
                        <span class="fas fa-file-code fa-sm"></span>
                    </button>
                </div>
                <span class="btn-group-divider"></span>
                {{#if hasFormatField}}<div class="kb-lexical-format-select btn-group btn-group-sm">
                    <select class="form-control input-sm" data-name="bodyFormatInline" title="{{bodyFormatFieldLabel}}">
                        <option value="Html"{{#if isHtmlFormat}} selected{{/if}}>HTML</option>
                        <option value="Markdown"{{#if isMarkdownFormat}} selected{{/if}}>Markdown</option>
                    </select>
                </div>{{/if}}
            </div>
            <div class="kb-lexical-editor-wrap">
                <div class="kb-lexical-editor form-control" contenteditable="true" role="textbox" aria-multiline="true" aria-label="{{name}}" aria-description="Type slash for blocks, at sign for references. Alt+F10 opens selection formatting. Alt+Shift+Arrow moves a block."></div>
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
        return [this.name, this.editorStateField, this.formatField].filter(Boolean);
    }

    setup() {
        super.setup();

        this.wait(
            Espo.loader.requirePromise('lib!lexical-kb').then(() => {
                this.EspoLexical = window.EspoLexical;
            })
        );

        if (this.formatField) this.listenTo(this.model, 'change:' + this.formatField, (model, value, o) => {
            if (this.skipNextFormatConvert) {
                this.skipNextFormatConvert = false;
                if (this.isRendered()) {
                    this.$el.find('select[data-name="bodyFormatInline"]').val(value);
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
        this.addHandler('mousedown', '.kb-lexical-toolbar button', e => e.preventDefault());
        this.on('render', () => this.destroyEditor());

        this.addHandler('input', 'textarea.kb-lexical-source', () => this.trigger('change'));

        this.addHandler('change', 'select[data-name="bodyFormatInline"]', (e, target) => {
            const next = target.value === 'Markdown' ? 'Markdown' : 'Html';

            if (next === this.getBodyFormat()) {
                return;
            }

            if (this.formatField) this.model.set(this.formatField, next, {ui: true});
        });
    }

    data() {
        const data = super.data();
        const format = this.getBodyFormat();
        const value = this.model.get(this.name);

        data.isPlain = format === 'Markdown';
        data.bodyFormat = format;
        data.hasFormatField = !!this.formatField;
        data.isHtmlFormat = format !== 'Markdown';
        data.isMarkdownFormat = format === 'Markdown';
        data.isNone = !data.isNotEmpty && data.valueIsSet && this.isDetailMode();
        data.formatLabel = this.getLanguage().translateOption(format, 'bodyFormat', this.model.entityType);
        data.bodyFormatFieldLabel = this.translate('bodyFormat', 'fields', this.model.entityType);

        if (this.isDetailMode() || this.isListMode()) {
            if (format === 'Html') {
                data.value = this.sanitizeHtml(value || '');
            } else if (this.EspoLexical) {
                data.isPlain = false;
                data.value = this.sanitizeHtml(this.EspoLexical.markdownToHtml(value || ''));
            } else {
                data.value = value || '';
            }
        }

        return data;
    }

    getBodyFormat() {
        return (this.formatField && this.model.get(this.formatField)) || 'Html';
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
            this.resolveDetailReferences();
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
            namespace: 'Espo-' + this.model.entityType + '-' + this.cid,
            notion: true,
            locale: this.getLanguage().name,
            references: {
                search: (query, signal) => this.referenceRequest('get', 'EditorReference/search', {q: query}, signal).then(data => data.list),
                resolve: references => this.referenceRequest('post', 'EditorReference/resolve', {references}).then(data => data.list),
            },
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

        const state = this.model.get(this.editorStateField);

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
        this.referenceGeneration = (this.referenceGeneration || 0) + 1;
        for (const abort of this.pendingReferences || []) abort();
        this.pendingReferences?.clear();
        if (this.kbEditor) {
            this.kbEditor.destroy();
            this.kbEditor = null;
        }
    }

    referenceRequest(method, url, data, signal) {
        this.pendingReferences ||= new Set();
        const request = Espo.Ajax[method === 'get' ? 'getRequest' : 'postRequest'](url, data);
        return new Promise((resolve, reject) => {
            const cleanup = () => { this.pendingReferences.delete(abort); signal?.removeEventListener('abort', abort); };
            const abort = () => { request.abort(); cleanup(); reject(new DOMException('Aborted', 'AbortError')); };
            this.pendingReferences.add(abort);
            if (signal?.aborted) { abort(); return; }
            signal?.addEventListener('abort', abort, {once: true});
            request.then(value => { cleanup(); resolve(value); }, error => { cleanup(); reject(error); });
        });
    }

    resolveDetailReferences() {
        if (!this.EspoLexical) return;
        const generation = (this.referenceGeneration || 0) + 1;
        this.referenceGeneration = generation;
        const links = [...this.el.querySelectorAll('a[href^="#crm-reference/"]')];
        const records = [];
        for (const link of links) {
            const ref = this.EspoLexical.parseReferenceUrl(link.getAttribute('href'));
            link.removeAttribute('href');
            link.classList.add('kb-mention');
            if (ref?.kind === 'record') {
                link.textContent = 'Unavailable reference';
                records.push({link, ref});
            } else if (!ref) link.textContent = 'Unavailable reference';
        }
        if (!records.length) return;
        const references = [...new Map(records.map(item => [this.EspoLexical.referenceUrl(item.ref), item.ref])).values()];
        this.referenceRequest('post', 'EditorReference/resolve', {references}).then(data => {
            if (generation !== this.referenceGeneration) return;
            const resolved = new Map(data.list.map(ref => [this.EspoLexical.referenceUrl(ref), ref]));
            for (const {link, ref} of records) {
                const result = resolved.get(this.EspoLexical.referenceUrl(ref));
                if (!result?.available) continue;
                link.textContent = result.label;
                link.href = `#${ref.entityType}/view/${ref.recordId}`;
            }
        }).catch(() => {});
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
            case 'table':
                this.promptTable();
                break;
            case 'date-separator':
                this.kbEditor.openDateSeparatorPicker();
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

    promptTable() {
        const raw = window.prompt(
            this.translate('insertTablePrompt', 'messages', this.model.entityType) ||
                'Table size (rows x columns)',
            '3x3'
        );

        if (raw === null) {
            return;
        }

        const match = String(raw).trim().match(/^(\d+)\s*[xX,;]\s*(\d+)$/) ||
            String(raw).trim().match(/^(\d+)\s+(\d+)$/);

        let rows = 3;
        let columns = 3;

        if (match) {
            rows = parseInt(match[1], 10);
            columns = parseInt(match[2], 10);
        }

        this.kbEditor.insertTable({rows, columns, includeHeaders: true});
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
            return this.model.get(this.editorStateField) || null;
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

        const msg = this.translate('bodyFormatSwitchConfirm', 'messages', this.model.entityType);

        if (!window.confirm(msg)) {
            this.skipNextFormatConvert = true;
            this.model.set(this.formatField, previous, {ui: false});
            return;
        }

        // Live editor: same Lexical document (bodyEditorState). Only body projection changes.
        // https://lexical.dev/docs/packages/lexical-markdown#import-and-export
        if (hasLiveEditor) {
            // bodyFormat already updated on the model; re-project with new format.
            this.model.set({
                [this.name]: this.readProjectedBody() || null,
                [this.editorStateField]: this.readEditorStateJSON(),
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
            [this.editorStateField]: nextState,
        }, {skipReRender: true});

        if (this.isEditMode() && this.isRendered()) {
            this.reRender();
        }
    }

    fetch() {
        const data = {};
        let body = this.isEditMode() ? this.readProjectedBody() : this.model.get(this.name);
        let state = this.isEditMode() ? this.readEditorStateJSON() : this.model.get(this.editorStateField);

        if (typeof body === 'string') {
            body = body.trim();
        }

        if (this.fetchEmptyValueAsNull && !body) {
            body = null;
            state = null;
        }

        data[this.name] = body;
        data[this.editorStateField] = state;
        if (this.formatField) data[this.formatField] = this.getBodyFormat();

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

export default LexicalBodyFieldView;
