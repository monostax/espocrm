/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

/** @module views/modals/edit-field */

import EditModalView from 'views/modals/edit';

/**
 * A modal for editing a single field of an existing record.
 * Used for inline editing in list views.
 */
class EditFieldModalView extends EditModalView {

    fullFormDisabled = true
    sideDisabled = true
    bottomDisabled = true
    isCollapsible = false
    editView = 'views/record/edit-small'
    className = 'dialog dialog-record dialog-edit-field'

    /**
     * @private
     * @type {string}
     */
    field

    /**
     * @typedef {module:views/modals/edit~options & {
     *     field: string,
     * }} module:views/modals/edit-field~options
     */

    /**
     * @param {module:views/modals/edit-field~options} options
     */
    constructor(options) {
        super(options);
    }

    setup() {
        this.field = this.options.field;

        if (!this.field) {
            throw new Error("No field.");
        }

        if (!this.options.id) {
            throw new Error("No ID.");
        }

        super.setup();
    }

    /**
     * @inheritDoc
     */
    handleRecordViewOptions(options) {
        options.detailLayout = [
            {
                rows: [
                    [
                        {name: this.field},
                    ],
                ],
            },
        ];

        options.focusForCreate = true;
    }

    /**
     * @protected
     * @return {string}
     */
    composeHeaderHtml() {
        const wrapper = document.createElement('span');

        const scope = document.createElement('span');
        scope.textContent = this.getLanguage().translate(this.scope, 'scopeNames');

        wrapper.append(
            document.createTextNode(this.getLanguage().translate('Edit') + ' · '),
            scope,
        );

        const nameAttribute = this.getMetadata().get(`clientDefs.${this.entityType}.nameAttribute`) || 'name';
        const name = this.model.attributes[nameAttribute];

        if (name) {
            wrapper.append(' ', this.createSeparatorElement(), ' ', name);
        }

        const field = document.createElement('span');
        field.classList.add('text-soft');
        field.textContent = this.translate(this.field, 'fields', this.scope);

        wrapper.append(' ', this.createSeparatorElement(), ' ', field);

        return this.getHelper().getScopeColorIconHtml(this.scope) + wrapper.outerHTML;
    }

    /**
     * @private
     * @return {HTMLElement}
     */
    createSeparatorElement() {
        const separator = document.createElement('span');
        separator.classList.add('chevron-right');

        return separator;
    }
}

export default EditFieldModalView;
