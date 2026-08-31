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
 * Free-text message body for WhatsAppCampaign (messageMode = FreeText).
 *
 * Used by QR Code (WAHA) inboxes, which cannot send Meta templates, and by
 * Cloud API inboxes sending inside the 24h customer service window.
 *
 * The whole body is a Handlebars template evaluated against the recipient's
 * Contact at send time (see ProcessWhatsAppCampaignChunk::renderBodyForContact),
 * so it supports the same expressions as parameterMapping — native Contact
 * fields, Contact customFields, and one-hop belongsTo customFields.
 *
 * Token chips are borrowed from the parameter-mapping field rather than
 * duplicated: both fields resolve against the same Contact host, so the
 * suggestion set must stay identical by construction.
 *
 * The base textarea template is intentionally left untouched (auto-height,
 * maxlength and resize behaviour live there); chips are appended after render.
 */
define('chatwoot:views/whatsapp-campaign/fields/message-body', [
    'views/fields/text',
    'chatwoot:views/whatsapp-campaign/fields/parameter-mapping',
], function (Dep, ParameterMapping) {

    return Dep.extend({

        rowsMin: 5,

        /**
         * Shared with parameter-mapping: native Contact chips + custom-field
         * leaf chips for Contact and one-hop belongsTo CF-enabled entities.
         */
        loadSuggestions: ParameterMapping.prototype.loadSuggestions,

        setup: function () {
            Dep.prototype.setup.call(this);

            this.suggestionList = [];

            if (this.mode === 'edit') {
                this.wait(this.loadSuggestions());
            }
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (!this.isEditMode()) {
                return;
            }

            this.renderTokenChips();
        },

        renderTokenChips: function () {
            const list = this.suggestionList || [];

            if (!list.length) {
                return;
            }

            this.$el.find('.body-token-suggestions').remove();

            const $block = $('<div>')
                .addClass('body-token-suggestions')
                .css('margin-top', '8px');

            $('<div>')
                .addClass('text-muted small')
                .css('margin-bottom', '4px')
                .text(this.translate('insertToken', 'messages', 'WhatsAppCampaign'))
                .appendTo($block);

            const $chips = $('<div>')
                .css({'max-height': '130px', 'overflow-y': 'auto'})
                .appendTo($block);

            // Built via jQuery (not string concat) so labels and expressions
            // coming from custom-field metadata are escaped by construction.
            list.forEach(item => {
                if (!item || !item.expression) {
                    return;
                }

                $('<button>')
                    .attr('type', 'button')
                    .addClass('btn btn-default btn-xs body-token')
                    .css('margin', '0 4px 4px 0')
                    .attr('title', item.expression)
                    .data('expression', item.expression)
                    .text(item.label || item.expression)
                    .appendTo($chips);
            });

            $chips.find('.body-token').on('click', e => {
                e.preventDefault();

                this.insertToken($(e.currentTarget).data('expression'));
            });

            this.$el.append($block);
        },

        /**
         * Insert at the caret so tokens can be dropped mid-sentence; falls
         * back to appending when the textarea was never focused.
         */
        insertToken: function (expression) {
            if (!expression) {
                return;
            }

            const $textarea = this.$element;

            if (!$textarea || !$textarea.length) {
                return;
            }

            const el = $textarea.get(0);
            const current = $textarea.val() || '';
            const start = typeof el.selectionStart === 'number' ? el.selectionStart : current.length;
            const end = typeof el.selectionEnd === 'number' ? el.selectionEnd : current.length;

            $textarea.val(current.slice(0, start) + expression + current.slice(end));

            const caret = start + expression.length;

            el.focus();
            el.setSelectionRange(caret, caret);

            // Route through the DOM event the base field binds, so the record
            // view marks itself dirty exactly as it would on manual typing.
            $textarea.trigger('change');

            if (typeof this.controlTextareaHeight === 'function') {
                this.controlTextareaHeight();
            }
        },
    });
});
