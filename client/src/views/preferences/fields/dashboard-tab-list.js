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

import ArrayFieldView from 'views/fields/array';

/**
 * Tab list field used by the Edit Dashboard modal.
 *
 * Each tab row is rendered as a small card with:
 *   - drag handle
 *   - tab label (the button text in the dashboard header — also acts as the
 *     stable tab key when no `id` is present)
 *   - header title (the <h3> shown on top of the dashboard for this tab —
 *     defaults to the translation of "Dashboard" when empty)
 *   - description (the muted subtitle under the header title — defaults to
 *     the global `messages.dashboardDescription` translation when empty)
 *   - "show date range picker" checkbox — controls whether the shared
 *     dashboard date range picker is exposed while this tab is active
 *   - delete button
 *
 * The extra per-tab values are stored on the model as:
 *   - `translatedOptions[tabKey]`    → tab label
 *   - `tabTitles[tabKey]`            → header title
 *   - `tabDescriptions[tabKey]`      → description
 *   - `tabShowDateRange[tabKey]`     → boolean (default true)
 *
 * The owning modal seeds these maps from `options.dashboardLayout` and the
 * dashboard view writes them back when applying the modal result.
 */
// noinspection JSUnusedGlobalSymbols
export default class extends ArrayFieldView {

    maxItemLength = 36

    /**
     * @private
     * @type {Object.<string, string>}
     */
    tabTitles = {}

    /**
     * @private
     * @type {Object.<string, string>}
     */
    tabDescriptions = {}

    /**
     * @private
     * @type {Object.<string, boolean>}
     */
    tabShowDateRange = {}

    setup() {
        super.setup();

        this.translatedOptions = {};

        const list = this.model.get(this.name) || [];

        list.forEach(value => {
            this.translatedOptions[value] = value;
        });

        // Pre-seeded by the modal — clone defensively so re-renders don't
        // share state with the owning model.
        this.tabTitles = Object.assign({}, this.model.get('tabTitles') || {});
        this.tabDescriptions = Object.assign({}, this.model.get('tabDescriptions') || {});
        this.tabShowDateRange = Object.assign({}, this.model.get('tabShowDateRange') || {});

        this.validations.push('uniqueLabel');
    }

    /**
     * @inheritDoc
     */
    getItemHtml(value) {
        value = value.toString();

        const translatedValue = this.translatedOptions[value] || value;
        const titleValue = this.tabTitles[value] || '';
        const descriptionValue = this.tabDescriptions[value] || '';
        const showDateRange = this.tabShowDateRange[value] !== false;

        // Building a heredoc-style DOM tree here keeps the field readable
        // while still emitting the markup the parent ArrayFieldView expects
        // (a `data-value` row that can be sorted/removed).
        const container = document.createElement('div');
        container.className = 'list-group-item link-with-role dashboard-tab-list-item';
        container.dataset.value = value;

        // ── Top row: drag handle ─ name input ─ delete button ──────────
        const topRow = document.createElement('div');
        topRow.className = 'dashboard-tab-list-row dashboard-tab-list-row-top';

        const dragHandle = document.createElement('span');
        dragHandle.className = 'drag-handle';

        const dragIcon = document.createElement('span');
        dragIcon.className = 'fas fa-grip fa-sm';
        dragHandle.append(dragIcon);
        topRow.append(dragHandle);

        const nameWrap = document.createElement('div');
        nameWrap.className = 'dashboard-tab-list-name';

        const nameInput = document.createElement('input');
        nameInput.maxLength = this.maxItemLength;
        nameInput.dataset.name = 'translatedValue';
        nameInput.dataset.value = value;
        nameInput.className = 'role form-control input-sm';
        nameInput.value = translatedValue;
        nameInput.placeholder = this.translate('Tab Label', 'labels', 'Global');
        nameWrap.append(nameInput);
        topRow.append(nameWrap);

        const deleteWrap = document.createElement('div');
        deleteWrap.className = 'dashboard-tab-list-delete';

        const deleteAnchor = document.createElement('a');
        deleteAnchor.setAttribute('role', 'button');
        deleteAnchor.setAttribute('tabindex', '0');
        deleteAnchor.dataset.value = value;
        deleteAnchor.dataset.action = 'removeValue';

        const deleteIcon = document.createElement('span');
        deleteIcon.className = 'fas fa-times';
        deleteAnchor.append(deleteIcon);
        deleteWrap.append(deleteAnchor);
        topRow.append(deleteWrap);

        container.append(topRow);

        // ── Title row ──────────────────────────────────────────────────
        container.append(
            this._buildTextInputRow({
                value: value,
                dataName: 'tabTitle',
                label: this.translate('Header Title', 'labels', 'Global'),
                placeholder: this.translate('Dashboard', 'scopeNames'),
                inputValue: titleValue,
            })
        );

        // ── Description row ────────────────────────────────────────────
        container.append(
            this._buildTextareaRow({
                value: value,
                dataName: 'tabDescription',
                label: this.translate('Description', 'labels', 'Global'),
                placeholder: this.translate('dashboardDescription', 'messages', 'Global'),
                inputValue: descriptionValue,
            })
        );

        // ── Date range toggle row ──────────────────────────────────────
        container.append(
            this._buildCheckboxRow({
                value: value,
                dataName: 'tabShowDateRange',
                label: this.translate('Show Date Range Picker', 'labels', 'Global'),
                checked: showDateRange,
            })
        );

        return container.outerHTML;
    }

    /**
     * @private
     */
    _buildTextInputRow({value, dataName, label, placeholder, inputValue}) {
        const row = document.createElement('div');
        row.className = 'dashboard-tab-list-row';

        const lab = document.createElement('label');
        lab.className = 'dashboard-tab-list-label';
        lab.textContent = label;
        row.append(lab);

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control input-sm';
        input.dataset.name = dataName;
        input.dataset.value = value;
        input.placeholder = placeholder || '';
        input.value = inputValue || '';
        row.append(input);

        return row;
    }

    /**
     * @private
     */
    _buildTextareaRow({value, dataName, label, placeholder, inputValue}) {
        const row = document.createElement('div');
        row.className = 'dashboard-tab-list-row';

        const lab = document.createElement('label');
        lab.className = 'dashboard-tab-list-label';
        lab.textContent = label;
        row.append(lab);

        const textarea = document.createElement('textarea');
        textarea.className = 'form-control input-sm';
        textarea.dataset.name = dataName;
        textarea.dataset.value = value;
        textarea.placeholder = placeholder || '';
        textarea.rows = 2;
        textarea.value = inputValue || '';
        row.append(textarea);

        return row;
    }

    /**
     * @private
     */
    _buildCheckboxRow({value, dataName, label, checked}) {
        const row = document.createElement('div');
        row.className = 'dashboard-tab-list-row dashboard-tab-list-row-check';

        const lab = document.createElement('label');
        lab.className = 'dashboard-tab-list-check-label';

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.dataset.name = dataName;
        input.dataset.value = value;

        if (checked) {
            input.setAttribute('checked', 'checked');
        }

        lab.append(input);
        lab.append(document.createTextNode(' ' + label));
        row.append(lab);

        return row;
    }

    /**
     * @private
     * @return {boolean}
     */
    validateUniqueLabel() {
        const keyList = this.model.get(this.name) || [];
        const labels = this.model.get('translatedOptions') || {};
        const metLabelList = [];

        for (const key of keyList) {
            const label = labels[key];

            if (!label) {
                return true;
            }

            if (metLabelList.indexOf(label) !== -1) {
                return true;
            }

            metLabelList.push(label);
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    fetch() {
        const data = super.fetch();

        data.translatedOptions = {};
        data.tabTitles = {};
        data.tabDescriptions = {};
        data.tabShowDateRange = {};

        (data[this.name] || []).forEach(value => {
            const valueInternal = CSS.escape(value);

            data.translatedOptions[value] = this.$el
                .find(`input[data-name="translatedValue"][data-value="${valueInternal}"]`)
                .val() || value;

            data.tabTitles[value] = this.$el
                .find(`input[data-name="tabTitle"][data-value="${valueInternal}"]`)
                .val() || '';

            data.tabDescriptions[value] = this.$el
                .find(`textarea[data-name="tabDescription"][data-value="${valueInternal}"]`)
                .val() || '';

            data.tabShowDateRange[value] = this.$el
                .find(`input[data-name="tabShowDateRange"][data-value="${valueInternal}"]`)
                .is(':checked');
        });

        return data;
    }
}
