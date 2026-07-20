/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * Adds customFields.<valueKey> options to CSV Import column mapping.
 ************************************************************************/

define('global:views/import/step2', ['views/import/step2'], function (Dep) {

    return class extends Dep {

        /**
         * @type {Record<string, string>}
         */
        customFieldAttributeLabels = {};

        /**
         * @type {string[]}
         */
        customFieldAttributeList = [];

        setup() {
            super.setup();

            const enabled =
                this.getMetadata().get(['app', 'customFields', 'entityTypeList']) || [];

            if (!Array.isArray(enabled) || !enabled.includes(this.scope)) {
                return;
            }

            if (this.getAcl().getScopeForbiddenFieldList(this.scope, 'edit').includes('customFields')) {
                return;
            }

            this.wait(this.loadCustomFieldAttributes());
        }

        /**
         * @return {Promise}
         */
        loadCustomFieldAttributes() {
            const teamIds = this.collectTeamIds();

            let url =
                'CustomField/action/importVariables?entityType=' +
                encodeURIComponent(this.scope);

            if (teamIds.length) {
                url += '&teamIds=' + encodeURIComponent(teamIds.join(','));
            }

            const tenantId =
                (this.formData.defaultValues && this.formData.defaultValues.tenantId) ||
                (this.model && this.model.get('tenantId'));

            if (tenantId) {
                url += '&tenantId=' + encodeURIComponent(tenantId);
            }

            return Espo.Ajax.getRequest(url)
                .then(response => {
                    const list = (response && response.list) || [];

                    list.forEach(item => {
                        const attribute = item.attribute;

                        if (!attribute || this.customFieldAttributeList.includes(attribute)) {
                            return;
                        }

                        this.customFieldAttributeList.push(attribute);

                        const group = item.groupLabel ? item.groupLabel + ' · ' : '';

                        this.customFieldAttributeLabels[attribute] =
                            'Custom Fields · ' + group + (item.label || item.valueKey);
                    });
                })
                .catch(() => {});
        }

        /**
         * @return {string[]}
         */
        collectTeamIds() {
            const ids = [];
            const defaults = this.formData.defaultValues || {};

            const push = raw => {
                if (Array.isArray(raw)) {
                    raw.forEach(id => {
                        if (typeof id === 'string' && id) {
                            ids.push(id);
                        }
                    });
                }
            };

            push(defaults.teamsIds);

            if (this.model) {
                push(this.model.get('teamsIds'));
            }

            return [...new Set(ids)];
        }

        /**
         * @return {string[]}
         */
        getAttributeList() {
            let list = super.getAttributeList();

            this.customFieldAttributeList.forEach(attribute => {
                if (!list.includes(attribute)) {
                    list.push(attribute);
                }
            });

            list = list.sort((v1, v2) => {
                return this.getAttributeLabel(v1).localeCompare(this.getAttributeLabel(v2));
            });

            return list;
        }

        /**
         * @param {string} attribute
         * @return {string}
         */
        getAttributeLabel(attribute) {
            if (this.customFieldAttributeLabels[attribute]) {
                return this.customFieldAttributeLabels[attribute];
            }

            const scope = this.formData.entityType;

            if (
                this.getLanguage().has(attribute, 'fields', scope) ||
                this.getLanguage().has(attribute, 'fields', 'Global')
            ) {
                return this.translate(attribute, 'fields', scope);
            }

            return attribute;
        }

        /**
         * @param {number} num
         * @param {string|false} name
         * @return {JQuery}
         */
        getFieldDropdown(num, name) {
            name = name || false;

            const fieldList = this.getAttributeList();

            const $select = $('<select>')
                .addClass('form-control')
                .attr('id', 'column-' + num.toString());

            let $option = $('<option>')
                .val('')
                .text('-' + this.translate('Skip', 'labels', 'Import') + '-');

            const scope = this.formData.entityType;

            $select.append($option);

            fieldList.forEach(field => {
                let label = '';

                if (this.customFieldAttributeLabels[field]) {
                    label = this.customFieldAttributeLabels[field];
                }
                else if (
                    this.getLanguage().has(field, 'fields', scope) ||
                    this.getLanguage().has(field, 'fields', 'Global')
                ) {
                    label = this.translate(field, 'fields', scope);
                }
                else {
                    if (field.indexOf('Id') === field.length - 2) {
                        const baseField = field.substr(0, field.length - 2);

                        if (this.getMetadata().get(['entityDefs', scope, 'fields', baseField])) {
                            label = this.translate(baseField, 'fields', scope) +
                                ' (' + this.translate('id', 'fields') + ')';
                        }
                    }
                    else if (field.indexOf('Name') === field.length - 4) {
                        const baseField = field.substr(0, field.length - 4);

                        if (this.getMetadata().get(['entityDefs', scope, 'fields', baseField])) {
                            label = this.translate(baseField, 'fields', scope) +
                                ' (' + this.translate('name', 'fields') + ')';
                        }
                    }
                    else if (field.indexOf('Type') === field.length - 4) {
                        const baseField = field.substr(0, field.length - 4);

                        if (this.getMetadata().get(['entityDefs', scope, 'fields', baseField])) {
                            label = this.translate(baseField, 'fields', scope) +
                                ' (' + this.translate('type', 'fields') + ')';
                        }
                    }
                    else if (field.indexOf('phoneNumber') === 0) {
                        const phoneNumberType = field.substr(11);

                        const phoneNumberTypeLabel = this.getLanguage()
                            .translateOption(phoneNumberType, 'phoneNumber', scope);

                        label = this.translate('phoneNumber', 'fields', scope) +
                            ' (' + phoneNumberTypeLabel + ')';
                    }
                    else if (
                        field.indexOf('emailAddress') === 0 &&
                        parseInt(field.substr(12)).toString() === field.substr(12)
                    ) {
                        const emailAddressNum = field.substr(12);

                        label = this.translate('emailAddress', 'fields', scope) +
                            ' ' + emailAddressNum.toString();
                    }
                    else if (field.indexOf('Ids') === field.length - 3) {
                        const baseField = field.substr(0, field.length - 3);

                        if (this.getMetadata().get(['entityDefs', scope, 'fields', baseField])) {
                            label = this.translate(baseField, 'fields', scope) +
                                ' (' + this.translate('ids', 'fields') + ')';
                        }
                    }
                }

                if (!label) {
                    label = field;
                }

                $option = $('<option>')
                    .val(field)
                    .text(label);

                if (name) {
                    if (field === name) {
                        $option.prop('selected', true);
                    }
                    else if (name.toLowerCase().replace(/_/g, '') === field.toLowerCase()) {
                        $option.prop('selected', true);
                    }
                    else if (this.isCustomFieldHeaderMatch(name, field)) {
                        $option.prop('selected', true);
                    }
                }

                $select.append($option);
            });

            return $select;
        }

        /**
         * @param {string} header
         * @param {string} attribute
         * @return {boolean}
         */
        isCustomFieldHeaderMatch(header, attribute) {
            if (!this.customFieldAttributeLabels[attribute]) {
                return false;
            }

            const h = String(header).trim().toLowerCase().replace(/\s+/g, '');
            const a = attribute.toLowerCase();

            if (h === a || h.replace(/_/g, '') === a.replace(/_/g, '') ||
                h.replace(/_/g, '.') === a
            ) {
                return true;
            }

            const attrName =
                this.getMetadata().get(['app', 'customFields', 'attributeName']) ||
                'customFields';

            const prefix = attrName.toLowerCase() + '.';

            if (a.startsWith(prefix)) {
                const valueKey = a.slice(prefix.length);

                if (h === valueKey || h.replace(/_/g, '.') === valueKey) {
                    return true;
                }
            }

            return false;
        }
    };
});
