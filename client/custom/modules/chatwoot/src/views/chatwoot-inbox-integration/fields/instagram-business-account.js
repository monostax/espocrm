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
 * Custom field view for the instagramBusinessAccountId field on ChatwootInboxIntegration.
 *
 * In edit mode, renders a <select> dropdown populated with Instagram Business
 * Accounts fetched from the Meta Instagram Graph API via the
 * InstagramBusinessAccount virtual entity.
 *
 * On selection, it writes `instagramId` and `instagramUsername` onto the model.
 * Re-fetches on `change:oAuthAccountId`.
 *
 * This view is dedicated to the Instagram flow — it does not branch on
 * channelType. The WhatsApp flow uses the separate `business-account.js` view.
 */
define('chatwoot:views/chatwoot-inbox-integration/fields/instagram-business-account', ['views/fields/varchar'], function (Dep) {

    return Dep.extend({

        editTemplateContent:
            '<select class="main-element form-control" data-name="{{name}}">' +
                '<option value="">{{selectPlaceholder}}</option>' +
                '{{#each igOptions}}' +
                '<option value="{{instagramId}}" data-username="{{username}}"' +
                    '{{#if selected}} selected{{/if}}>{{label}}</option>' +
                '{{/each}}' +
            '</select>' +
            '{{#if isLoading}}' +
            '<span class="text-muted small ig-loading">' +
                '<span class="fas fa-spinner fa-spin"></span> Loading Instagram accounts...' +
            '</span>' +
            '{{/if}}' +
            '{{#if loadError}}' +
            '<span class="text-danger small ig-error">' +
                '<span class="fas fa-exclamation-triangle"></span> {{loadError}}' +
            '</span>' +
            '{{/if}}',

        detailTemplateContent:
            '{{#if instagramUsername}}' +
            '<span>{{instagramUsername}}</span>' +
            '{{else}}' +
            '{{#if value}}' +
            '<span>{{value}}</span>' +
            '{{else}}' +
            '<span class="none-value">{{translate \'None\'}}</span>' +
            '{{/if}}' +
            '{{/if}}',

        igOptions: null,
        isLoading: false,
        loadError: null,
        currentRequest: null,
        // Tracks the oAuthAccountId for which we last attempted a fetch.
        // Prevents afterRender from recursively re-triggering fetchAccounts
        // when the API returns an empty list or an error (both of which leave
        // igOptions empty).
        fetchedForOAuthAccountId: null,

        data: function () {
            const data = Dep.prototype.data.call(this);

            data.igOptions = this.igOptions || [];
            data.isLoading = this.isLoading;
            data.loadError = this.loadError;
            data.instagramUsername = this.model.get('instagramUsername');
            data.selectPlaceholder = this.isLoading
                ? 'Loading...'
                : 'Select an Instagram account';

            return data;
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.igOptions = [];
            this.isLoading = false;
            this.loadError = null;
            this.fetchedForOAuthAccountId = null;

            this.listenTo(this.model, 'change:oAuthAccountId', () => {
                if (this.isEditMode() && this.model.get('channelType') === 'instagram') {
                    this.model.set('instagramBusinessAccountId', null);
                    this.model.set('instagramId', null);
                    this.model.set('instagramUsername', null);
                    // Allow afterRender to refetch for the new oAuthAccountId.
                    this.fetchedForOAuthAccountId = null;
                    this.fetchAccounts();
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

            if (this.isEditMode() && this.model.get('channelType') === 'instagram') {
                this.$el.find('select[data-name="' + this.name + '"]').on('change', () => {
                    this.onSelectChange();
                });

                const oAuthAccountId = this.model.get('oAuthAccountId');

                if (
                    oAuthAccountId &&
                    this.fetchedForOAuthAccountId !== oAuthAccountId &&
                    !this.isLoading
                ) {
                    this.fetchAccounts();
                }
            }
        },

        onSelectChange: function () {
            const $select = this.$el.find('select[data-name="' + this.name + '"]');
            const instagramId = $select.val();
            const username = $select.find('option:selected').data('username') || null;

            this.model.set('instagramBusinessAccountId', instagramId || null);
            this.model.set('instagramId', instagramId || null);
            this.model.set('instagramUsername', username || null);
        },

        fetchAccounts: function () {
            const oAuthAccountId = this.model.get('oAuthAccountId');

            if (!oAuthAccountId) {
                this.igOptions = [];
                this.isLoading = false;
                this.loadError = null;
                this.fetchedForOAuthAccountId = null;
                this.reRender();
                return;
            }

            // Mark the fetch as attempted BEFORE the request resolves so the
            // reRender() below (and any subsequent reRenders triggered by the
            // resolution/error handlers) won't cause afterRender to queue up
            // another fetch for the same oAuthAccountId.
            this.fetchedForOAuthAccountId = oAuthAccountId;
            this.isLoading = true;
            this.loadError = null;
            this.igOptions = [];
            this.reRender();

            if (this.currentRequest) {
                this.currentRequest.abort();
                this.currentRequest = null;
            }

            this.currentRequest = Espo.Ajax.getRequest('InstagramBusinessAccount', {
                oAuthAccountId: oAuthAccountId,
            });

            this.currentRequest
                .then(response => {
                    this.currentRequest = null;
                    this.isLoading = false;

                    const list = response.list || [];
                    const currentValue = this.model.get('instagramBusinessAccountId')
                        || this.model.get('instagramId');

                    this.igOptions = list.map(item => {
                        const instagramId = item.instagramId || '';
                        const username = item.username || '';
                        const label = username
                            ? (username + (instagramId ? ' (' + instagramId + ')' : ''))
                            : instagramId;

                        return {
                            instagramId: instagramId,
                            username: username,
                            label: label,
                            selected: instagramId === currentValue,
                        };
                    });

                    if (list.length === 0) {
                        this.loadError = 'No Instagram accounts found for this Meta account.';
                    }

                    this.reRender();
                })
                .catch(xhr => {
                    this.currentRequest = null;
                    this.isLoading = false;

                    if (xhr && xhr.statusText === 'abort') {
                        return;
                    }

                    this.loadError = 'Failed to load Instagram accounts.';
                    this.reRender();
                });
        },

        isEditMode: function () {
            return this.mode === 'edit';
        },

        fetch: function () {
            const $select = this.$el.find('select[data-name="' + this.name + '"]');

            if ($select.length) {
                const instagramId = $select.val();
                const username = $select.find('option:selected').data('username') || null;

                const data = {};
                data[this.name] = instagramId || null;
                data['instagramId'] = instagramId || null;
                data['instagramUsername'] = username || null;
                return data;
            }

            return Dep.prototype.fetch.call(this);
        },
    });
});
