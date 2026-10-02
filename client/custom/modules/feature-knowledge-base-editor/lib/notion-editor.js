import React, {useEffect, useId, useMemo, useRef, useState} from 'react';
import {createRoot} from 'react-dom/client';
import {createPortal} from 'react-dom';
import {LexicalComposerContext, createLexicalComposerContext} from '@lexical/react/LexicalComposerContext';
import {LexicalTypeaheadMenuPlugin, MenuOption, useBasicTypeaheadTriggerMatch} from '@lexical/react/LexicalTypeaheadMenuPlugin';
import {DraggableBlockPlugin_EXPERIMENTAL as DraggableBlockPlugin} from '@lexical/react/LexicalDraggableBlockPlugin';
import {autoUpdate, computePosition, offset, flip, shift, hide, size} from '@floating-ui/dom';
import {
    $getSelection, $isRangeSelection, $setSelection, $getRoot, $createParagraphNode,
    $createTextNode, $getNearestNodeFromDOMNode, FORMAT_TEXT_COMMAND, $nodesOfType, $isTextNode,
    $getNodeByKey, $isNodeSelection, COMMAND_PRIORITY_EDITOR,
} from 'lexical';
import {$setBlocksType} from '@lexical/selection';
import {$createHeadingNode, $createQuoteNode} from '@lexical/rich-text';
import {$createCodeNode} from '@lexical/code';
import {INSERT_ORDERED_LIST_COMMAND, INSERT_UNORDERED_LIST_COMMAND} from '@lexical/list';
import {INSERT_TABLE_COMMAND} from '@lexical/table';
import {TOGGLE_LINK_COMMAND, $isLinkNode} from '@lexical/link';
import {MentionNode, $createMentionNode, CONTEXT_LABELS, referenceUrl} from './mention-node';
import {
    DateSeparatorNode, OPEN_DATE_SEPARATOR_COMMAND, INSERT_DATE_SEPARATOR_COMMAND,
    localSeparatorValue, isSeparatorValue,
} from './date-separator-node';

const h = React.createElement;
const blocks = [
    ['Paragraph', () => $createParagraphNode()],
    ['Heading 1', () => $createHeadingNode('h1')],
    ['Heading 2', () => $createHeadingNode('h2')],
    ['Heading 3', () => $createHeadingNode('h3')],
    ['Quote', () => $createQuoteNode()],
    ['Code block', () => $createCodeNode()],
    ['Bullet list', INSERT_UNORDERED_LIST_COMMAND],
    ['Numbered list', INSERT_ORDERED_LIST_COMMAND],
    ['Table', INSERT_TABLE_COMMAND],
    ['Date separator', OPEN_DATE_SEPARATOR_COMMAND],
];
const formats = [['bold', 'B'], ['italic', 'I'], ['underline', 'U'], ['strikethrough', 'S̶'], ['code', '</>']];

function useEditable(editor) {
    const [editable, setEditable] = useState(editor.isEditable());
    useEffect(() => editor.registerEditableListener(setEditable), [editor]);
    return editable;
}

function selectionRange(editor) {
    const selection = window.getSelection();
    const root = editor.getRootElement();
    if (!root || !selection?.rangeCount || !root.contains(selection.anchorNode) || !root.contains(selection.focusNode)) return null;
    return selection.getRangeAt(0).cloneRange();
}

function Floating({editor, range, children, className, role, labelledBy, onKeyDown, panelRef, id}) {
    const ref = useRef(null);
    useEffect(() => {
        const element = ref.current;
        const root = editor.getRootElement();
        if (!element || !root || !range) return;
        let active = true;
        const reference = {getBoundingClientRect: () => range.getBoundingClientRect(),
            getClientRects: () => range.getClientRects(), contextElement: root};
        const update = () => computePosition(reference, element, {
            strategy: 'fixed', placement: 'bottom-start',
            middleware: [offset(10), flip(), shift({padding: 8}), size({padding: 8, apply({availableWidth, availableHeight}) {
                element.style.maxWidth = `${Math.max(0, availableWidth)}px`;
                element.style.maxHeight = `${Math.max(0, availableHeight)}px`;
            }}), hide()],
        }).then(({x, y, middlewareData}) => {
            if (!active) return;
            Object.assign(element.style, {left: `${x}px`, top: `${y}px`, visibility: middlewareData.hide?.referenceHidden ? 'hidden' : 'visible'});
        });
        const cleanup = autoUpdate(reference, element, update);
        return () => { active = false; cleanup(); };
    }, [editor, range]);
    // Keep controls inside Bootstrap's modal focus boundary.
    const parent = editor.getRootElement()?.closest('.modal') || document.body;
    return createPortal(h('div', {ref: element => {ref.current = element; if (panelRef) panelRef.current = element;},
        className: `kb-notion-popover ${className || ''}`, role, id, 'aria-label': labelledBy,
        style: {position: 'fixed', visibility: 'hidden'}, onKeyDown}, children), parent);
}

function SelectionToolbar({editor, menuOpen}) {
    const editable = useEditable(editor);
    const [state, setState] = useState(null);
    const saved = useRef(null);
    const panel = useRef(null);
    const selecting = useRef(false);
    useEffect(() => {
        const root = editor.getRootElement();
        const update = () => {
            if (panel.current?.contains(document.activeElement)) return;
            editor.getEditorState().read(() => {
                const selection = $getSelection();
                const range = selectionRange(editor);
                if (!editor.isEditable() || editor.isComposing() || selecting.current || !range ||
                    !$isRangeSelection(selection) || selection.isCollapsed() || !selection.getTextContent().trim()) {
                    setState(null);
                    return;
                }
                saved.current = selection.clone();
                setState({range, formats: Object.fromEntries(formats.map(([format]) => [format, selection.hasFormat(format)])),
                    link: $isLinkNode(selection.anchor.getNode().getParent())});
            });
        };
        const down = () => { selecting.current = true; setState(null); };
        const up = () => { selecting.current = false; update(); };
        const key = event => {
            if (event.altKey && event.key === 'F10' && panel.current) {
                event.preventDefault(); panel.current.querySelector('button')?.focus();
            }
        };
        root.addEventListener('pointerdown', down);
        root.addEventListener('keydown', key);
        document.addEventListener('pointerup', up);
        document.addEventListener('selectionchange', update);
        root.addEventListener('compositionstart', down);
        root.addEventListener('compositionend', up);
        const unregister = editor.registerUpdateListener(update);
        return () => {
            unregister(); root.removeEventListener('pointerdown', down); root.removeEventListener('keydown', key);
            document.removeEventListener('pointerup', up); document.removeEventListener('selectionchange', update);
            root.removeEventListener('compositionstart', down); root.removeEventListener('compositionend', up);
        };
    }, [editor]);
    if (!editable || menuOpen || !state) return null;
    const apply = (command, value) => {
        editor.update(() => {
            if (saved.current) $setSelection(saved.current.clone());
            editor.dispatchCommand(command, value);
        }, {discrete: true});
        editor.focus();
    };
    return h(Floating, {editor, range: state.range, role: 'toolbar', labelledBy: 'Text formatting', panelRef: panel,
        className: 'kb-selection-toolbar', onKeyDown: event => {
            if (event.key === 'Escape') { event.preventDefault(); editor.focus(); }
            if (['ArrowRight', 'ArrowLeft'].includes(event.key)) {
                event.preventDefault();
                const buttons = [...panel.current.querySelectorAll('button')];
                const index = buttons.indexOf(document.activeElement);
                buttons[(index + (event.key === 'ArrowRight' ? 1 : buttons.length - 1)) % buttons.length].focus();
            }
        }},
    ...formats.map(([format, label]) => h('button', {key: format, type: 'button', 'aria-label': format,
        title: format, 'aria-pressed': !!state.formats[format],
        'aria-keyshortcuts': {bold: 'Control+b Meta+b', italic: 'Control+i Meta+i', underline: 'Control+u Meta+u'}[format],
        onMouseDown: e => e.preventDefault(), onClick: () => apply(FORMAT_TEXT_COMMAND, format)}, label)),
    h('button', {type: 'button', 'aria-label': 'Link', 'aria-pressed': state.link, onMouseDown: e => e.preventDefault(), onClick: () => {
        const url = window.prompt('Link URL (empty to remove)', 'https://');
        if (url !== null && (!url.trim() || /^(https?:\/\/|mailto:|tel:|[#/?])/i.test(url.trim()))) apply(TOGGLE_LINK_COMMAND, url.trim() || null);
    }}, '↗'));
}

class Option extends MenuOption {
    constructor(key, label, group, action) { super(key); this.label = label; this.group = group; this.action = action; }
}

function MenuResults({editor, range, id, anchor, options, selectedIndex, selectOptionAndCleanUp, setHighlightedIndex, status}) {
    useEffect(() => {
        const root = editor.getRootElement();
        const sync = () => {
            const active = `${id}-${selectedIndex}`;
            if (selectedIndex != null && root.getAttribute('aria-activedescendant') !== active) root.setAttribute('aria-activedescendant', active);
            if (root.getAttribute('aria-controls') !== id) root.setAttribute('aria-controls', id);
            anchor.current?.setAttribute('aria-hidden', 'true');
            if (anchor.current) anchor.current.id = `${id}-anchor`;
        };
        // Lexical 0.48's global menu item prefix needs an instance-scoped ARIA target.
        const observer = new MutationObserver(sync);
        observer.observe(root, {attributes: true, attributeFilter: ['aria-activedescendant', 'aria-controls']});
        sync();
        return () => { observer.disconnect(); root.removeAttribute('aria-activedescendant'); root.removeAttribute('aria-controls'); };
    }, [editor, id, anchor, selectedIndex]);
    return h(Floating, {editor, range, id, className: 'kb-command-menu', role: 'listbox', labelledBy: 'Insert block or reference'},
        ...options.map((option, index) => h('div', {key: option.key, id: `${id}-${index}`,
            ref: element => option.setRefElement(element), role: 'option', 'aria-selected': index === selectedIndex,
            className: index === selectedIndex ? 'active' : '', onMouseDown: e => e.preventDefault(),
            onMouseEnter: () => setHighlightedIndex(index), onClick: () => selectOptionAndCleanUp(option)},
        h('small', null, option.group), h('span', null, option.label))),
        status ? h('div', {role: 'status'}, status) : !options.length ? h('div', {role: 'status'}, 'No commands') : null);
}

function Picker({editor, trigger, services, onOpenChange}) {
    const [query, setQuery] = useState(null);
    const [results, setResults] = useState([]);
    const [status, setStatus] = useState('');
    const [range, setRange] = useState(null);
    const id = useId();
    const match = useBasicTypeaheadTriggerMatch(trigger, {minLength: 0, maxLength: 60, allowWhitespace: true});
    useEffect(() => {
        if (trigger !== '@' || query === null) return;
        let current = true;
        const abort = new AbortController();
        setResults([]); setStatus('Loading…');
        const timer = setTimeout(() => {
            Promise.resolve(services.search?.(query, abort.signal) || []).then(items => {
                if (current) { setResults(items); setStatus(items.length ? '' : 'No matching records'); }
            }).catch(() => { if (current) setStatus('Search unavailable. Try again.'); });
        }, 200);
        return () => { current = false; clearTimeout(timer); abort.abort(); };
    }, [query, trigger, services]);
    const options = useMemo(() => trigger === '/' ? blocks
        .filter(([label]) => label.toLowerCase().includes((query || '').toLowerCase()))
        .map(([label, action]) => new Option(label, label, 'Blocks', action)) : [
            ...Object.entries(CONTEXT_LABELS).filter(([, label]) => label.toLowerCase().includes((query || '').toLowerCase()))
                .map(([key, label]) => new Option(key, label, 'Run context', {kind: 'context', key})),
            ...results.map(ref => new Option(referenceUrl(ref), ref.label, ref.entityType, ref)),
        ], [query, trigger, results]);
    return h(LexicalTypeaheadMenuPlugin, {
        options, triggerFn: match, onQueryChange: setQuery, anchorClassName: 'kb-typeahead-anchor',
        onOpen: () => { setRange(selectionRange(editor)); onOpenChange(true); },
        onClose: () => { setRange(null); onOpenChange(false); },
        onSelectOption: (option, queryNode, close) => {
            editor.update(() => {
                // The plugin may have split with an older query length when Enter
                // follows rapid input. Reconcile the remaining prefix at the live
                // cursor before replacing; never leave half of the trigger behind.
                const previous = queryNode?.getPreviousSibling();
                if (queryNode && !queryNode.getTextContent().startsWith(trigger) && $isTextNode(previous) && previous.isSimpleText()) {
                    const prefix = previous.getTextContent();
                    const live = match(prefix + queryNode.getTextContent(), editor);
                    if (!live) { close(); return; }
                    const keep = prefix.slice(0, live.leadOffset);
                    if (keep) previous.setTextContent(keep);
                    else previous.remove();
                }
                if (!queryNode) { close(); return; }
                if (trigger === '@') {
                    const mention = $createMentionNode(option.action);
                    queryNode.replace(mention);
                    const space = $createTextNode(' '); mention.insertAfter(space); space.selectEnd();
                } else {
                    queryNode?.remove();
                    const selection = $getSelection();
                    if (typeof option.action === 'function' && $isRangeSelection(selection)) $setBlocksType(selection, option.action);
                    else editor.dispatchCommand(option.action, option.label === 'Table' ? {rows: '3', columns: '3', includeHeaders: true} : undefined);
                }
                close();
            });
        },
        menuRenderFn: (anchor, props) => range && h(MenuResults, {editor, range, id, anchor, options, ...props, status: trigger === '@' ? status : ''}),
    });
}

function References({editor, services}) {
    useEffect(() => {
        let disposed = false;
        let generation = 0;
        let last = '';
        const cache = new Map();
        const transform = editor.registerNodeTransform(MentionNode, node => {
            const result = cache.get(referenceUrl(node.__reference));
            if (result?.available && node.__resolved?.label !== result.label) node.resolve(result);
        });
        const resolve = () => {
            const refs = editor.getEditorState().read(() => $nodesOfType(MentionNode)
                .map(node => node.__reference).filter(ref => ref.kind === 'record'));
            const unique = [...new Map(refs.map(ref => [referenceUrl(ref), ref])).values()];
            const signature = unique.map(referenceUrl).sort().join('|');
            if (signature === last) return;
            last = signature;
            const request = ++generation;
            if (!unique.length || !services.resolve) return;
            services.resolve(unique).then(results => {
                if (disposed || request !== generation) return;
                const resolved = new Map(results.map(result => [referenceUrl(result), result]));
                for (const [url, result] of resolved) cache.set(url, result);
                editor.update(() => $nodesOfType(MentionNode).forEach(node => {
                    if (node.__reference.kind === 'record') node.resolve(resolved.get(referenceUrl(node.__reference)));
                }), {tag: 'history-merge'});
            }).catch(() => { /* Unresolved nodes stay unavailable; never trust cached labels. */ });
        };
        resolve();
        const unregister = editor.registerUpdateListener(resolve);
        const root = editor.getRootElement();
        const click = event => {
            if (!event.ctrlKey && !event.metaKey) return;
            editor.getEditorState().read(() => {
                const node = $getNearestNodeFromDOMNode(event.target);
                if (node instanceof MentionNode && node.__resolved && node.__reference.kind === 'record') {
                    const ref = node.__reference;
                    window.open(`#${ref.entityType}/view/${ref.recordId}`, '_blank', 'noopener');
                }
            });
        };
        root.addEventListener('click', click);
        return () => {disposed = true; unregister(); transform(); root.removeEventListener('click', click);};
    }, [editor, services]);
    return null;
}

function DateSeparators({editor}) {
    const [state, setState] = useState(null);
    const current = useRef(null);
    const panel = useRef(null);
    const open = state !== null;
    current.current = state;
    useEffect(() => {
        const root = editor.getRootElement();
        const unregister = editor.registerCommand(OPEN_DATE_SEPARATOR_COMMAND, nodeKey => {
            if (!editor.isEditable()) return false;
            const node = nodeKey ? $getNodeByKey(nodeKey) : null;
            if (nodeKey && !(node instanceof DateSeparatorNode)) return false;
            const value = node ? node.getValue() : localSeparatorValue();
            const selection = $getSelection();
            const block = $isRangeSelection(selection) ? selection.anchor.getNode().getTopLevelElement() : null;
            setState({nodeKey, value, type: value.includes('T') ? 'datetime-local' : 'date',
                time: value.includes('T') ? value.slice(11) : localSeparatorValue(true).slice(11),
                selection: selection?.clone(),
                range: node ? editor.getElementByKey(nodeKey) : (block && editor.getElementByKey(block.getKey())) || root});
            return true;
        }, COMMAND_PRIORITY_EDITOR);
        const click = event => {
            if (!editor.isEditable() || !event.target.closest?.('.kb-date-separator-label')) return;
            const key = editor.read(() => {
                const node = $getNearestNodeFromDOMNode(event.target);
                return node instanceof DateSeparatorNode ? node.getKey() : null;
            });
            if (key) editor.dispatchCommand(OPEN_DATE_SEPARATOR_COMMAND, key);
        };
        const outside = event => {
            if (panel.current && !panel.current.contains(event.target) &&
                !root.contains(event.target.closest?.('.kb-date-separator-label'))) setState(null);
        };
        const unregisterEditable = editor.registerEditableListener(editable => { if (!editable) setState(null); });
        const unregisterUpdate = editor.registerUpdateListener(({editorState}) => {
            const key = current.current?.nodeKey;
            if (key && !editorState.read(() => $getNodeByKey(key) instanceof DateSeparatorNode)) setState(null);
        });
        root.addEventListener('click', click);
        document.addEventListener('pointerdown', outside);
        return () => {
            unregister(); unregisterEditable(); unregisterUpdate();
            root.removeEventListener('click', click); document.removeEventListener('pointerdown', outside);
        };
    }, [editor]);
    useEffect(() => { if (open) panel.current?.querySelector('input')?.focus(); }, [open]);
    if (!state) return null;
    const cancel = () => {
        if (state.selection) editor.update(() => $setSelection(state.selection.clone()));
        setState(null); editor.focus();
    };
    const save = event => {
        event.preventDefault();
        if (!isSeparatorValue(state.value) || !editor.isEditable()) return;
        editor.update(() => {
            if (state.selection) $setSelection(state.selection.clone());
            if (state.nodeKey) {
                const node = $getNodeByKey(state.nodeKey);
                if (node instanceof DateSeparatorNode) node.setValue(state.value);
            } else editor.dispatchCommand(INSERT_DATE_SEPARATOR_COMMAND, state.value);
        }, {discrete: true, tag: 'history-push'});
        setState(null); editor.focus();
    };
    return h(Floating, {editor, range: state.range, panelRef: panel, role: 'dialog', labelledBy: 'Date separator',
        className: 'kb-date-separator-picker', onKeyDown: event => {
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); cancel(); }
            if (event.key === 'Tab') {
                const controls = [...panel.current.querySelectorAll('input, select, button:not(:disabled)')];
                const target = event.shiftKey ? controls.at(-1) : controls[0];
                if (document.activeElement === (event.shiftKey ? controls[0] : controls.at(-1))) {
                    event.preventDefault(); target.focus();
                }
            }
        }},
    h('form', {onSubmit: save},
        h('strong', null, 'Date separator'),
        h('label', null, 'Display', h('select', {value: state.type, onChange: event => {
            const type = event.target.value;
            setState(previous => ({...previous, type,
                value: type === 'date' ? previous.value.slice(0, 10) : `${previous.value.slice(0, 10)}T${previous.time}`}));
        }}, h('option', {value: 'date'}, 'Date'), h('option', {value: 'datetime-local'}, 'Date and time'))),
        h('label', null, state.type === 'date' ? 'Date' : 'Date and time', h('input', {
            type: state.type, value: state.value, required: true, step: state.type === 'date' ? 1 : 60,
            min: state.type === 'date' ? '0001-01-01' : '0001-01-01T00:00',
            max: state.type === 'date' ? '9999-12-31' : '9999-12-31T23:59',
            onChange: event => {
                const value = event.target.value;
                setState(previous => ({...previous, value, time: value.includes('T') ? value.slice(11) : previous.time}));
            },
        })),
        h('div', {className: 'kb-date-separator-actions'},
            h('button', {type: 'button', className: 'btn btn-default btn-sm', onClick: cancel}, 'Cancel'),
            h('button', {type: 'submit', className: 'btn btn-primary btn-sm', disabled: !isSeparatorValue(state.value)},
                state.nodeKey ? 'Save' : 'Insert'))));
}

function Blocks({editor}) {
    const menu = useRef(null);
    const line = useRef(null);
    const target = useRef(null);
    useEffect(() => {
        const root = editor.getRootElement();
        const move = event => {
            if (!event.altKey || !event.shiftKey || !['ArrowUp', 'ArrowDown'].includes(event.key)) return;
            event.preventDefault();
            event.stopPropagation();
            editor.update(() => {
                const selection = $getSelection();
                const node = $isRangeSelection(selection) ? selection.anchor.getNode().getTopLevelElement()
                    : $isNodeSelection(selection) ? selection.getNodes()[0]?.getTopLevelElement() : null;
                if (event.key === 'ArrowUp') node?.getPreviousSibling()?.insertBefore(node);
                else node?.getNextSibling()?.insertAfter(node);
            });
        };
        // Reorder before Lexical's arrow handler can change a block selection.
        root.addEventListener('keydown', move, true);
        return () => root.removeEventListener('keydown', move, true);
    }, [editor]);
    const modify = action => editor.update(() => {
        const node = target.current && $getNearestNodeFromDOMNode(target.current)?.getTopLevelElement();
        if (!node) return;
        if (action === 'add') {
            const next = $createParagraphNode(); node.insertAfter(next); next.append($createTextNode('/')); next.selectEnd();
        } else if (action === 'up') node.getPreviousSibling()?.insertBefore(node);
        else node.getNextSibling()?.insertAfter(node);
    });
    return h(DraggableBlockPlugin, {anchorElem: editor.getRootElement().parentElement,
        menuRef: menu, targetLineRef: line, isOnMenu: element => !!element.closest('.kb-block-handle'),
        onElementChanged: element => { target.current = element; },
        menuComponent: h('div', {ref: menu, className: 'kb-block-handle'},
            h('button', {type: 'button', 'aria-label': 'Insert block below', onMouseDown: e => e.preventDefault(), onClick: () => modify('add')}, '+'),
            h('button', {type: 'button', draggable: true, 'aria-label': 'Drag block; Alt+Arrow to move',
                onKeyDown: e => { if (e.altKey && ['ArrowUp', 'ArrowDown'].includes(e.key)) { e.preventDefault(); modify(e.key === 'ArrowUp' ? 'up' : 'down'); } }}, '⠿')),
        targetLineComponent: h('div', {ref: line, className: 'kb-block-target'}),
    });
}

function Plugins({editor, services}) {
    const editable = useEditable(editor);
    const [slashOpen, setSlashOpen] = useState(false);
    const [mentionOpen, setMentionOpen] = useState(false);
    const [empty, setEmpty] = useState(false);
    useEffect(() => { if (!editable) { setSlashOpen(false); setMentionOpen(false); } }, [editable]);
    useEffect(() => {
        const update = () => setEmpty(editor.getEditorState().read(() => !$getRoot().getTextContent()));
        update(); return editor.registerUpdateListener(update);
    }, [editor]);
    return h(React.Fragment, null,
        h(References, {editor, services}),
        h(DateSeparators, {editor}),
        editable && empty ? h('div', {className: 'kb-notion-placeholder', 'aria-hidden': true}, 'Type / for commands or @ for references…') : null,
        editable ? h(React.Fragment, null,
            h(Picker, {editor, trigger: '/', services, onOpenChange: setSlashOpen}),
            h(Picker, {editor, trigger: '@', services, onOpenChange: setMentionOpen}),
            h(Blocks, {editor}), h(SelectionToolbar, {editor, menuOpen: slashOpen || mentionOpen})) : null);
}

export function mountNotionEditor(editor, theme, options) {
    const host = document.createElement('div');
    host.className = 'kb-notion-plugins';
    options.element.parentElement.appendChild(host);
    const root = createRoot(host);
    root.render(h(LexicalComposerContext.Provider, {value: [editor, createLexicalComposerContext(null, theme)]},
        h(Plugins, {editor, services: options.references || {}})));
    return () => { root.unmount(); host.remove(); };
}
