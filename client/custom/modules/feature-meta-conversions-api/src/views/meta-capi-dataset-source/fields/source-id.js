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
 * Custom field view for the `sourceId` field on MetaCapiDatasetSource.
 *
 * In edit mode it renders a <select> populated with the messaging sources
 * reachable through the selected OAuthAccount, scoped by the `channel` field:
 *   - whatsapp  → GET /WhatsAppBusinessAccount?oAuthAccountId=… (items.wabaId)
 *   - instagram → GET /InstagramBusinessAccount?oAuthAccountId=… (items.instagramId)
 *
 * Selecting an OAuthAccount or changing channel reloads the list; selecting a
 * source sets sourceId + sourceName on the model.
 *
 * In detail/list mode it falls back to a plain label showing sourceName.
 */
define('feature-meta-conversions-api:views/meta-capi-dataset-source/fields/source-id', ['views/fields/varchar'], function (Dep) {

    const CHANNEL_CONFIG = {
        whatsapp: { entity: 'WhatsAppBusinessAccount', idKey: 'wabaId', nameKey: 'name' },
        instagram: { entity: 'InstagramBusinessAccount', idKey: 'instagramId', nameKey: 'username' },
    };

    return Dep.extend({

        editTemplateContent:
            '<select class="main-element form-control" data-name="{{name}}">' +
                '<option value="">{{selectPlaceholder}}</option>' +
                '{{#each sourceOptions}}' +
                '<option value="{{sourceId}}" data-source-name="{{name}}"' +
                    '{{#if selected}} selected{{/if}}>{{label}}</option>' +
                '{{/each}}' +
            '</select>' +
            '{{#if isLoading}}' +
            '<span class="text-muted small source-loading">' +
                '<span class="fas fa-spinner fa-spin"></span> Loading accounts...' +
            '</span>' +
            '{{/if}}' +
            '{{#if loadError}}' +
            '<span class="text-danger small source-error">' +
                '<span class="fas fa-exclamation-triangle"></span> {{loadError}}' +
            '</span>' +
            '{{/if}}' +
            '{{#unless oAuthAccountId}}' +
            '<span class="text-muted small">Select a Meta account first.</span>' +
            '{{/unless}}',

        detailTemplateContent:
            '{{#if sourceName}}' +
            '<span>{{sourceName}}</span>' +
            '{{else}}' +
            '{{#if value}}' +
            '<span>{{value}}</span>' +
            '{{else}}' +
            '<span class="none-value">{{translate \'None\'}}</span>' +
            '{{/if}}' +
            '{{/if}}',

        sourceOptions: null,
        isLoading: false,
        loadError: null,
        currentRequest: null,

        data: function () {
            const data = Dep.prototype.data.call(this);

            data.sourceOptions = this.sourceOptions || [];
            data.isLoading = this.isLoading;
            data.loadError = this.loadError;
            data.sourceName = this.model.get('sourceName');
            data.oAuthAccountId = this.model.get('oAuthAccountId');
            data.selectPlaceholder = this.isLoading ? 'Loading...' : 'Select an account';

            return data;
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.sourceOptions = [];
            this.isLoading = false;
            this.loadError = null;

            this.listenTo(this.model, 'change:oAuthAccountId change:channel', () => {
                if (this.isEditMode()) {
                    this.model.set('sourceId', null);
                    this.model.set('sourceName', null);
                    this.fetchSources();
                }
            });
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (this.isEditMode()) {
                this.$el.find('select[data-name="' + this.name + '"]').on('change', () => {
                    this.onSelectChange();
                });

                const oAuthAccountId = this.model.get('oAuthAccountId');

                if (oAuthAccountId && this.sourceOptions.length === 0 && !this.isLoading) {
                    this.fetchSources();
                }
            }
        },

        onSelectChange: function () {
            const $select = this.$el.find('select[data-name="' + this.name + '"]');
            const sourceId = $select.val();
            const sourceName = $select.find('option:selected').data('source-name') || null;

            this.model.set('sourceId', sourceId || null);
            this.model.set('sourceName', sourceName || null);
        },

        fetchSources: function () {
            const oAuthAccountId = this.model.get('oAuthAccountId');
            const channel = this.model.get('channel') || 'whatsapp';
            const config = CHANNEL_CONFIG[channel];

            if (!oAuthAccountId || !config) {
                this.sourceOptions = [];
                this.isLoading = false;
                this.loadError = null;
                this.reRender();
                return;
            }

            this.isLoading = true;
            this.loadError = null;
            this.sourceOptions = [];
            this.reRender();

            if (this.currentRequest) {
                this.currentRequest.abort();
                this.currentRequest = null;
            }

            this.currentRequest = Espo.Ajax.getRequest(config.entity, {
                oAuthAccountId: oAuthAccountId,
            });

            this.currentRequest
                .then(response => {
                    this.currentRequest = null;
                    this.isLoading = false;

                    const list = response.list || [];
                    const currentValue = this.model.get('sourceId');

                    this.sourceOptions = list.map(item => {
                        const sourceId = item[config.idKey] || '';
                        const name = item[config.nameKey] || item.name || '';

                        return {
                            sourceId: sourceId,
                            name: name,
                            label: name + (sourceId ? ' (' + sourceId + ')' : ''),
                            selected: sourceId === currentValue,
                        };
                    });

                    if (list.length === 0) {
                        this.loadError = 'No accounts found for this Meta account.';
                    }

                    this.reRender();
                })
                .catch(xhr => {
                    this.currentRequest = null;
                    this.isLoading = false;

                    if (xhr && xhr.statusText === 'abort') {
                        return;
                    }

                    this.loadError = 'Failed to load accounts.';
                    this.reRender();
                });
        },

        isEditMode: function () {
            return this.mode === 'edit';
        },

        fetch: function () {
            const $select = this.$el.find('select[data-name="' + this.name + '"]');

            if ($select.length) {
                const sourceId = $select.val();
                const sourceName = $select.find('option:selected').data('source-name') || null;

                const data = {};
                data[this.name] = sourceId || null;
                data['sourceName'] = sourceName || null;
                return data;
            }

            return Dep.prototype.fetch.call(this);
        },
    });
});
