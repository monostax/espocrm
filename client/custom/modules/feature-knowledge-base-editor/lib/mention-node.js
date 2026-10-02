import {TextNode, $applyNodeReplacement} from 'lexical';

export const ENTITY_TYPES = ['User', 'Account', 'Opportunity', 'Contact', 'KnowledgeBaseArticle', 'Document'];
export const CONTEXT_LABELS = {
    currentOpportunity: 'Current Opportunity',
    opportunityOwner: 'Opportunity owner',
    primaryContact: 'Primary contact',
};
const PREFIX = '#crm-reference/v1/';

export function validateReference(value) {
    if (value?.kind === 'record' && ENTITY_TYPES.includes(value.entityType) &&
        typeof value.recordId === 'string' && /^[a-zA-Z0-9_-]{1,64}$/.test(value.recordId)) {
        return {kind: 'record', entityType: value.entityType, recordId: value.recordId,
            label: typeof value.label === 'string' ? value.label.slice(0, 255) : ''};
    }
    if (value?.kind === 'context' && Object.hasOwn(CONTEXT_LABELS, value.key)) {
        return {kind: 'context', key: value.key};
    }
    return null;
}

export function referenceUrl(ref) {
    return PREFIX + (ref.kind === 'record' ? `record/${ref.entityType}/${ref.recordId}` : `context/${ref.key}`);
}

export function parseReferenceUrl(url, label = '') {
    if (typeof url !== 'string' || !url.startsWith(PREFIX)) return null;
    const parts = url.slice(PREFIX.length).split('/');
    if (parts[0] === 'record' && parts.length === 3) {
        return validateReference({kind: 'record', entityType: parts[1], recordId: parts[2], label});
    }
    if (parts[0] === 'context' && parts.length === 2) return validateReference({kind: 'context', key: parts[1]});
    return null;
}

/** Atomic text entity: native Lexical selection, deletion, clipboard and history. */
export class MentionNode extends TextNode {
    static getType() { return 'crm-mention'; }
    static clone(node) {
        const clone = new MentionNode(node.__reference, node.__key);
        clone.__resolved = node.__resolved;
        return clone;
    }
    constructor(reference, key) {
        const ref = validateReference(reference);
        if (!ref) throw new Error('Invalid CRM reference');
        super(ref.kind === 'record' ? ref.label || 'Unavailable reference' : CONTEXT_LABELS[ref.key], key);
        this.__reference = ref;
        this.__resolved = null;
    }
    static importJSON(json) {
        if (json.version !== 1) throw new Error('Unsupported CRM reference version');
        return $createMentionNode(json.reference).updateFromJSON(json).setMode('token');
    }
    exportJSON() { return {...super.exportJSON(), type: 'crm-mention', version: 1, reference: this.__reference}; }
    static importDOM() {
        return {a: element => {
            const ref = parseReferenceUrl(element.getAttribute('href'), element.textContent);
            return ref ? {priority: 4, conversion: () => ({node: $createMentionNode(ref)})} : null;
        }};
    }
    exportDOM() {
        const element = document.createElement('a');
        element.href = referenceUrl(this.__reference);
        element.textContent = this.__reference.kind === 'context'
            ? CONTEXT_LABELS[this.__reference.key] : this.__reference.label || 'Unavailable reference';
        return {element};
    }
    createDOM(config) {
        const element = super.createDOM(config);
        this.paint(element);
        return element;
    }
    updateDOM(previous, element, config) {
        const replace = super.updateDOM(previous, element, config);
        this.paint(element);
        return replace;
    }
    paint(element) {
        const ref = this.__reference;
        element.classList.add('kb-mention');
        element.dataset.reference = referenceUrl(ref);
        element.textContent = ref.kind === 'context' ? CONTEXT_LABELS[ref.key] : this.__resolved?.label || 'Unavailable reference';
        element.title = ref.kind === 'context' ? 'Resolved for each run' : this.__resolved ? ref.entityType : 'Unavailable reference';
        element.classList.toggle('kb-mention-unavailable', ref.kind === 'record' && !this.__resolved);
    }
    resolve(result) {
        const node = this.getWritable();
        node.__resolved = result?.available ? {label: result.label} : null;
        if (node.__resolved) {
            node.__reference = {...node.__reference, label: result.label};
            node.setTextContent(result.label);
        }
    }
    isTextEntity() { return true; }
    canInsertTextBefore() { return false; }
    canInsertTextAfter() { return false; }
}

export function $createMentionNode(ref) {
    return $applyNodeReplacement(new MentionNode(ref)).setMode('token');
}

// Normal Markdown links deliberately survive sanitizers and source-mode editing.
export const MENTION_TRANSFORMER = {
    dependencies: [MentionNode], type: 'text-match',
    regExp: /\[((?:\\.|[^\]\\])*)\]\((#crm-reference\/v1\/[^\s)]+)\)/,
    importRegExp: /\[((?:\\.|[^\]\\])*)\]\((#crm-reference\/v1\/[^\s)]+)\)/,
    trigger: ')',
    export: node => node instanceof MentionNode
        ? `[${node.getTextContent().replace(/[\[\]\\]/g, '\\$&')}](${referenceUrl(node.__reference)})` : null,
    replace: (node, match) => {
        const ref = parseReferenceUrl(match[2], match[1].replace(/\\([\\\[\]])/g, '$1'));
        if (ref) node.replace($createMentionNode(ref));
    },
};
