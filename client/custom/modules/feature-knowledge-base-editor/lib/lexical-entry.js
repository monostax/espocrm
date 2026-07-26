/**
 * Lexical KB editor entry — rolled up to UMD as window.EspoLexical
 * Markdown: https://lexical.dev/docs/packages/lexical-markdown
 */
import {
    createEditor,
    $getRoot,
    $insertNodes,
    $createParagraphNode,
    $getSelection,
    FORMAT_TEXT_COMMAND,
    UNDO_COMMAND,
    REDO_COMMAND,
    COMMAND_PRIORITY_EDITOR,
} from 'lexical';
import {
    HeadingNode,
    QuoteNode,
    $createHeadingNode,
    $createQuoteNode,
    registerRichText,
} from '@lexical/rich-text';
import {
    ListItemNode,
    ListNode,
    INSERT_ORDERED_LIST_COMMAND,
    INSERT_UNORDERED_LIST_COMMAND,
    REMOVE_LIST_COMMAND,
    registerList,
} from '@lexical/list';
import {
    LinkNode,
    TOGGLE_LINK_COMMAND,
    $toggleLink,
    registerLink,
} from '@lexical/link';
import {CodeNode, CodeHighlightNode} from '@lexical/code';
import {
    TableNode,
    TableCellNode,
    TableRowNode,
    INSERT_TABLE_COMMAND,
    $createTableNodeWithDimensions,
    registerTablePlugin,
    registerTableSelectionObserver,
} from '@lexical/table';
import {$generateHtmlFromNodes, $generateNodesFromDOM} from '@lexical/html';
import {
    $convertFromMarkdownString,
    $convertToMarkdownString,
    TRANSFORMERS,
    registerMarkdownShortcuts,
} from '@lexical/markdown';
import {createEmptyHistoryState, registerHistory} from '@lexical/history';
import {mergeRegister} from '@lexical/utils';

const theme = {
    paragraph: 'kb-lex-p',
    quote: 'kb-lex-quote',
    heading: {
        h1: 'kb-lex-h1',
        h2: 'kb-lex-h2',
        h3: 'kb-lex-h3',
    },
    list: {
        ul: 'kb-lex-ul',
        ol: 'kb-lex-ol',
        listitem: 'kb-lex-li',
        nested: {
            listitem: 'kb-lex-nested-li',
        },
        listitemChecked: 'kb-lex-li-checked',
        listitemUnchecked: 'kb-lex-li-unchecked',
    },
    link: 'kb-lex-link',
    text: {
        bold: 'kb-lex-bold',
        italic: 'kb-lex-italic',
        underline: 'kb-lex-underline',
        code: 'kb-lex-code',
        strikethrough: 'kb-lex-strike',
    },
    code: 'kb-lex-codeblock',
    table: 'kb-lex-table table table-bordered',
    tableCell: 'kb-lex-td',
    tableCellHeader: 'kb-lex-th',
    tableRow: 'kb-lex-tr',
    tableSelected: 'kb-lex-table-selected',
    tableCellSelected: 'kb-lex-td-selected',
    tableSelection: 'kb-lex-table-selection',
};

function createNodes() {
    return [
        HeadingNode,
        QuoteNode,
        ListNode,
        ListItemNode,
        LinkNode,
        CodeNode,
        CodeHighlightNode,
        TableNode,
        TableCellNode,
        TableRowNode,
    ];
}

/**
 * Lexical 0.48+ registerLink expects signal-like stores (from LinkExtension),
 * not a plain options bag. Provide minimal stores so peek()/value work.
 * @see node_modules/@lexical/link registerLink(editor, stores)
 */
function createLinkStores() {
    const validateUrl = (url) => {
        if (!url || typeof url !== 'string') {
            return false;
        }

        const trimmed = url.trim();

        // Espo attachment / relative / in-app URLs
        if (
            trimmed.startsWith('?') ||
            trimmed.startsWith('/') ||
            trimmed.startsWith('#') ||
            trimmed.startsWith('mailto:') ||
            trimmed.startsWith('tel:')
        ) {
            return true;
        }

        try {
            // eslint-disable-next-line no-new
            new URL(trimmed);
            return true;
        } catch (e) {
            return false;
        }
    };

    return {
        validateUrl: {
            peek: () => validateUrl,
            get value() {
                return validateUrl;
            },
        },
        attributes: {
            peek: () => undefined,
            get value() {
                return undefined;
            },
        },
    };
}

/**
 * Fallback link command registration if registerLink signature changes again.
 */
function registerLinkFallback(editor) {
    return editor.registerCommand(
        TOGGLE_LINK_COMMAND,
        (payload) => {
            if (payload === null) {
                $toggleLink(null);
                return true;
            }

            if (typeof payload === 'string') {
                $toggleLink(payload);
                return true;
            }

            if (payload && typeof payload === 'object') {
                const {url, target, rel, title} = payload;
                $toggleLink(url, {rel, target, title});
                return true;
            }

            return false;
        },
        COMMAND_PRIORITY_EDITOR
    );
}

/**
 * Minimal signal store for Lexical 0.48 plugins that call .peek().
 * @param {*} initial
 */
function createSignal(initial) {
    let value = initial;

    return {
        peek: () => value,
        get value() {
            return value;
        },
        set(next) {
            value = next;
        },
    };
}

/**
 * Fallback INSERT_TABLE if registerTablePlugin signature changes.
 */
function registerTableFallback(editor) {
    return editor.registerCommand(
        INSERT_TABLE_COMMAND,
        (payload) => {
            if (!payload) {
                return false;
            }

            const rows = Number(payload.rows) || 0;
            const columns = Number(payload.columns) || 0;

            if (rows < 1 || columns < 1) {
                return false;
            }

            const includeHeaders =
                payload.includeHeaders === undefined ? true : payload.includeHeaders;

            const tableNode = $createTableNodeWithDimensions(rows, columns, includeHeaders);
            $insertNodes([tableNode, $createParagraphNode()]);

            return true;
        },
        COMMAND_PRIORITY_EDITOR
    );
}

/**
 * @param {object} options
 * @param {HTMLElement} options.element
 * @param {string} [options.namespace]
 * @param {() => void} [options.onChange]
 * @param {boolean} [options.editable]
 * @param {boolean} [options.markdownShortcuts=true]
 */
function createKbEditor(options) {
    const {
        element,
        namespace = 'EspoKB',
        onChange,
        editable = true,
        markdownShortcuts = true,
    } = options;

    const editor = createEditor({
        namespace,
        theme,
        editable,
        nodes: createNodes(),
        onError(error) {
            console.error('[EspoLexical]', error);
        },
    });

    editor.setRootElement(element);

    const historyState = createEmptyHistoryState();

    let linkUnregister;
    try {
        linkUnregister = registerLink(editor, createLinkStores());
    } catch (e) {
        console.warn('[EspoLexical] registerLink failed, using fallback', e);
        linkUnregister = registerLinkFallback(editor);
    }

    let tableUnregister;
    try {
        // Lexical 0.48: hasNestedTables is a signal store with .peek()
        tableUnregister = mergeRegister(
            registerTablePlugin(editor, {
                hasNestedTables: createSignal(false),
            }),
            registerTableSelectionObserver(editor, true)
        );
    } catch (e) {
        console.warn('[EspoLexical] registerTablePlugin failed, using fallback', e);
        tableUnregister = registerTableFallback(editor);
    }

    const unregisters = [
        registerRichText(editor),
        registerList(editor),
        linkUnregister,
        tableUnregister,
        registerHistory(editor, historyState, 300),
        editor.registerUpdateListener(() => {
            if (typeof onChange === 'function') {
                onChange();
            }
        }),
    ];

    // https://lexical.dev/docs/packages/lexical-markdown#shortcuts
    if (markdownShortcuts) {
        unregisters.push(registerMarkdownShortcuts(editor, TRANSFORMERS));
    }

    const unregister = mergeRegister(...unregisters);

    return {
        editor,
        destroy() {
            unregister();
            editor.setRootElement(null);
        },
        setEditable(value) {
            editor.setEditable(!!value);
        },
        /** Import HTML (legacy Summernote / Html projection). */
        setHtml(html) {
            editor.update(() => {
                const root = $getRoot();
                root.clear();

                const source = (html || '').trim();

                if (!source) {
                    root.append($createParagraphNode());
                    return;
                }

                const parser = new DOMParser();
                const dom = parser.parseFromString(source, 'text/html');
                const nodes = $generateNodesFromDOM(editor, dom);

                if (!nodes.length) {
                    root.append($createParagraphNode());
                    return;
                }

                $insertNodes(nodes);
            });
        },
        getHtml() {
            let html = '';
            editor.getEditorState().read(() => {
                html = $generateHtmlFromNodes(editor, null);
            });

            if (html === '<p><br></p>' || html === '<p></p>') {
                return '';
            }

            return html;
        },
        // https://lexical.dev/docs/packages/lexical-markdown#import-and-export
        // Frontmatter is stripped before import (remark-frontmatter style).
        setMarkdown(markdown) {
            const body = stripFrontmatter(markdown || '');
            editor.update(() => {
                $convertFromMarkdownString(body, TRANSFORMERS);
            });
        },
        getMarkdown() {
            let md = '';
            editor.getEditorState().read(() => {
                md = $convertToMarkdownString(TRANSFORMERS);
            });
            return md;
        },
        /**
         * Canonical Lexical editor state JSON (mentions / custom nodes later).
         * @returns {string|null}
         */
        getEditorStateJSON() {
            try {
                return JSON.stringify(editor.getEditorState().toJSON());
            } catch (e) {
                console.error('[EspoLexical] getEditorStateJSON', e);
                return null;
            }
        },
        /**
         * @param {string|object|null} stateJSON
         * @returns {boolean}
         */
        setEditorStateJSON(stateJSON) {
            if (!stateJSON) {
                return false;
            }

            try {
                const parsed = typeof stateJSON === 'string' ? JSON.parse(stateJSON) : stateJSON;
                editor.setEditorState(editor.parseEditorState(parsed));
                return true;
            } catch (e) {
                console.error('[EspoLexical] setEditorStateJSON', e);
                return false;
            }
        },
        isEmpty() {
            let empty = true;
            editor.getEditorState().read(() => {
                empty = $getRoot().getTextContent().trim() === '';
            });
            return empty;
        },
        focus() {
            editor.focus();
        },
        formatBold() {
            editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'bold');
        },
        formatItalic() {
            editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'italic');
        },
        formatUnderline() {
            editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'underline');
        },
        formatStrikethrough() {
            editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'strikethrough');
        },
        formatCode() {
            editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'code');
        },
        insertUnorderedList() {
            editor.dispatchCommand(INSERT_UNORDERED_LIST_COMMAND, undefined);
        },
        insertOrderedList() {
            editor.dispatchCommand(INSERT_ORDERED_LIST_COMMAND, undefined);
        },
        removeList() {
            editor.dispatchCommand(REMOVE_LIST_COMMAND, undefined);
        },
        undo() {
            editor.dispatchCommand(UNDO_COMMAND, undefined);
        },
        redo() {
            editor.dispatchCommand(REDO_COMMAND, undefined);
        },
        toggleLink(url) {
            editor.dispatchCommand(TOGGLE_LINK_COMMAND, url ? url : null);
        },
        /**
         * @param {{rows?: number|string, columns?: number|string, includeHeaders?: boolean}|number} [rowsOrOpts]
         * @param {number|string} [columns]
         */
        insertTable(rowsOrOpts = 3, columns = 3) {
            let rows = 3;
            let cols = 3;
            let includeHeaders = true;

            if (rowsOrOpts && typeof rowsOrOpts === 'object') {
                rows = Number(rowsOrOpts.rows) || 3;
                cols = Number(rowsOrOpts.columns) || 3;
                if (rowsOrOpts.includeHeaders !== undefined) {
                    includeHeaders = !!rowsOrOpts.includeHeaders;
                }
            } else {
                rows = Number(rowsOrOpts) || 3;
                cols = Number(columns) || 3;
            }

            rows = Math.min(Math.max(rows, 1), 20);
            cols = Math.min(Math.max(cols, 1), 12);

            editor.dispatchCommand(INSERT_TABLE_COMMAND, {
                rows: String(rows),
                columns: String(cols),
                includeHeaders,
            });
        },
        insertHeading(tag) {
            editor.update(() => {
                const selection = $getSelection();
                if (!selection) {
                    return;
                }
                selection.insertNodes([$createHeadingNode(tag || 'h2')]);
            });
        },
        insertQuote() {
            editor.update(() => {
                const selection = $getSelection();
                if (!selection) {
                    return;
                }
                selection.insertNodes([$createQuoteNode()]);
            });
        },
    };
}

/**
 * YAML frontmatter before Lexical markdown.
 * Mirrors backend $Skills/frontmatter.ts + remark-frontmatter style split:
 * leading `---` … `---` is peeled off; Lexical only sees the body.
 *
 * @param {string} raw
 * @returns {{ frontmatter: string|null, yaml: string, body: string, fields: Record<string, string> }}
 */
function splitFrontmatter(raw) {
    const content = String(raw || '').replace(/^\uFEFF/, '');
    const match = content.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n?([\s\S]*)$/);

    if (!match) {
        return {
            frontmatter: null,
            yaml: '',
            body: content,
            fields: {},
        };
    }

    const yaml = match[1] ?? '';
    const body = (match[2] ?? '').replace(/^\n/, '');
    const fields = {};

    for (const line of yaml.split(/\r?\n/)) {
        const kv = line.match(/^([A-Za-z0-9_-]+)\s*:\s*(.*)$/);

        if (!kv) {
            continue;
        }

        let value = (kv[2] || '').trim();

        if (
            (value.startsWith('"') && value.endsWith('"')) ||
            (value.startsWith("'") && value.endsWith("'"))
        ) {
            value = value.slice(1, -1);
        }

        fields[kv[1]] = value;
    }

    return {
        frontmatter: `---\n${yaml}\n---`,
        yaml,
        body,
        fields,
    };
}

/**
 * @param {string} raw
 * @returns {string} markdown body without leading YAML frontmatter
 */
function stripFrontmatter(raw) {
    return splitFrontmatter(raw).body;
}

/**
 * Build SKILL.md-style document. Frontmatter stays outside Lexical.
 *
 * @param {{name?: string, description?: string, fields?: Record<string, string>, body: string}} input
 * @returns {string}
 */
function joinFrontmatter(input) {
    const body = String(input.body || '').replace(/^\uFEFF/, '').replace(/^\n+/, '');
    const fields = {...(input.fields || {})};

    if (input.name != null && input.name !== '') {
        fields.name = input.name;
    }

    if (input.description != null) {
        fields.description = input.description;
    }

    const keys = Object.keys(fields);

    if (!keys.length) {
        return body;
    }

    const escapeYamlDoubleQuoted = (value) =>
        String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');

    const lines = keys.map((key) => {
        const value = fields[key] == null ? '' : String(fields[key]);
        const needsQuote = /[:#{}[\],&*?|>!%@`]|^\s|\s$|"|'|\n/.test(value);

        return needsQuote
            ? `${key}: "${escapeYamlDoubleQuoted(value)}"`
            : `${key}: ${value}`;
    });

    return (
        `---\n` +
        `${lines.join('\n')}\n` +
        `---\n\n` +
        `${body.endsWith('\n') || body === '' ? body : `${body}\n`}`
    );
}

/**
 * MD → HTML via the same TRANSFORMERS used in-editor.
 */
function markdownToHtml(markdown) {
    if (!markdown) {
        return '';
    }

    const hold = document.createElement('div');
    hold.style.display = 'none';
    document.body.appendChild(hold);

    try {
        const tmp = createKbEditor({
            element: hold,
            editable: false,
            markdownShortcuts: false,
        });
        tmp.setMarkdown(markdown);
        const html = tmp.getHtml();
        tmp.destroy();
        return html;
    } finally {
        hold.remove();
    }
}

function htmlToMarkdown(html) {
    if (!html) {
        return '';
    }

    const hold = document.createElement('div');
    hold.style.display = 'none';
    document.body.appendChild(hold);

    try {
        const tmp = createKbEditor({
            element: hold,
            editable: false,
            markdownShortcuts: false,
        });
        tmp.setHtml(html);
        const md = tmp.getMarkdown();
        tmp.destroy();
        return md;
    } finally {
        hold.remove();
    }
}

const EspoLexical = {
    createKbEditor,
    markdownToHtml,
    htmlToMarkdown,
    splitFrontmatter,
    stripFrontmatter,
    joinFrontmatter,
    TRANSFORMERS,
};

export default EspoLexical;
