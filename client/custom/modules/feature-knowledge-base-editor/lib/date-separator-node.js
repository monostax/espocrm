import {
    DecoratorNode, $applyNodeReplacement, $createParagraphNode, $isParagraphNode,
    $nodesOfType, $getSelection, $isRangeSelection, createCommand, COMMAND_PRIORITY_EDITOR,
} from 'lexical';
import {$insertNodeToNearestRoot, mergeRegister} from '@lexical/utils';
import {$generateNodesFromDOM} from '@lexical/html';

export const INSERT_DATE_SEPARATOR_COMMAND = createCommand('INSERT_DATE_SEPARATOR_COMMAND');
export const OPEN_DATE_SEPARATOR_COMMAND = createCommand('OPEN_DATE_SEPARATOR_COMMAND');

/** Keep calendar dates and local date-times intact, without UTC conversion. */
export function localSeparatorValue(includeTime = false) {
    const now = new Date();
    const pad = value => String(value).padStart(2, '0');
    const date = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
    return includeTime ? `${date}T${pad(now.getHours())}:${pad(now.getMinutes())}` : date;
}

export function isSeparatorValue(value) {
    if (typeof value !== 'string') return false;
    const match = /^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?$/.exec(value);
    if (!match) return false;
    const [year, month, day, hour, minute] = match.slice(1).map(Number);
    const leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
    const days = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    return year > 0 && month >= 1 && month <= 12 && day >= 1 && day <= days[month - 1] &&
        (match[4] === undefined || (hour <= 23 && minute <= 59));
}

function separatorLocale(locale) {
    try {
        return new Intl.DateTimeFormat((locale || document.documentElement.lang || navigator.language).replace(/_/g, '-'))
            .resolvedOptions().locale;
    } catch (e) {
        return new Intl.DateTimeFormat().resolvedOptions().locale;
    }
}

export function formatSeparatorDate(value, locale) {
    // Format wall-clock values independently of the viewer's timezone and DST.
    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'long', timeZone: 'UTC', ...(value.includes('T') ? {timeStyle: 'short'} : {}),
    }).format(new Date(value.includes('T') ? `${value}:00Z` : `${value}T12:00:00Z`));
}

/** An atomic, full-width block; the picker lives outside the editable DOM. */
export class DateSeparatorNode extends DecoratorNode {
    static getType() { return 'date-separator'; }
    static clone(node) { return new DateSeparatorNode(node.__value, node.__locale, node.__key); }
    constructor(value = localSeparatorValue(), locale, key) {
        super(key);
        if (!isSeparatorValue(value)) throw new Error('Invalid date separator value');
        this.__value = value;
        this.__locale = separatorLocale(locale);
    }
    static importJSON(json) {
        if (json.version !== 1) throw new Error('Unsupported date separator version');
        return $createDateSeparatorNode(json.value, json.locale).updateFromJSON(json);
    }
    exportJSON() {
        return {...super.exportJSON(), type: 'date-separator', version: 1, value: this.__value, locale: this.__locale};
    }
    static importDOM() {
        return {div: element => {
            if (!element.hasAttribute('data-date-separator') && !element.classList.contains('kb-date-separator')) return null;
            const value = element.querySelector('time')?.getAttribute('datetime');
            return isSeparatorValue(value) ? {priority: 4, conversion: () => ({
                node: $createDateSeparatorNode(value, element.lang), after: () => [],
            })} : null;
        }};
    }
    exportDOM() {
        const element = document.createElement('div');
        element.className = 'kb-date-separator';
        element.setAttribute('data-date-separator', '');
        element.lang = this.__locale;
        element.setAttribute('role', 'separator');
        element.setAttribute('aria-label', this.getTextContent());
        const time = document.createElement('time');
        time.className = 'kb-date-separator-label';
        time.dateTime = this.__value;
        time.textContent = this.getTextContent();
        element.appendChild(time);
        return {element};
    }
    createDOM(config, editor) {
        const element = document.createElement('div');
        element.className = 'kb-date-separator';
        element.setAttribute('data-date-separator', '');
        element.contentEditable = 'false';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'kb-date-separator-label';
        button.disabled = !editor.isEditable();
        button.appendChild(document.createElement('time'));
        element.appendChild(button);
        this.paint(element);
        return element;
    }
    updateDOM(previous, element) {
        if (previous.__value !== this.__value || previous.__locale !== this.__locale) this.paint(element);
        return false;
    }
    paint(element) {
        const label = this.getTextContent();
        element.lang = this.__locale;
        const time = element.querySelector('time');
        time.dateTime = this.__value;
        time.textContent = label;
        element.querySelector('button').setAttribute('aria-label', `Edit date separator: ${label}`);
    }
    getValue() { return this.getLatest().__value; }
    setValue(value) {
        if (!isSeparatorValue(value)) throw new Error('Invalid date separator value');
        this.getWritable().__value = value;
    }
    getTextContent() { return formatSeparatorDate(this.getLatest().__value, this.getLatest().__locale); }
    isInline() { return false; }
}

export function $createDateSeparatorNode(value, locale) {
    return $applyNodeReplacement(new DateSeparatorNode(value, locale));
}

export function registerDateSeparators(editor, locale) {
    const refresh = () => editor.getEditorState().read(() => {
        for (const node of $nodesOfType(DateSeparatorNode)) {
            const element = editor.getElementByKey(node.getKey());
            if (!element) continue;
            element.querySelector('button').disabled = !editor.isEditable();
            element.classList.toggle('kb-date-separator-selected', node.isSelected());
        }
    });
    return mergeRegister(
        editor.registerCommand(INSERT_DATE_SEPARATOR_COMMAND, value => {
            if (!editor.isEditable() || !isSeparatorValue(value)) return false;
            const selection = $getSelection();
            const block = $isRangeSelection(selection) && selection.isCollapsed()
                ? selection.anchor.getNode().getTopLevelElement() : null;
            const node = $createDateSeparatorNode(value, locale);
            if ($isParagraphNode(block) && block.isEmpty()) block.replace(node);
            else $insertNodeToNearestRoot(node);
            let next = node.getNextSibling();
            if (!$isParagraphNode(next) || !next.isEmpty()) {
                next = $createParagraphNode();
                node.insertAfter(next);
            }
            next.selectStart();
            return true;
        }, COMMAND_PRIORITY_EDITOR),
        editor.registerUpdateListener(refresh),
        editor.registerEditableListener(refresh)
    );
}

/** Raw HTML is valid Markdown and preserves the selected date, time and locale. */
export function dateSeparatorMarkdown(editor) {
    return {
        type: 'multiline-element', dependencies: [DateSeparatorNode],
        regExpStart: /^<div\b[^>]*\bdata-date-separator(?:=|\s|>)/i,
        regExpEnd: /<\/div>\s*$/i,
        export: node => node instanceof DateSeparatorNode ? node.exportDOM().element.outerHTML : null,
        replace: () => false,
        handleImportAfterStartMatch({lines, rootNode, startLineIndex}) {
            let end = startLineIndex;
            while (end < lines.length && !/<\/div>\s*$/i.test(lines[end])) end++;
            if (end === lines.length) return null;
            const dom = new DOMParser().parseFromString(lines.slice(startLineIndex, end + 1).join('\n'), 'text/html');
            const nodes = $generateNodesFromDOM(editor, dom).filter(node => node instanceof DateSeparatorNode);
            if (!nodes.length) return null;
            rootNode.append(...nodes);
            return [true, end];
        },
    };
}
