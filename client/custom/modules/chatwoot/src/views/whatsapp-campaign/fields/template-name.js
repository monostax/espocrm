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
 * Custom field view for the templateName field on WhatsAppCampaign.
 *
 * In edit mode, renders a <select> dropdown populated with APPROVED message
 * templates fetched from the Meta API via the
 * WhatsAppBusinessAccountMessageTemplate virtual entity.
 *
 * When a template is selected, parses its components to extract numbered
 * parameters ({{1}}, {{2}}, etc.) and shows mapping inputs so the user can
 * map each parameter to an EspoCRM Contact field expression using
 * Handlebars syntax (e.g. {{firstName}}, {{account.name}}).
 *
 * In detail/list mode, falls back to standard varchar rendering.
 */
define('chatwoot:views/whatsapp-campaign/fields/template-name', ['views/fields/varchar'], function (Dep) {

    return Dep.extend({

        editTemplateContent:
            '<select class="main-element form-control" data-name="{{name}}">' +
                '<option value="">{{selectPlaceholder}}</option>' +
                '{{#each templateOptions}}' +
                '<option value="{{optionValue}}"' +
                    '{{#if selected}} selected{{/if}}>{{label}}</option>' +
                '{{/each}}' +
            '</select>' +
            '{{#if isLoading}}' +
            '<span class="text-muted small template-loading">' +
                '<span class="fas fa-spinner fa-spin"></span> {{loadingMessage}}' +
            '</span>' +
            '{{/if}}' +
            '{{#if loadError}}' +
            '<span class="text-danger small template-error">' +
                '<span class="fas fa-exclamation-triangle"></span> {{loadError}}' +
            '</span>' +
            '{{/if}}' +
            '{{#if noCredential}}' +
            '<span class="text-muted small">' +
                'Select a Credential first.' +
            '</span>' +
            '{{/if}}' +
            '{{#if hasTemplateParams}}' +
            '<div class="parameter-mapping-section" style="margin-top: 10px;">' +
                '<div class="text-muted small" style="margin-bottom: 8px;">' +
                    'Map template parameters to Contact fields:' +
                '</div>' +
                '{{#each templateParams}}' +
                '<div class="form-group param-row" style="margin-bottom: 6px;">' +
                    '<label class="control-label small" style="margin-bottom: 2px;">' +
                        '<code style="color: #c7254e;">{{paramPlaceholder}}</code> ' +
                        '<span class="text-muted">{{contextHint}}</span>' +
                    '</label>' +
                    '<input type="text" class="form-control input-sm param-mapping-input"' +
                        ' data-param-number="{{paramNumber}}"' +
                        ' value="{{currentValue}}">' +
                '</div>' +
                '{{/each}}' +
            '</div>' +
            '{{/if}}',

        templateOptions: null,
        isLoading: false,
        loadingMessage: null,
        loadError: null,
        currentRequest: null,
        templateComponentsMap: null,
        currentTemplateParams: null,

        data: function () {
            const data = Dep.prototype.data.call(this);

            const credentialId = this.model.get('credentialId');
            const currentName = this.model.get(this.name);
            const currentLang = this.model.get('templateLanguage');

            data.templateOptions = (this.templateOptions || []).map(opt => ({
                optionValue: opt.name + '::' + opt.language,
                label: opt.label,
                selected: opt.name === currentName && opt.language === currentLang,
            }));
            data.isLoading = this.isLoading;
            data.loadingMessage = this.loadingMessage || 'Loading...';
            data.loadError = this.loadError;
            data.noCredential = !this.isLoading && !this.loadError && !credentialId;
            data.selectPlaceholder = this.isLoading
                ? 'Loading...'
                : 'Select a template';
            data.templateParams = this.currentTemplateParams || [];
            data.hasTemplateParams = data.templateParams.length > 0;

            return data;
        },

        setup: function () {
            Dep.prototype.setup.call(this);

            this.templateOptions = [];
            this.isLoading = false;
            this.loadError = null;
            this.templateComponentsMap = {};
            this.currentTemplateParams = [];

            this.listenTo(this.model, 'change:credentialId', () => {
                if (this.isEditMode()) {
                    this.model.set('templateName', null);
                    this.model.set('templateLanguage', null);
                    this.model.set('wabaId', null);
                    this.model.set('parameterMapping', null);
                    this.currentTemplateParams = [];
                    this.resolveAndFetchTemplates();
                }
            });
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (this.isEditMode()) {
                this.$el.find('select[data-name="' + this.name + '"]').on('change', () => {
                    this.onSelectChange();
                });

                this.$el.find('.param-mapping-input').on('change', (e) => {
                    this.onMappingInputChange();
                });

                this.$el.find('.param-mapping-input').each((i, el) => {
                    if (!el.getAttribute('placeholder')) {
                        el.setAttribute('placeholder', 'e.g. {{firstName}}');
                    }
                });

                const credentialId = this.model.get('credentialId');

                if (credentialId && this.templateOptions.length === 0 && !this.isLoading && !this.loadError) {
                    this.resolveAndFetchTemplates();
                }
            }
        },

        onSelectChange: function () {
            const $select = this.$el.find('select[data-name="' + this.name + '"]');
            const compositeVal = $select.val() || '';
            const parts = compositeVal.split('::');
            const templateName = parts[0] || null;
            const language = parts[1] || null;

            const selectedOpt = this.templateOptions.find(
                o => o.name === templateName && o.language === language
            );

            const compositeKey = templateName && language ? templateName + '::' + language : null;

            let templateBody = null;

            if (compositeKey && this.templateComponentsMap[compositeKey]) {
                const components = this.templateComponentsMap[compositeKey];

                if (Array.isArray(components)) {
                    const bodyParts = components
                        .filter(c => c.type === 'BODY')
                        .map(c => c.text || '');

                    templateBody = bodyParts.join('\n') || null;
                }
            }

            const attrs = {
                templateName: templateName,
                templateLanguage: language,
                templateCategory: selectedOpt ? selectedOpt.category : null,
                templateBody: templateBody,
                parameterMapping: null,
            };

            this.currentTemplateParams = [];

            if (compositeKey && this.templateComponentsMap[compositeKey]) {
                this.parseTemplateParameters(compositeKey);
            }

            this.model.set(attrs, {silent: true});

            if (this.currentTemplateParams.length > 0) {
                this.reRender();
            }
        },

        parseTemplateParameters: function (compositeKey) {
            const components = this.templateComponentsMap[compositeKey];

            if (!components || !Array.isArray(components)) {
                this.currentTemplateParams = [];
                return;
            }

            const params = [];
            const existingMapping = this.model.get('parameterMapping') || {};

            for (const comp of components) {
                const type = comp.type || '';
                const text = comp.text || '';

                if (type !== 'BODY' && type !== 'HEADER') {
                    continue;
                }

                const regex = /\{\{(\d+)\}\}/g;
                let match;

                while ((match = regex.exec(text)) !== null) {
                    const paramNumber = match[1];
                    const pos = match.index;
                    const before = text.substring(Math.max(0, pos - 20), pos).replace(/\n/g, ' ');
                    const after = text.substring(pos + match[0].length, pos + match[0].length + 20).replace(/\n/g, ' ');
                    const contextHint = '...' + before + '___' + after + '...';

                    params.push({
                        paramNumber: paramNumber,
                        paramPlaceholder: '{{' + paramNumber + '}}',
                        contextHint: contextHint,
                        componentType: type,
                        currentValue: existingMapping[paramNumber] || '',
                    });
                }
            }

            params.sort((a, b) => parseInt(a.paramNumber) - parseInt(b.paramNumber));

            this.currentTemplateParams = params;
        },

        onMappingInputChange: function () {
            const mapping = {};
            let hasAny = false;

            this.$el.find('.param-mapping-input').each((i, el) => {
                const paramNum = el.getAttribute('data-param-number');
                const value = (el.value || '').trim();

                if (paramNum && value) {
                    mapping[paramNum] = value;
                    hasAny = true;
                }
            });

            this.model.set('parameterMapping', hasAny ? mapping : null);
        },

        resolveAndFetchTemplates: function () {
            const credentialId = this.model.get('credentialId');

            if (!credentialId) {
                this.templateOptions = [];
                this.isLoading = false;
                this.loadError = null;
                this.reRender();
                return;
            }

            this.isLoading = true;
            this.loadingMessage = 'Resolving credential...';
            this.loadError = null;
            this.templateOptions = [];
            this.reRender();

            if (this.currentRequest) {
                this.currentRequest.abort();
                this.currentRequest = null;
            }

            this.currentRequest = Espo.Ajax.getRequest('WhatsAppCampaign/action/resolveCredential', {
                credentialId: credentialId,
            });

            this.currentRequest
                .then(result => {
                    this.currentRequest = null;

                    const wabaId = result.wabaId;

                    if (!wabaId) {
                        this.isLoading = false;
                        this.loadError = 'Credential does not contain a WABA ID.';
                        this.reRender();
                        return;
                    }

                    this.model.set('wabaId', wabaId);
                    this.fetchTemplates(credentialId);
                })
                .catch(xhr => {
                    this.currentRequest = null;
                    this.isLoading = false;

                    if (xhr && xhr.statusText === 'abort') {
                        return;
                    }

                    let msg = 'Failed to resolve credential.';
                    if (xhr?.responseJSON?.message) {
                        msg = xhr.responseJSON.message;
                    }

                    this.loadError = msg;
                    this.reRender();
                });
        },

        fetchTemplates: function (credentialId) {
            this.loadingMessage = 'Loading templates...';
            this.reRender();

            if (this.currentRequest) {
                this.currentRequest.abort();
                this.currentRequest = null;
            }

            this.currentRequest = Espo.Ajax.getRequest('WhatsAppBusinessAccountMessageTemplate', {
                credentialId: credentialId,
            });

            this.currentRequest
                .then(response => {
                    this.currentRequest = null;
                    this.isLoading = false;

                    const list = response.list || [];
                    const currentValue = this.model.get('templateName');

                    this.templateComponentsMap = {};

                    this.templateOptions = list
                        .filter(item => item.status === 'APPROVED')
                        .map(item => {
                            const name = item.name || '';
                            const language = item.language || '';
                            const category = item.category || '';
                            const compositeKey = name + '::' + language;

                            let components = [];
                            if (item.components) {
                                try {
                                    components = typeof item.components === 'string'
                                        ? JSON.parse(item.components)
                                        : item.components;
                                } catch (e) {
                                    components = [];
                                }
                            }

                            this.templateComponentsMap[compositeKey] = components;

                            return {
                                name: name,
                                language: language,
                                category: category,
                                label: name + ' (' + language + ') [' + category + ']',
                            };
                        });

                    const currentLang = this.model.get('templateLanguage');
                    const currentKey = currentValue && currentLang
                        ? currentValue + '::' + currentLang : null;

                    if (currentKey && this.templateComponentsMap[currentKey]) {
                        this.parseTemplateParameters(currentKey);
                    }

                    if (list.length === 0) {
                        this.loadError = 'No templates found for this credential.';
                    } else if (this.templateOptions.length === 0) {
                        this.loadError = 'No APPROVED templates found.';
                    }

                    this.reRender();
                })
                .catch(xhr => {
                    this.currentRequest = null;
                    this.isLoading = false;

                    if (xhr && xhr.statusText === 'abort') {
                        return;
                    }

                    this.loadError = 'Failed to load templates.';
                    this.reRender();
                });
        },

        isEditMode: function () {
            return this.mode === 'edit';
        },

        fetch: function () {
            const $select = this.$el.find('select[data-name="' + this.name + '"]');

            if ($select.length) {
                const compositeVal = $select.val() || '';
                const parts = compositeVal.split('::');
                const templateName = parts[0] || null;
                const language = parts[1] || null;

                const selectedOpt = this.templateOptions.find(
                    o => o.name === templateName && o.language === language
                );

                const data = {};
                data[this.name] = templateName;
                data['templateLanguage'] = language;
                data['templateCategory'] = selectedOpt ? selectedOpt.category : null;

                const mapping = {};
                let hasMapping = false;

                this.$el.find('.param-mapping-input').each((i, el) => {
                    const paramNum = el.getAttribute('data-param-number');
                    const value = (el.value || '').trim();

                    if (paramNum && value) {
                        mapping[paramNum] = value;
                        hasMapping = true;
                    }
                });

                data['parameterMapping'] = hasMapping ? mapping : null;
                data['templateBody'] = this.model.get('templateBody') || null;

                return data;
            }

            return Dep.prototype.fetch.call(this);
        },
    });
});
