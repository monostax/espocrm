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
 * Adds tenant custom-field placeholders to the Email Template Insert Field picker.
 *
 * - Host:    {Contact.customFields.address.city}
 * - Related: {Contact.account.customFields.address.city} (belongsTo one-hop)
 */
define('global:views/email-template/fields/insert-field', [
    'views/email-template/fields/insert-field',
], function (Dep) {

    return class extends Dep {

        /**
         * @type {Record<string, Array>}
         */
        customFieldPlaceholders = {};

        setup() {
            super.setup();

            if (this.mode === this.MODE_LIST) {
                return;
            }

            this.wait(this.loadCustomFieldPlaceholders());
        }

        /**
         * @return {Promise}
         */
        loadCustomFieldPlaceholders() {
            const enabled =
                this.getMetadata().get(['app', 'customFields', 'entityTypeList']) || [];
            const scopes = Array.isArray(enabled) ? enabled : [];

            if (!scopes.length) {
                return Promise.resolve();
            }

            const attrName =
                this.getMetadata().get(['app', 'customFields', 'attributeName']) ||
                'customFields';

            const promises = scopes.map(entityType => {
                if (!this.getAcl().checkScope(entityType)) {
                    return Promise.resolve();
                }

                const url =
                    'CustomField/action/templateVariables?entityType=' +
                    encodeURIComponent(entityType);

                return Espo.Ajax.getRequest(url)
                    .then(response => {
                        const list = (response && response.list) || [];

                        if (!list.length) {
                            return;
                        }

                        this.customFieldPlaceholders[entityType] = list;

                        this.injectHostCustomFields(entityType, list, attrName);
                    })
                    .catch(() => {});
            });

            return Promise.all(promises).then(() => {
                this.injectRelatedCustomFields(scopes, attrName);
            });
        }

        /**
         * @param {string} entityType
         * @param {Array} list
         * @param {string} attrName
         */
        injectHostCustomFields(entityType, list, attrName) {
            if (!this.entityFields || !this.entityFields[entityType]) {
                return;
            }

            if (!this.translatedOptions[entityType]) {
                this.translatedOptions[entityType] = {};
            }

            list.forEach(item => {
                const field = attrName + '.' + item.valueKey;
                const group = item.groupLabel ? item.groupLabel + ' · ' : '';
                const label =
                    'Custom Fields · ' + group + (item.label || item.valueKey);

                if (!this.entityFields[entityType].includes(field)) {
                    this.entityFields[entityType].push(field);
                }

                this.translatedOptions[entityType][field] = label;
            });
        }

        /**
         * One-hop belongsTo: Contact + account → Account CF defs.
         *
         * @param {string[]} enabledScopes
         * @param {string} attrName
         */
        injectRelatedCustomFields(enabledScopes, attrName) {
            if (!this.entityFields) {
                return;
            }

            Object.keys(this.entityFields).forEach(scope => {
                if (scope === 'Person') {
                    return;
                }

                /** @type {Record<string, Record>} */
                const links = this.getMetadata().get(`entityDefs.${scope}.links`) || {};

                Object.keys(links).forEach(link => {
                    const linkDefs = links[link] || {};

                    if (linkDefs.type !== 'belongsTo') {
                        return;
                    }

                    const foreignScope = linkDefs.entity;

                    if (!foreignScope || !enabledScopes.includes(foreignScope)) {
                        return;
                    }

                    if (linkDefs.disabled || linkDefs.utility) {
                        return;
                    }

                    if (
                        this.getMetadata().get(['entityAcl', scope, 'links', link, 'onlyAdmin']) ||
                        this.getMetadata().get(['entityAcl', scope, 'links', link, 'forbidden']) ||
                        this.getMetadata().get(['entityAcl', scope, 'links', link, 'internal'])
                    ) {
                        return;
                    }

                    if (!this.getAcl().checkScope(foreignScope)) {
                        return;
                    }

                    const list = this.customFieldPlaceholders[foreignScope];

                    if (!list || !list.length) {
                        return;
                    }

                    if (!this.translatedOptions[scope]) {
                        this.translatedOptions[scope] = {};
                    }

                    const linkLabel = this.translate(link, 'links', scope);

                    list.forEach(item => {
                        const field = link + '.' + attrName + '.' + item.valueKey;
                        const group = item.groupLabel ? item.groupLabel + ' · ' : '';
                        const label =
                            linkLabel +
                            ' · Custom Fields · ' +
                            group +
                            (item.label || item.valueKey);

                        if (!this.entityFields[scope].includes(field)) {
                            this.entityFields[scope].push(field);
                        }

                        this.translatedOptions[scope][field] = label;
                    });
                });
            });
        }
    };
});
