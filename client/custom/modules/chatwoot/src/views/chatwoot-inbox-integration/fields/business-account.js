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
 * Custom field view for the businessAccountId field on ChatwootInboxIntegration.
 *
 * When channelType is "whatsappCloudApi" and in edit mode, this view renders
 * a <select> dropdown populated with WhatsApp Business Accounts fetched from
 * the Meta API via the WhatsAppBusinessAccount virtual entity.
 *
 * When an OAuthAccount is selected, WABAs are discovered dynamically. On
 * selection it sets businessAccountId (WABA ID) and businessAccountName on
 * the model, which in turn triggers the phone number selector to reload.
 *
 * In detail/list mode or for whatsappQrcode, it falls back to standard
 * varchar rendering showing the businessAccountName.
 */
define('chatwoot:views/chatwoot-inbox-integration/fields/business-account', ['views/fields/varchar'], function (Dep) {

    return Dep.extend({

        editTemplateContent:
            '{{#if isCloudApi}}' +
            '<select class="main-element form-control" data-name="{{name}}">' +
                '<option value="">{{selectPlaceholder}}</option>' +
                '{{#each wabaOptions}}' +
                '<option value="{{wabaId}}" data-waba-name="{{name}}"' +
                    '{{#if selected}} selected{{/if}}>{{label}}</option>' +
                '{{/each}}' +
            '</select>' +
            '{{#if isLoading}}' +
            '<span class="text-muted small waba-loading">' +
                '<span class="fas fa-spinner fa-spin"></span> Loading business accounts...' +
            '</span>' +
            '{{/if}}' +
            '{{#if loadError}}' +
            '<span class="text-danger small waba-error">' +
                '<span class="fas fa-exclamation-triangle"></span> {{loadError}}' +
            '</span>' +
            '{{/if}}' +
            '{{else}}' +
            '<input type="text" class="main-element form-control" data-name="{{name}}"' +
                ' value="{{value}}" autocomplete="espo-{{name}}" maxlength="{{maxLength}}" readonly>' +
            '{{/if}}',

        detailTemplateContent:
            '{{#if businessAccountName}}' +
            '<span>{{businessAccountName}}</span>' +
            '{{else}}' +
            '{{#if value}}' +
            '<span>{{value}}</span>' +
            '{{else}}' +
            '<span class="none-value">{{translate \'None\'}}</span>' +
            '{{/if}}' +
            '{{/if}}',

        wabaOptions: null,
        isLoading: false,
        loadError: null,
        currentRequest: null,

        data: function () {
            const data = Dep.prototype.data.call(this);
            const isCloudApi = this.model.get('channelType') === 'whatsappCloudApi';

            data.isCloudApi = isCloudApi;
            data.wabaOptions = this.wabaOptions || [];
            data.isLoading = this.isLoading;
            data.loadError = this.loadError;
            data.businessAccountName = this.model.get('businessAccountName');
            data.selectPlaceholder = this.isLoading
                ? 'Loading...'
                : 'Select a business account';

            return data;
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.wabaOptions = [];
            this.isLoading = false;
            this.loadError = null;

            this.listenTo(this.model, 'change:oAuthAccountId', () => {
                if (this.isEditMode() && this.model.get('channelType') === 'whatsappCloudApi') {
                    this.model.set('businessAccountId', null);
                    this.model.set('businessAccountName', null);
                    // Clear dependent phone number fields.
                    this.model.set('phoneNumber', null);
                    this.model.set('phoneNumberId', null);
                    this.fetchBusinessAccounts();
                }
            });

            this.listenTo(this.model, 'change:channelType', () => {
                if (this.isEditMode()) {
                    this.reRender();
                }
            });
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (this.isEditMode() && this.model.get('channelType') === 'whatsappCloudApi') {
                this.$el.find('select[data-name="' + this.name + '"]').on('change', () => {
                    this.onSelectChange();
                });

                const oAuthAccountId = this.model.get('oAuthAccountId');

                if (oAuthAccountId && this.wabaOptions.length === 0 && !this.isLoading) {
                    this.fetchBusinessAccounts();
                }
            }
        },

        onSelectChange: function () {
            const $select = this.$el.find('select[data-name="' + this.name + '"]');
            const wabaId = $select.val();
            const wabaName = $select.find('option:selected').data('waba-name') || null;

            this.model.set('businessAccountId', wabaId || null);
            this.model.set('businessAccountName', wabaName || null);
            // Clear dependent phone number when WABA changes.
            this.model.set('phoneNumber', null);
            this.model.set('phoneNumberId', null);
        },

        fetchBusinessAccounts: function () {
            const oAuthAccountId = this.model.get('oAuthAccountId');

            if (!oAuthAccountId) {
                this.wabaOptions = [];
                this.isLoading = false;
                this.loadError = null;
                this.reRender();
                return;
            }

            this.isLoading = true;
            this.loadError = null;
            this.wabaOptions = [];
            this.reRender();

            if (this.currentRequest) {
                this.currentRequest.abort();
                this.currentRequest = null;
            }

            // Fetch WABAs from the virtual entity API filtered by OAuthAccount.
            this.currentRequest = Espo.Ajax.getRequest('WhatsAppBusinessAccount', {
                oAuthAccountId: oAuthAccountId,
            });

            this.currentRequest
                .then(response => {
                    this.currentRequest = null;
                    this.isLoading = false;

                    const list = response.list || [];
                    const currentValue = this.model.get('businessAccountId');

                    this.wabaOptions = list.map(item => {
                        // The WABA entity has a composite id: oAuthAccountId_wabaId
                        const wabaId = item.wabaId || '';
                        const name = item.name || '';

                        return {
                            wabaId: wabaId,
                            name: name,
                            label: name + (wabaId ? ' (' + wabaId + ')' : ''),
                            selected: wabaId === currentValue,
                        };
                    });

                    if (list.length === 0) {
                        this.loadError = 'No business accounts found for this Meta account.';
                    }

                    this.reRender();
                })
                .catch(xhr => {
                    this.currentRequest = null;
                    this.isLoading = false;

                    if (xhr && xhr.statusText === 'abort') {
                        return;
                    }

                    this.loadError = 'Failed to load business accounts.';
                    this.reRender();
                });
        },

        isEditMode: function () {
            return this.mode === 'edit';
        },

        fetch: function () {
            if (this.model.get('channelType') === 'whatsappCloudApi') {
                const $select = this.$el.find('select[data-name="' + this.name + '"]');

                if ($select.length) {
                    const wabaId = $select.val();
                    const wabaName = $select.find('option:selected').data('waba-name') || null;

                    const data = {};
                    data[this.name] = wabaId || null;
                    data['businessAccountName'] = wabaName || null;
                    return data;
                }
            }

            return Dep.prototype.fetch.call(this);
        },
    });
});
