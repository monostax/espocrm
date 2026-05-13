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
 * Custom field view for `funnelsToManage` on ChatwootAccountUserMembership.
 *
 * Extends the stock `link-multiple-with-columns` view to expose:
 * - A per-row `description` additionalColumn rendered as a wysiwyg-edited
 *   HTML blob.
 *
 * The stock parent only natively supports varchar/enum/bool column types
 * (link-multiple-with-columns.js:99-101, 183, 420-440). For wysiwyg we
 * inject our own UI: a stripped-text preview + an "Edit Internal Notes"
 * button that opens a modal containing `views/fields/wysiwyg`.
 *
 * Storage: link table column is `text` (description) declared via
 * `additionalColumns` in entityDefs.
 *
 * Read path: `getAgentFunnelsToManage` in drizzle.crm.app/helpers.ts strips
 * the HTML to plain text before the value reaches the AI agent — the LLM
 * never sees tags, mirroring the existing `aiPrompt` cleanup in
 * workflows/$chatwootAgent.ts.
 */
define('chatwoot:views/account-user-membership/fields/funnels-to-manage', [
    'views/fields/link-multiple-with-columns',
], function (Dep) {

    const PREVIEW_LENGTH = 80;

    /** Strip HTML tags + collapse whitespace for inline preview rendering. */
    function stripHtmlPreview(html) {
        if (!html) {
            return '';
        }

        const text = String(html)
            .replace(/<[^>]*>/g, ' ')
            .replace(/&amp;/g, '&')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&quot;/g, '"')
            .replace(/&#39;/g, "'")
            .replace(/&nbsp;/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();

        if (text.length <= PREVIEW_LENGTH) {
            return text;
        }

        return text.slice(0, PREVIEW_LENGTH).trim() + '…';
    }

    return Dep.extend({

        events: Object.assign({}, Dep.prototype.events || {}, {
            'click [data-action="editFunnelDescription"]': function (e) {
                e.preventDefault();
                e.stopPropagation();

                const id = e.currentTarget.getAttribute('data-id');

                if (!id) {
                    return;
                }

                this.openDescriptionEditor(id);
            },
        }),

        /** @inheritDoc */
        addLinkHtml: function (id, name) {
            const $el = Dep.prototype.addLinkHtml.call(this, id, name);

            if (this.isEditMode() || this.isDetailMode()) {
                this.appendDescriptionUi($el, id);
            }

            return $el;
        },

        /** @inheritDoc */
        getDetailLinkHtml: function (id, name) {
            const html = Dep.prototype.getDetailLinkHtml.call(this, id, name);

            const description = this.getColumnValue(id, 'description');
            const preview = stripHtmlPreview(description);

            if (!preview) {
                return html;
            }

            const $wrapper = $('<div>').html(html);

            $wrapper.append(
                $('<span>').text(' '),
                $('<span>').addClass('text-muted middle-dot'),
                $('<span>').text(' '),
                $('<span>')
                    .addClass('text-muted small')
                    .text(preview)
            );

            return $wrapper.get(0).innerHTML;
        },

        /**
         * Append the description preview + editor button to a freshly
         * rendered link row.
         *
         * @param {JQuery} $el The row element returned by the parent.
         * @param {string} id  The funnel id for this row.
         */
        appendDescriptionUi: function ($el, id) {
            const value = this.getColumnValue(id, 'description');
            const preview = stripHtmlPreview(value);

            const $preview = $('<span>')
                .addClass('text-muted small funnel-description-preview')
                .attr('data-id', id)
                .text(preview);

            const $button = $('<a>')
                .attr('role', 'button')
                .attr('tabindex', '0')
                .attr('data-action', 'editFunnelDescription')
                .attr('data-id', id)
                .addClass('funnel-description-edit-btn')
                .css('margin-left', '6px')
                .text(this.translate('Edit Internal Notes', 'labels', 'ChatwootAccountUserMembership'));

            const $name = $el.find('.link-item-name');

            if ($name.length) {
                $name.append(
                    $('<span>').addClass('funnel-description-meta')
                        .css('margin-left', '8px')
                        .append($preview)
                        .append($button)
                );
            } else {
                $el.append(
                    $('<div>').append($preview).append($button)
                );
            }
        },

        /**
         * Open the wysiwyg modal for a row and persist the result back
         * into the host model's `<name>Columns` attribute.
         *
         * @param {string} id The funnel id for the row being edited.
         */
        openDescriptionEditor: function (id) {
            const currentValue = this.getColumnValue(id, 'description') || '';
            const funnelName = this.nameHash[id] || '';

            this.createView('editDescriptionModal', 'chatwoot:views/account-user-membership/modals/edit-funnel-description', {
                funnelId: id,
                funnelName: funnelName,
                value: currentValue,
            }, (view) => {
                this.listenToOnce(view, 'save', (data) => {
                    if (!data || data.funnelId !== id) {
                        return;
                    }

                    this.columns[id] = this.columns[id] || {};
                    this.columns[id].description = data.value || null;

                    this.refreshDescriptionRow(id);
                    this.trigger('change');
                });

                view.render();
            });
        },

        /**
         * Re-render the per-row preview/button for one id without
         * reflowing the entire link list.
         */
        refreshDescriptionRow: function (id) {
            const $row = this.$el.find('.link-' + id);

            if (!$row.length) {
                return;
            }

            $row.find('.funnel-description-meta').remove();
            this.appendDescriptionUi($row, id);
        },

        /** @inheritDoc */
        fetch: function () {
            return Dep.prototype.fetch.call(this);
        },
    });
});
