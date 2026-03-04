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
 * Structured key-value view for the parameterMapping field on WhatsAppCampaign.
 *
 * Detail mode:  table with "Parameter #" and "Contact Field" columns.
 * Edit mode:    editable rows with add/remove controls.
 * List mode:    compact summary like "3 params".
 */
define('chatwoot:views/whatsapp-campaign/fields/parameter-mapping', ['views/fields/base'], function (Dep) {

    return Dep.extend({

        // language=Handlebars
        listTemplateContent:
            '{{#if isEmpty}}' +
                '<span class="none-value">{{translate "None"}}</span>' +
            '{{else}}' +
                '<span class="text-muted">{{summary}}</span>' +
            '{{/if}}',

        // language=Handlebars
        detailTemplateContent:
            '{{#if isEmpty}}' +
                '<span class="none-value">{{translate "None"}}</span>' +
            '{{else}}' +
            '<table class="table table-bordered table-condensed" style="margin-bottom: 0;">' +
                '<thead>' +
                    '<tr>' +
                        '<th style="width: 120px;">Parameter</th>' +
                        '<th>Contact Field</th>' +
                    '</tr>' +
                '</thead>' +
                '<tbody>' +
                    '{{#each rows}}' +
                    '<tr>' +
                        '<td><code>{{curlyOpen}}{{paramNum}}{{curlyClose}}</code></td>' +
                        '<td><code>{{expression}}</code></td>' +
                    '</tr>' +
                    '{{/each}}' +
                '</tbody>' +
            '</table>' +
            '{{/if}}',

        // language=Handlebars
        editTemplateContent:
            '<div class="parameter-mapping-edit">' +
                '{{#each rows}}' +
                '<div class="row param-edit-row" data-index="{{@index}}" style="margin-bottom: 6px;">' +
                    '<div class="col-xs-3 col-sm-2">' +
                        '<input type="text" class="form-control input-sm param-key-input"' +
                            ' value="{{paramNum}}" placeholder="#">' +
                    '</div>' +
                    '<div class="col-xs-7 col-sm-8">' +
                        '<input type="text" class="form-control input-sm param-value-input"' +
                            ' value="{{expression}}" placeholder="e.g. {{curlyOpen}}{{curlyOpen}}firstName{{curlyClose}}{{curlyClose}}">' +
                    '</div>' +
                    '<div class="col-xs-2 col-sm-2">' +
                        '<a role="button" class="btn btn-link btn-sm param-remove-btn" data-index="{{@index}}"' +
                            ' title="Remove">' +
                            '<span class="fas fa-times text-danger"></span>' +
                        '</a>' +
                    '</div>' +
                '</div>' +
                '{{/each}}' +
                '<div style="margin-top: 4px;">' +
                    '<a role="button" class="btn btn-default btn-sm param-add-btn">' +
                        '<span class="fas fa-plus"></span> Add Parameter' +
                    '</a>' +
                '</div>' +
            '</div>',

        events: {
            'click .param-add-btn': function () {
                this.addRow();
            },
            'click .param-remove-btn': function (e) {
                var index = parseInt($(e.currentTarget).attr('data-index'), 10);
                this.removeRow(index);
            },
            'change .param-key-input': function () {
                this.trigger('change', {ui: true});
            },
            'change .param-value-input': function () {
                this.trigger('change', {ui: true});
            },
        },

        data: function () {
            var data = Dep.prototype.data.call(this);
            var mapping = this.getMappingObject();
            var keys = Object.keys(mapping).sort(function (a, b) {
                return parseInt(a) - parseInt(b);
            });

            data.isEmpty = keys.length === 0;
            data.summary = keys.length + ' param' + (keys.length !== 1 ? 's' : '');
            data.curlyOpen = '{{';
            data.curlyClose = '}}';

            data.rows = keys.map(function (key) {
                return {
                    paramNum: key,
                    expression: mapping[key],
                    curlyOpen: '{{',
                    curlyClose: '}}',
                };
            });

            if (this.isEditMode() && data.rows.length === 0) {
                data.rows = [];
            }

            return data;
        },

        getMappingObject: function () {
            var value = this.model.get(this.name);

            if (!value) {
                return {};
            }

            if (typeof value === 'string') {
                try {
                    value = JSON.parse(value);
                } catch (e) {
                    return {};
                }
            }

            if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
                return value;
            }

            return {};
        },

        isEditMode: function () {
            return this.mode === 'edit';
        },

        addRow: function () {
            var mapping = this.fetchMapping();
            var nextNum = 1;

            Object.keys(mapping).forEach(function (k) {
                var n = parseInt(k);
                if (!isNaN(n) && n >= nextNum) {
                    nextNum = n + 1;
                }
            });

            mapping[String(nextNum)] = '';
            this.model.set(this.name, mapping);
            this.reRender();
        },

        removeRow: function (index) {
            var mapping = this.fetchMapping();
            var keys = Object.keys(mapping).sort(function (a, b) {
                return parseInt(a) - parseInt(b);
            });

            if (keys[index] !== undefined) {
                delete mapping[keys[index]];
            }

            var hasAny = Object.keys(mapping).length > 0;
            this.model.set(this.name, hasAny ? mapping : null);
            this.reRender();
        },

        fetchMapping: function () {
            var mapping = {};

            this.$el.find('.param-edit-row').each(function (i, el) {
                var key = $(el).find('.param-key-input').val().trim();
                var value = $(el).find('.param-value-input').val().trim();

                if (key) {
                    mapping[key] = value;
                }
            });

            return mapping;
        },

        fetch: function () {
            var data = {};
            var mapping = this.fetchMapping();
            var hasAny = Object.keys(mapping).some(function (k) {
                return mapping[k] !== '';
            });

            data[this.name] = hasAny ? mapping : null;
            return data;
        },
    });
});
