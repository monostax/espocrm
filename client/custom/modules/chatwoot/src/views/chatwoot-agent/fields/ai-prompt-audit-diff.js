/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * Custom audit view for the aiPrompt field.
 *
 * This view is ONLY used in the stream audit context (via the auditView
 * field param). It converts HTML to plain text using the same htmlToPlain
 * logic from EspoCRM's wysiwyg field, then renders a unified word-level
 * diff with deletions in red and additions in green.
 */
define('chatwoot:views/chatwoot-agent/fields/ai-prompt-audit-diff', ['view'], function (Dep) {

    /**
     * Convert HTML to plain text using the same logic as EspoCRM's
     * wysiwyg field htmlToPlain method.
     * Handles links, blockquotes, br/p/div, and nested elements.
     */
    function htmlToPlain(html) {
        if (!html) return '';

        var div = document.createElement('div');
        div.innerHTML = html;

        function processNode(node) {
            if (node.nodeType === Node.TEXT_NODE) {
                return node.nodeValue;
            }

            if (node.nodeType === Node.ELEMENT_NODE) {
                // Handle anchor tags: show text + URL
                if (node.tagName === 'A') {
                    if (node.textContent === node.href) {
                        return node.href;
                    }
                    return node.textContent + ' (' + node.href + ')';
                }

                // Handle blockquotes
                if (node.tagName === 'BLOCKQUOTE') {
                    return '> ' + node.textContent.trim();
                }

                // Handle bold
                if (node.tagName === 'B' || node.tagName === 'STRONG') {
                    return '**' + processChildren(node) + '**';
                }

                // Handle italic
                if (node.tagName === 'I' || node.tagName === 'EM') {
                    return '_' + processChildren(node) + '_';
                }

                // Handle headings
                if (/^H[1-6]$/.test(node.tagName)) {
                    var level = parseInt(node.tagName[1]);
                    var prefix = '';
                    for (var h = 0; h < level; h++) prefix += '#';
                    return '\n' + prefix + ' ' + processChildren(node) + '\n';
                }

                // Handle list items
                if (node.tagName === 'LI') {
                    return '\n- ' + processChildren(node);
                }

                // Handle unordered/ordered lists
                if (node.tagName === 'UL' || node.tagName === 'OL') {
                    return '\n' + processChildren(node) + '\n';
                }

                // Handle line-breaking elements
                var tag = node.tagName.toLowerCase();
                if (tag === 'br' || tag === 'p' || tag === 'div') {
                    return '\n' + processChildren(node) + '\n';
                }

                return processChildren(node);
            }

            return '';
        }

        function processChildren(node) {
            var result = '';
            var children = node.childNodes;
            for (var i = 0; i < children.length; i++) {
                result += processNode(children[i]);
            }
            return result;
        }

        return processNode(div).replace(/\n{3,}/g, '\n\n').trim();
    }

    /**
     * Compute a word-level diff using LCS (Longest Common Subsequence).
     * Splits by whitespace-preserving tokens for accurate diffs.
     */
    function wordDiff(oldText, newText) {
        var oldWords = oldText.split(/(\s+)/);
        var newWords = newText.split(/(\s+)/);
        var m = oldWords.length;
        var n = newWords.length;

        var dp = [];
        var i, j;
        for (i = 0; i <= m; i++) {
            dp[i] = [];
            for (j = 0; j <= n; j++) {
                if (i === 0 || j === 0) {
                    dp[i][j] = 0;
                } else if (oldWords[i - 1] === newWords[j - 1]) {
                    dp[i][j] = dp[i - 1][j - 1] + 1;
                } else {
                    dp[i][j] = Math.max(dp[i - 1][j], dp[i][j - 1]);
                }
            }
        }

        var result = [];
        i = m;
        j = n;
        while (i > 0 || j > 0) {
            if (i > 0 && j > 0 && oldWords[i - 1] === newWords[j - 1]) {
                result.unshift({ type: 'equal', value: oldWords[i - 1] });
                i--; j--;
            } else if (j > 0 && (i === 0 || dp[i][j - 1] >= dp[i - 1][j])) {
                result.unshift({ type: 'added', value: newWords[j - 1] });
                j--;
            } else {
                result.unshift({ type: 'removed', value: oldWords[i - 1] });
                i--;
            }
        }
        return result;
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    function renderDiffHtml(diffResult) {
        var html = '';
        for (var k = 0; k < diffResult.length; k++) {
            var part = diffResult[k];
            var escaped = escapeHtml(part.value);
            if (part.type === 'removed') {
                html += '<del style="background-color: #ffeef0; color: #b31d28; text-decoration: line-through;">' + escaped + '</del>';
            } else if (part.type === 'added') {
                html += '<ins style="background-color: #e6ffec; color: #22863a; text-decoration: none;">' + escaped + '</ins>';
            } else {
                html += escaped;
            }
        }
        return html;
    }

    return Dep.extend({

        templateContent: '{{{diffHtml}}}',

        setup: function () {
            Dep.prototype.setup.call(this);
            this.auditData = this.options.auditData || null;
        },

        data: function () {
            if (!this.auditData || this.auditData.type === 'was') {
                return { diffHtml: '' };
            }

            return { diffHtml: this.computeDiffHtml() };
        },

        computeDiffHtml: function () {
            // Traverse up to find the Note model with both was/became values
            var noteModel = null;
            var current = this.getParentView();
            var depth = 0;

            while (current && depth < 5) {
                if (current.model && current.model.get && current.model.get('data') &&
                    current.model.get('data').attributes) {
                    noteModel = current.model;
                    break;
                }
                current = current.getParentView ? current.getParentView() : null;
                depth++;
            }

            if (!noteModel) {
                var val = this.model.get(this.options.name) || '';
                return '<pre style="white-space: pre-wrap; font-size: 12px; margin: 0;">' +
                    escapeHtml(htmlToPlain(val)) + '</pre>';
            }

            var data = noteModel.get('data');
            var fieldName = this.options.name;
            var wasHtml = (data.attributes.was || {})[fieldName] || '';
            var becameHtml = (data.attributes.became || {})[fieldName] || '';

            // Convert HTML to markdown-like plain text
            var oldText = htmlToPlain(wasHtml);
            var newText = htmlToPlain(becameHtml);

            if (!oldText && !newText) {
                return '';
            }

            var containerStyle = 'white-space: pre-wrap; font-family: monospace; font-size: 12px; ' +
                'line-height: 1.6; padding: 8px; border-radius: 4px; border: 1px solid #e1e4e8;';

            if (!oldText) {
                return '<div style="' + containerStyle + '">' +
                    '<ins style="background-color: #e6ffec; color: #22863a; text-decoration: none;">' +
                    escapeHtml(newText) + '</ins></div>';
            }

            if (!newText) {
                return '<div style="' + containerStyle + '">' +
                    '<del style="background-color: #ffeef0; color: #b31d28; text-decoration: line-through;">' +
                    escapeHtml(oldText) + '</del></div>';
            }

            var diff = wordDiff(oldText, newText);
            var html = renderDiffHtml(diff);

            return '<div style="' + containerStyle + '">' + html + '</div>';
        },

        afterRender: function () {
            if (!this.auditData) return;

            if (this.auditData.type === 'was') {
                // Hide "was" cell and arrow, expand "became" to full width
                var $td = this.$el;
                var $row = $td.closest('tr');

                $td.hide();
                $row.find('td').eq(2).hide();
                $row.find('.cell-became').attr('colspan', 3).css('width', '70%');
            }
        },
    });
});
