import BaseFieldView from 'views/fields/base';

/**
 * AgentSkill.body — Lexical markdown (OpenCode SKILL.md instructions).
 *
 * SoT on disk is YAML frontmatter + body. CRM maps:
 *   name, description → frontmatter fields
 *   body → markdown after frontmatter (this field)
 *
 * Frontmatter is never sent into Lexical. If the user pastes a full SKILL.md
 * (with leading --- yaml ---), it is split via EspoLexical.splitFrontmatter;
 * yaml name/description may hydrate the sibling fields when empty.
 *
 * @see https://lexical.dev/docs/packages/lexical-markdown
 */
class AgentSkillBodyFieldView extends BaseFieldView {
    type = 'text'

    detailTemplateContent = `
        {{#if isNone}}
            <span class="none-value">{{translate 'None'}}</span>
        {{else}}
            <div class="html-container agent-skill-body-detail">{{{html}}}</div>
        {{/if}}
    `

    editTemplateContent = `
        <div class="kb-lexical-field agent-skill-lexical" data-name="{{name}}">
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
                Markdown body (YAML frontmatter is name + description)
            </div>
        </div>
    `

    fetchEmptyValueAsNull = false
    seeMoreDisabled = true

    /** @type {any} */
    skillEditor = null
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

        this.skillEditor = this.EspoLexical.createKbEditor({
            element,
            namespace: 'EspoAgentSkill-' + this.cid,
            onChange: () => {
                this.trigger('change');
            },
        });

        this.loadContentIntoEditor();

        const minHeight = this.params.minHeight || 280;
        this.$editorEl.css({minHeight: minHeight + 'px'});
    }

    loadContentIntoEditor() {
        if (!this.skillEditor) {
            return;
        }

        const raw = this.model.get(this.name) || '';
        const parsed = this.splitRaw(raw);

        this.maybeHydrateFrontmatterFields(parsed.fields);
        this.skillEditor.setMarkdown(parsed.body || '');
    }

    /**
     * If user pasted full SKILL.md, copy yaml name/description into empty fields.
     *
     * @param {Record<string, string>} fields
     */
    maybeHydrateFrontmatterFields(fields) {
        if (!fields || !Object.keys(fields).length) {
            return;
        }

        const patch = {};

        if (fields.name && !this.model.get('name')) {
            patch.name = fields.name;
        }

        if (fields.description && !this.model.get('description')) {
            patch.description = fields.description;
        }

        if (Object.keys(patch).length) {
            this.model.set(patch, {ui: false, skipReRender: true});
        }
    }

    destroyEditor() {
        if (this.skillEditor) {
            this.skillEditor.destroy();
            this.skillEditor = null;
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

        if (this.sourceMode || !this.skillEditor) {
            return;
        }

        switch (action) {
            case 'bold':
                this.skillEditor.formatBold();
                break;
            case 'italic':
                this.skillEditor.formatItalic();
                break;
            case 'strike':
                this.skillEditor.formatStrikethrough();
                break;
            case 'code':
                this.skillEditor.formatCode();
                break;
            case 'ul':
                this.skillEditor.insertUnorderedList();
                break;
            case 'ol':
                this.skillEditor.insertOrderedList();
                break;
            case 'undo':
                this.skillEditor.undo();
                break;
            case 'redo':
                this.skillEditor.redo();
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

        this.skillEditor.toggleLink(url.trim() || null);
    }

    toggleSourceMode() {
        if (!this.skillEditor) {
            return;
        }

        if (!this.sourceMode) {
            this.$source.val(this.readMarkdownBody());
            this.$el.find('.kb-lexical-editor-wrap').addClass('hidden');
            this.$source.removeClass('hidden');
            this.sourceMode = true;
            this.skillEditor.setEditable(false);
            return;
        }

        const source = this.$source.val() || '';
        const parsed = this.splitRaw(source);

        this.maybeHydrateFrontmatterFields(parsed.fields);

        this.$source.addClass('hidden');
        this.$el.find('.kb-lexical-editor-wrap').removeClass('hidden');
        this.sourceMode = false;
        this.skillEditor.setEditable(true);
        this.skillEditor.setMarkdown(parsed.body || '');
        this.trigger('change');
    }

    /**
     * Body only — never re-embeds YAML frontmatter into the stored field.
     * @returns {string}
     */
    readMarkdownBody() {
        if (this.sourceMode) {
            return this.stripBody(this.$source.val() || '');
        }

        if (!this.skillEditor) {
            return this.stripBody(this.model.get(this.name) || '');
        }

        return this.skillEditor.getMarkdown() || '';
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

export default AgentSkillBodyFieldView;
