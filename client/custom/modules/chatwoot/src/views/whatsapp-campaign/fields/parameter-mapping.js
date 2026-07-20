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
 * Edit mode:    editable rows with add/remove + Contact / custom-field suggestions.
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
                '{{#if suggestionList.length}}' +
                '<div class="text-muted small" style="margin-bottom: 8px;">' +
                    'Click a field to fill the focused expression, or type Handlebars manually.' +
                '</div>' +
                '<div class="param-suggestions" style="margin-bottom: 10px; max-height: 120px; overflow-y: auto;">' +
                    '{{#each suggestionList}}' +
                    '<a role="button" class="label label-default param-suggestion-btn" ' +
                        'style="display: inline-block; margin: 0 4px 4px 0; cursor: pointer;" ' +
                        'data-expression="{{expression}}" title="{{expression}}">' +
                        '{{label}}' +
                    '</a>' +
                    '{{/each}}' +
                '</div>' +
                '{{/if}}' +
                '{{#each rows}}' +
                '<div class="row param-edit-row" data-index="{{@index}}" style="margin-bottom: 6px;">' +
                    '<div class="col-xs-3 col-sm-2">' +
                        '<input type="text" class="form-control input-sm param-key-input"' +
                            ' value="{{paramNum}}" placeholder="#">' +
                    '</div>' +
                    '<div class="col-sm-8 col-xs-7">' +
                        '<input type="text" class="form-control input-sm param-value-input"' +
                            ' value="{{expression}}" ' +
                            'placeholder="e.g. {{curlyOpen}}{{curlyOpen}}firstName{{curlyClose}}{{curlyClose}} or {{curlyOpen}}{{curlyOpen}}customFields.plan{{curlyClose}}{{curlyClose}}">' +
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
            'focus .param-value-input': function (e) {
                this._focusedValueInput = $(e.currentTarget);
            },
            'click .param-suggestion-btn': function (e) {
                e.preventDefault();
                var expression = $(e.currentTarget).attr('data-expression');
                this.applySuggestion(expression);
            },
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.suggestionList = [];

            if (this.mode === 'edit' || this.mode === 'detail') {
                this.wait(this.loadSuggestions());
            }
        },

        loadSuggestions: function () {
            var native = [
                {label: 'First Name', expression: '{{firstName}}'},
                {label: 'Last Name', expression: '{{lastName}}'},
                {label: 'Name', expression: '{{name}}'},
                {label: 'Email', expression: '{{emailAddress}}'},
                {label: 'Phone', expression: '{{phoneNumber}}'},
                {label: 'Account Name', expression: '{{account.name}}'},
            ];

            this.suggestionList = native.slice();

            var url = 'CustomField/action/templateVariables?entityType=Contact';

            return Espo.Ajax.getRequest(url)
                .then(function (response) {
                    var list = (response && response.list) || [];

                    list.forEach(function (item) {
                        var group = item.groupLabel ? item.groupLabel + ' · ' : '';
                        this.suggestionList.push({
                            label: 'CF · ' + group + (item.label || item.valueKey),
                            expression: item.expression ||
                                ('{{customFields.' + item.valueKey + '}}'),
                        });
                    }.bind(this));
                }.bind(this))
                .catch(function () {
                    // Soft-fail: keep native suggestions only.
                });
        },

        applySuggestion: function (expression) {
            if (!expression) {
                return;
            }

            var $input = this._focusedValueInput;

            if (!$input || !$input.length) {
                $input = this.$el.find('.param-value-input').last();
            }

            if (!$input || !$input.length) {
                this.addRow();
                $input = this.$el.find('.param-value-input').last();
            }

            if (!$input || !$input.length) {
                return;
            }

            $input.val(expression);
            this.trigger('change', {ui: true});
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
            data.suggestionList = this.suggestionList || [];

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
