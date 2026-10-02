import {$createNodeSelection} from 'lexical';
import {$isTableNode, TableNode, TableRowNode, TableCellNode} from '@lexical/table';
import {$generateHtmlFromNodes, $generateNodesFromDOM} from '@lexical/html';

/** Raw HTML is valid Markdown and retains cell formatting and mention identities. */
export function tableMarkdown(editor) {
    return {
        type: 'multiline-element', dependencies: [TableNode, TableRowNode, TableCellNode],
        regExpStart: /^<table[\s>]/i,
        regExpEnd: /<\/table>\s*$/i,
        export(node) {
            if (!$isTableNode(node)) return null;
            const selection = $createNodeSelection();
            selection.add(node.getKey());
            return $generateHtmlFromNodes(editor, selection);
        },
        replace: () => false,
        handleImportAfterStartMatch({lines, rootNode, startLineIndex}) {
            let end = startLineIndex;
            while (end < lines.length && !/<\/table>\s*$/i.test(lines[end])) end++;
            if (end === lines.length) return null;
            const dom = new DOMParser().parseFromString(lines.slice(startLineIndex, end + 1).join('\n'), 'text/html');
            const nodes = $generateNodesFromDOM(editor, dom).filter($isTableNode);
            if (!nodes.length) return null;
            rootNode.append(...nodes);
            return [true, end];
        },
    };
}
