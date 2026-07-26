import BaseFieldView from 'views/fields/base';

/**
 * AgentMode.body — Lexical markdown (OpenCode mode prompt after frontmatter).
 *
 * SoT on disk is YAML frontmatter + body. CRM maps:
 *   model, temperature, tools → frontmatter
 *   body → markdown prompt after frontmatter (this field)
 *
 * @see https://open-code.ai/en/docs/modes
 */
class AgentModeBodyFieldView extends BaseFieldView {
    type = 'text'

    detailTemplateContent = `
        {{#if isNone}}
            <span class="none-value">{{translate 'None'}}</span>
        {{else}}
            <div class="html-container agent-mode-body-detail">{{{html}}}</div>
        {{/if}}
    `

    editTemplateContent = `
        <div class="kb-lexical-field agent-mode-lexical" data-name="{{name}}">
            <div class="kb-lexical-toolbar btn-group btn-group-sm" role="toolbar">
                <button type="button" class="btn btn-default" data-action="undo" title="Undo"><span class="fas fa-undo fa-sm"></span></button>
                <button type="button" class="btn btn-default" data-action="redo" title="Redo"><span class="fas fa-redo fa-sm"></span></button>
                <span class="btn-group-divider"></span>
                <button type="button" class="btn btn-default" data-action="bold" title="Bold"><span class="fas fa-bold fa-sm"></span></button>
                <button type="button" class="btn btn-default" data-action="italic" title="Italic"><span class="fas fa-italic fa-sm"></span></button>
                <button type="button" class="btn btn-default" data-action="strike" title="Strike"><span class="fas fa-strikethrough fa-sm"></span></button>
                <button type="button" class="btn btn-default" data-action="code" title="Code"><span class="fas fa-code fa-sm"></span></button>
                <span class="btn-group-divider"></span>
                <button type="button" class="btn btn-default" data-action="ul" title="Bullet list"><span class="fas fa-list-ul fa-sm"></span></button>
                <button type="button" class="btn btn-default" data-action="ol" title="Numbered list"><span class="fas fa-list-ol fa-sm"></span></button>
                <button type="button" class="btn btn-default" data-action="link" title="Link"><span class="fas fa-link fa-sm"></span></button>
                <span class="btn-group-divider"></span>
                <button type="button" class="btn btn-default" data-action="source" title="Markdown source">
                    <span class="fas fa-file-code fa-sm"></span>
                </button>
            </div>
            <div class="kb-lexical-editor-wrap">
                <div class="kb-lexical-editor form-control" contenteditable="true"></div>
            </div>
            <textarea class="main-element form-control kb-lexical-source hidden auto-height"
                data-name="{{name}}"
                rows="18"
                style="resize:vertical;"
            ></textarea>
            <div class="kb-lexical-format-hint text-muted small">
                Markdown prompt (YAML frontmatter is model / temperature / tools)
            </div>
        </div>
    `

    fetchEmptyValueAsNull = false
    seeMoreDisabled = true

    /** @type {any} */
    modeEditor = null
    sourceMode = false

    setup() {
        super.setup();

        this.wait(
            Espo.loader.requirePromise('lib!lexical-kb').then(() => {
                this.EspoLexical = window.EspoLexical;
            })
        );

        this.addHandler('click', '[data-action]', (e, target) => {
            this.onToolbarAction(target.getAttribute('data-action'));
        });
    }

    data() {
        const data = super.data();
        const raw = this.model.get(this.name) || '';
        const body = this.stripBody(raw);

        data.isNone = !body && data.valueIsSet && this.isDetailMode();

        if (this.isDetailMode() || this.isListMode()) {
            data.html = this.renderMarkdownHtml(body);
        }

        return data;
    }

    /**
     * @param {string} raw
     * @returns {string}
     */
    stripBody(raw) {
        if (!this.EspoLexical || typeof this.EspoLexical.stripFrontmatter !== 'function') {
            return this.stripFrontmatterFallback(raw);
        }

        return this.EspoLexical.stripFrontmatter(raw || '');
    }

    /**
     * @param {string} raw
     * @returns {{frontmatter: string|null, yaml: string, body: string, fields: Record<string, string>}}
     */
    splitRaw(raw) {
        if (this.EspoLexical && typeof this.EspoLexical.splitFrontmatter === 'function') {
            return this.EspoLexical.splitFrontmatter(raw || '');
        }

        const body = this.stripFrontmatterFallback(raw);

        return {
            frontmatter: body === (raw || '') ? null : '---',
            yaml: '',
            body,
            fields: {},
        };
    }

    stripFrontmatterFallback(raw) {
        const content = String(raw || '').replace(/^\uFEFF/, '');
        const match = content.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n?([\s\S]*)$/);

        if (!match) {
            return content;
        }

        return (match[2] || '').replace(/^\n/, '');
    }

    /**
     * @param {string} markdown
     * @returns {string}
     */
    renderMarkdownHtml(markdown) {
        if (!markdown) {
            return '';
        }

        if (this.EspoLexical && typeof this.EspoLexical.markdownToHtml === 'function') {
            const html = this.EspoLexical.markdownToHtml(markdown);

            return this.getHelper().sanitizeHtml(html || '');
        }

        return this.getHelper().sanitizeHtml(
            '<pre class="complex-text">' + this.getHelper().escapeString(markdown) + '</pre>'
        );
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

        this.modeEditor = this.EspoLexical.createKbEditor({
            element,
            namespace: 'EspoAgentMode-' + this.cid,
            onChange: () => {
                this.trigger('change');
            },
        });

        this.loadContentIntoEditor();

        const minHeight = this.params.minHeight || 280;
        this.$editorEl.css({minHeight: minHeight + 'px'});
    }

    loadContentIntoEditor() {
        if (!this.modeEditor) {
            return;
        }

        const raw = this.model.get(this.name) || '';
        const parsed = this.splitRaw(raw);

        this.maybeHydrateFrontmatterFields(parsed.fields);
        this.modeEditor.setMarkdown(parsed.body || '');
    }

    /**
     * If user pasted full mode .md, copy yaml model/temperature into empty fields.
     *
     * @param {Record<string, string>} fields
     */
    maybeHydrateFrontmatterFields(fields) {
        if (!fields || !Object.keys(fields).length) {
            return;
        }

        const patch = {};

        if (fields.model && !this.model.get('model')) {
            patch.model = fields.model;
        }

        if (fields.temperature != null && fields.temperature !== '' &&
            (this.model.get('temperature') === null || this.model.get('temperature') === undefined ||
                this.model.get('temperature') === '')
        ) {
            const t = Number(fields.temperature);

            if (Number.isFinite(t)) {
                patch.temperature = t;
            }
        }

        if (Object.keys(patch).length) {
            this.model.set(patch, {ui: false, skipReRender: true});
        }
    }

    destroyEditor() {
        if (this.modeEditor) {
            this.modeEditor.destroy();
            this.modeEditor = null;
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

        if (this.sourceMode || !this.modeEditor) {
            return;
        }

        switch (action) {
            case 'bold':
                this.modeEditor.formatBold();
                break;
            case 'italic':
                this.modeEditor.formatItalic();
                break;
            case 'strike':
                this.modeEditor.formatStrikethrough();
                break;
            case 'code':
                this.modeEditor.formatCode();
                break;
            case 'ul':
                this.modeEditor.insertUnorderedList();
                break;
            case 'ol':
                this.modeEditor.insertOrderedList();
                break;
            case 'undo':
                this.modeEditor.undo();
                break;
            case 'redo':
                this.modeEditor.redo();
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

        this.modeEditor.toggleLink(url.trim() || null);
    }

    toggleSourceMode() {
        if (!this.modeEditor) {
            return;
        }

        if (!this.sourceMode) {
            this.$source.val(this.readMarkdownBody());
            this.$el.find('.kb-lexical-editor-wrap').addClass('hidden');
            this.$source.removeClass('hidden');
            this.sourceMode = true;
            this.modeEditor.setEditable(false);

            return;
        }

        const source = this.$source.val() || '';
        const parsed = this.splitRaw(source);

        this.maybeHydrateFrontmatterFields(parsed.fields);

        this.$source.addClass('hidden');
        this.$el.find('.kb-lexical-editor-wrap').removeClass('hidden');
        this.sourceMode = false;
        this.modeEditor.setEditable(true);
        this.modeEditor.setMarkdown(parsed.body || '');
        this.trigger('change');
    }

    /**
     * @returns {string}
     */
    readMarkdownBody() {
        if (this.sourceMode) {
            return this.stripBody(this.$source.val() || '');
        }

        if (!this.modeEditor) {
            return this.stripBody(this.model.get(this.name) || '');
        }

        return this.modeEditor.getMarkdown() || '';
    }

    fetch() {
        let body = this.isEditMode() ? this.readMarkdownBody() : this.model.get(this.name);

        if (typeof body === 'string') {
            body = this.stripBody(body).replace(/^\n+/, '');
        }

        return {
            [this.name]: body || '',
        };
    }

    validateRequired() {
        if (!this.isRequired()) {
            return false;
        }

        const value = this.isEditMode() ? this.readMarkdownBody() : this.model.get(this.name);

        if (!value || !String(value).trim()) {
            const msg = this.translate('fieldIsRequired', 'messages')
                .replace('{field}', this.getLabelText());
            this.showValidationMessage(msg);

            return true;
        }

        return false;
    }
}

export default AgentModeBodyFieldView;
