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
 * Multi-select of Meta-approved WhatsApp message templates for an AI membership.
 *
 * Loads Cloud API / Coexistence inboxes linked to this membership, resolves
 * Meta auth per inbox (same path as WhatsApp campaigns), lists APPROVED
 * templates, and stores selected entries as a jsonArray:
 *
 *   [{ name, language, category, inboxPlatformId, inboxName }]
 *
 * Runtime (backend agent) intersects this allow-list with Chatwoot-synced
 * channel templates for the conversation inbox when the 24h window is closed.
 */
define('chatwoot:views/account-user-membership/fields/whatsapp-message-templates', [
    'views/fields/base',
], function (Dep) {

    const ALLOWED_CHANNEL_TYPES = ['whatsappCloudApi', 'whatsappCoexistence'];

    function compositeKey(entry) {
        return String(entry.inboxPlatformId) + '::' + entry.name + '::' + entry.language;
    }

    function isCloudOrCoexistenceInbox(item) {
        if (!item) {
            return false;
        }

        if (ALLOWED_CHANNEL_TYPES.includes(item.channelType)) {
            return true;
        }

        // Fallback when foreign channelType is missing from the link payload
        // but direct provider is present (admin-visible on ChatwootInbox).
        return item.provider === 'whatsapp_cloud';
    }

    return Dep.extend({

        detailTemplateContent:
            '{{#if isEmpty}}' +
            '<span class="text-muted">—</span>' +
            '{{else}}' +
            '<ul class="list-unstyled" style="margin:0;">' +
            '{{#each selectedLabels}}' +
            '<li><code>{{this}}</code></li>' +
            '{{/each}}' +
            '</ul>' +
            '{{/if}}',

        editTemplateContent:
            '{{#if isLoading}}' +
            '<span class="text-muted small">' +
                '<span class="fas fa-spinner fa-spin"></span> {{loadingMessage}}' +
            '</span>' +
            '{{/if}}' +
            '{{#if loadError}}' +
            '<div class="text-danger small" style="margin-bottom:8px;">' +
                '<span class="fas fa-exclamation-triangle"></span> {{loadError}}' +
            '</div>' +
            '{{/if}}' +
            '{{#if noInboxes}}' +
            '<div class="text-muted small">' +
                '<div>{{noInboxesMessage}}</div>' +
                '{{#if linkedInboxSummary}}' +
                '<div style="margin-top:4px;">{{linkedInboxSummary}}</div>' +
                '{{/if}}' +
            '</div>' +
            '{{else}}' +
            '{{#each inboxGroups}}' +
            '<div class="whatsapp-template-inbox-group" style="margin-bottom:12px;">' +
                '<div class="text-soft small" style="font-weight:600;margin-bottom:4px;">' +
                    '{{inboxName}} <span class="text-muted">(#{{inboxPlatformId}} · {{channelType}})</span>' +
                '</div>' +
                '{{#if options.length}}' +
                '{{#each options}}' +
                '<div class="checkbox" style="margin:2px 0;">' +
                    '<label style="font-weight:normal;">' +
                        '<input type="checkbox" class="template-option" ' +
                            'data-key="{{key}}" ' +
                            '{{#if checked}}checked{{/if}}>' +
                        ' {{label}}' +
                    '</label>' +
                '</div>' +
                '{{/each}}' +
                '{{else}}' +
                '<div class="text-muted small">{{emptyMessage}}</div>' +
                '{{/if}}' +
            '</div>' +
            '{{/each}}' +
            '{{/if}}',

        inboxGroups: null,
        linkedInboxSummary: null,
        isLoading: false,
        loadError: null,
        loadingMessage: null,
        currentRequest: null,
        loadToken: 0,

        data: function () {
            const data = Dep.prototype.data.call(this);
            const selected = this.getSelectedEntries();
            const selectedKeys = {};

            selected.forEach(e => {
                selectedKeys[compositeKey(e)] = true;
            });

            data.isLoading = this.isLoading;
            data.loadingMessage = this.loadingMessage ||
                this.translate('whatsappTemplatesLoading', 'messages', 'ChatwootAccountUserMembership');
            data.loadError = this.loadError;
            data.noInboxes = !this.isLoading && !this.loadError &&
                Array.isArray(this.inboxGroups) && this.inboxGroups.length === 0;
            data.noInboxesMessage = this.translate(
                'whatsappTemplatesNoCloudInboxes',
                'messages',
                'ChatwootAccountUserMembership'
            );
            data.linkedInboxSummary = this.linkedInboxSummary;
            data.inboxGroups = (this.inboxGroups || []).map(group => ({
                inboxName: group.inboxName,
                inboxPlatformId: group.inboxPlatformId,
                channelType: group.channelType,
                emptyMessage: group.emptyMessage || this.translate(
                    'whatsappTemplatesNoApproved',
                    'messages',
                    'ChatwootAccountUserMembership'
                ),
                options: (group.options || []).map(opt => ({
                    key: opt.key,
                    label: opt.label,
                    checked: !!selectedKeys[opt.key],
                })),
            }));
            data.selectedLabels = selected.map(e =>
                (e.inboxName ? e.inboxName + ' · ' : '') +
                e.name + ' (' + e.language + ')' +
                (e.category ? ' [' + e.category + ']' : '')
            );
            data.isEmpty = selected.length === 0;

            return data;
        },

        setup: function () {
            Dep.prototype.setup.call(this);
            this.inboxGroups = null;
            this.linkedInboxSummary = null;
            this.isLoading = false;
            this.loadError = null;
            this.loadToken = 0;

            this.listenTo(this.model, 'sync', () => {
                if (this.isEditMode() || this.isDetailMode()) {
                    this.loadOptions();
                }
            });
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (this.isEditMode()) {
                this.$el.find('input.template-option').on('change', () => {
                    this.onCheckboxChange();
                });

                if (this.inboxGroups === null && !this.isLoading) {
                    this.loadOptions();
                }
            } else if (this.isDetailMode() && this.inboxGroups === null && !this.isLoading) {
                // Detail mode does not need remote load for display; labels from stored value.
            }
        },

        getSelectedEntries: function () {
            const raw = this.model.get(this.name);
            if (!raw) {
                return [];
            }
            if (Array.isArray(raw)) {
                return raw.filter(e => e && e.name && e.language && e.inboxPlatformId != null);
            }
            return [];
        },

        onCheckboxChange: function () {
            const byKey = {};
            (this.inboxGroups || []).forEach(group => {
                (group.options || []).forEach(opt => {
                    byKey[opt.key] = opt.entry;
                });
            });

            const selected = [];
            this.$el.find('input.template-option:checked').each((i, el) => {
                const key = el.getAttribute('data-key');
                if (key && byKey[key]) {
                    selected.push(byKey[key]);
                }
            });

            this.model.set(this.name, selected.length ? selected : null);
        },

        buildLinkedInboxSummary: function (list) {
            if (!list || !list.length) {
                return null;
            }

            const labels = list.map(item => {
                const name = item.name || ('#' + (item.chatwootInboxId || item.id));
                const type = item.channelType || item.provider || '?';

                return name + ' (' + type + ')';
            });

            return this.translate(
                'whatsappTemplatesLinkedInboxesSummary',
                'messages',
                'ChatwootAccountUserMembership'
            ).replace('{list}', labels.join(', '));
        },

        loadOptions: function () {
            const membershipId = this.model.id;
            if (!membershipId) {
                this.inboxGroups = [];
                this.linkedInboxSummary = null;
                this.reRender();
                return;
            }

            const token = ++this.loadToken;

            this.isLoading = true;
            this.loadingMessage = this.translate(
                'whatsappTemplatesLoadingInboxes',
                'messages',
                'ChatwootAccountUserMembership'
            );
            this.loadError = null;
            this.linkedInboxSummary = null;
            this.reRender();

            if (this.currentRequest && this.currentRequest.abort) {
                this.currentRequest.abort();
            }

            this.currentRequest = Espo.Ajax.getRequest(
                'ChatwootAccountUserMembership/' + membershipId + '/chatwootInboxes',
                {
                    maxSize: 200,
                    select: 'id,name,chatwootInboxId,channelType,provider,phoneNumber',
                }
            );

            this.currentRequest
                .then(response => {
                    this.currentRequest = null;

                    if (token !== this.loadToken) {
                        return;
                    }

                    const list = (response && response.list) || [];
                    const cloudInboxes = list.filter(isCloudOrCoexistenceInbox);

                    if (cloudInboxes.length === 0) {
                        this.isLoading = false;
                        this.inboxGroups = [];
                        this.linkedInboxSummary = this.buildLinkedInboxSummary(list);
                        this.reRender();
                        return;
                    }

                    this.loadingMessage = this.translate(
                        'whatsappTemplatesLoadingMeta',
                        'messages',
                        'ChatwootAccountUserMembership'
                    );
                    this.reRender();

                    const tasks = cloudInboxes.map(inbox => this.loadTemplatesForInbox(inbox));

                    return Promise.all(tasks).then(groups => {
                        if (token !== this.loadToken) {
                            return;
                        }

                        this.isLoading = false;
                        this.inboxGroups = groups;
                        this.linkedInboxSummary = null;
                        this.reRender();
                    });
                })
                .catch(xhr => {
                    this.currentRequest = null;

                    if (token !== this.loadToken) {
                        return;
                    }

                    this.isLoading = false;
                    if (xhr && xhr.statusText === 'abort') {
                        return;
                    }
                    this.loadError = (xhr && xhr.responseJSON && xhr.responseJSON.message) ||
                        this.translate(
                            'whatsappTemplatesLoadInboxesFailed',
                            'messages',
                            'ChatwootAccountUserMembership'
                        );
                    this.inboxGroups = [];
                    this.linkedInboxSummary = null;
                    this.reRender();
                });
        },

        loadTemplatesForInbox: function (inbox) {
            const inboxCrmId = inbox.id;
            const inboxPlatformId = inbox.chatwootInboxId;
            const inboxName = inbox.name || ('Inbox #' + inboxPlatformId);
            const channelType = inbox.channelType || inbox.provider || '';

            return Espo.Ajax.getRequest('WhatsAppCampaign/action/resolveInbox', {
                chatwootInboxId: inboxCrmId,
            })
                .then(resolved => {
                    const params = {};
                    if (resolved.oAuthAccountId && resolved.wabaId) {
                        params.oAuthAccountId = resolved.oAuthAccountId;
                        params.businessAccountId = resolved.wabaId;
                    } else if (resolved.credentialId) {
                        params.credentialId = resolved.credentialId;
                        params.wabaId = resolved.wabaId;
                    } else {
                        return {
                            inboxName,
                            inboxPlatformId,
                            channelType,
                            options: [],
                            emptyMessage: this.translate(
                                'whatsappTemplatesNoMetaCredentials',
                                'messages',
                                'ChatwootAccountUserMembership'
                            ),
                        };
                    }

                    return Espo.Ajax.getRequest('WhatsAppBusinessAccountMessageTemplate', params)
                        .then(tplResponse => {
                            const list = (tplResponse && tplResponse.list) || [];
                            const options = list
                                .filter(item => String(item.status || '').toUpperCase() === 'APPROVED')
                                .map(item => {
                                    const name = item.name || '';
                                    const language = item.language || '';
                                    const category = item.category || '';
                                    const entry = {
                                        name,
                                        language,
                                        category,
                                        inboxPlatformId,
                                        inboxName,
                                    };

                                    return {
                                        key: compositeKey(entry),
                                        label: name + ' (' + language + ')' +
                                            (category ? ' [' + category + ']' : ''),
                                        entry,
                                    };
                                });

                            return {
                                inboxName,
                                inboxPlatformId,
                                channelType,
                                options,
                                emptyMessage: options.length
                                    ? null
                                    : this.translate(
                                        'whatsappTemplatesNoApprovedForInbox',
                                        'messages',
                                        'ChatwootAccountUserMembership'
                                    ),
                            };
                        });
                })
                .catch(xhr => {
                    const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) ||
                        this.translate(
                            'whatsappTemplatesLoadTemplatesFailed',
                            'messages',
                            'ChatwootAccountUserMembership'
                        );
                    return {
                        inboxName,
                        inboxPlatformId,
                        channelType,
                        options: [],
                        emptyMessage: msg,
                    };
                });
        },

        fetch: function () {
            // Value is written continuously via onCheckboxChange.
            const data = {};
            data[this.name] = this.model.get(this.name) || null;
            return data;
        },
    });
});
