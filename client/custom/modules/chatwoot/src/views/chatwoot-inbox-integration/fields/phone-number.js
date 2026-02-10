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
 * Custom field view for the phoneNumber field on ChatwootInboxIntegration.
 *
 * When channelType is "whatsappCloudApi" and in edit mode, this view renders
 * a <select> dropdown populated with phone numbers fetched from the Meta API
 * via the WhatsAppBusinessAccountPhoneNumber virtual entity.
 *
 * The phone numbers are fetched using the oAuthAccountId and businessAccountId
 * from the model (set by the WABA selector field). On selection it sets both
 * phoneNumber (display) and phoneNumberId (Meta ID) on the model.
 *
 * In detail/list mode or for whatsappQrcode, it falls back to the standard
 * varchar rendering.
 */
define('chatwoot:views/chatwoot-inbox-integration/fields/phone-number', ['views/fields/varchar'], function (Dep) {

    return Dep.extend({

        editTemplateContent:
            '{{#if isCloudApi}}' +
            '<select class="main-element form-control" data-name="{{name}}">' +
                '<option value="">{{selectPlaceholder}}</option>' +
                '{{#each phoneOptions}}' +
                '<option value="{{displayPhoneNumber}}" data-phone-number-id="{{phoneNumberId}}"' +
                    '{{#if selected}} selected{{/if}}>{{label}}</option>' +
                '{{/each}}' +
            '</select>' +
            '{{#if isLoading}}' +
            '<span class="text-muted small phone-number-loading">' +
                '<span class="fas fa-spinner fa-spin"></span> Loading phone numbers...' +
            '</span>' +
            '{{/if}}' +
            '{{#if loadError}}' +
            '<span class="text-danger small phone-number-error">' +
                '<span class="fas fa-exclamation-triangle"></span> {{loadError}}' +
            '</span>' +
            '{{/if}}' +
            '{{else}}' +
            '<input type="text" class="main-element form-control" data-name="{{name}}"' +
                ' value="{{value}}" autocomplete="espo-{{name}}" maxlength="{{maxLength}}">' +
            '{{/if}}',

        phoneOptions: null,
        isLoading: false,
        loadError: null,
        currentRequest: null,

        data: function () {
            const data = Dep.prototype.data.call(this);
            const isCloudApi = this.model.get('channelType') === 'whatsappCloudApi';

            data.isCloudApi = isCloudApi;
            data.phoneOptions = this.phoneOptions || [];
            data.isLoading = this.isLoading;
            data.loadError = this.loadError;
            data.selectPlaceholder = this.isLoading
                ? 'Loading...'
                : 'Select a phone number';

            return data;
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.phoneOptions = [];
            this.isLoading = false;
            this.loadError = null;

            // Reload phone numbers when the WABA selection changes.
            this.listenTo(this.model, 'change:businessAccountId', () => {
                if (this.isEditMode() && this.model.get('channelType') === 'whatsappCloudApi') {
                    this.model.set('phoneNumber', null);
                    this.model.set('phoneNumberId', null);
                    this.fetchPhoneNumbers();
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
                const businessAccountId = this.model.get('businessAccountId');

                if (oAuthAccountId && businessAccountId && this.phoneOptions.length === 0 && !this.isLoading) {
                    this.fetchPhoneNumbers();
                }
            }
        },

        onSelectChange: function () {
            const $select = this.$el.find('select[data-name="' + this.name + '"]');
            const displayPhoneNumber = $select.val();
            const phoneNumberId = $select.find('option:selected').data('phone-number-id') || null;

            this.model.set('phoneNumber', displayPhoneNumber || null);
            this.model.set('phoneNumberId', phoneNumberId || null);
        },

        fetchPhoneNumbers: function () {
            const oAuthAccountId = this.model.get('oAuthAccountId');
            const businessAccountId = this.model.get('businessAccountId');

            if (!oAuthAccountId || !businessAccountId) {
                this.phoneOptions = [];
                this.isLoading = false;
                this.loadError = null;
                this.reRender();
                return;
            }

            this.isLoading = true;
            this.loadError = null;
            this.phoneOptions = [];
            this.reRender();

            if (this.currentRequest) {
                this.currentRequest.abort();
                this.currentRequest = null;
            }

            // Fetch phone numbers directly using oAuthAccountId + businessAccountId.
            this.currentRequest = Espo.Ajax.getRequest('WhatsAppBusinessAccountPhoneNumber', {
                oAuthAccountId: oAuthAccountId,
                businessAccountId: businessAccountId,
            });

            this.currentRequest
                .then(response => {
                    this.currentRequest = null;
                    this.isLoading = false;

                    const list = response.list || [];
                    const currentValue = this.model.get('phoneNumber');

                    this.phoneOptions = list.map(item => {
                        const display = item.displayPhoneNumber || '';
                        const name = item.name || '';
                        const pnId = item.phoneNumberId || '';

                        return {
                            displayPhoneNumber: display,
                            phoneNumberId: pnId,
                            label: display + (name ? ' - ' + name : ''),
                            selected: display === currentValue,
                        };
                    });

                    if (list.length === 0) {
                        this.loadError = 'No phone numbers found for this business account.';
                    }

                    this.reRender();
                })
                .catch(xhr => {
                    this.currentRequest = null;
                    this.isLoading = false;

                    if (xhr && xhr.statusText === 'abort') {
                        return;
                    }

                    this.loadError = 'Failed to load phone numbers.';
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
                    const displayPhoneNumber = $select.val();
                    const phoneNumberId = $select.find('option:selected').data('phone-number-id') || null;

                    const data = {};
                    data[this.name] = displayPhoneNumber || null;
                    data['phoneNumberId'] = phoneNumberId || null;
                    return data;
                }
            }

            return Dep.prototype.fetch.call(this);
        },
    });
});
