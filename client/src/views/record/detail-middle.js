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

/** @module views/record/detail-middle */

import View from 'view';

/**
 * A detail-middle record view.
 */
class DetailMiddleRecordView extends View {

    events = {
        /** @this module:views/record/detail-middle */
        'click .panel > .panel-heading': function (e) {
            if (
                $(e.target).closest('a, button, input, textarea, select, .action, .dropdown-menu').length
            ) {
                return;
            }

            const name = $(e.currentTarget).closest('.panel').attr('data-name');

            if (!name) {
                return;
            }

            this.togglePanelCollapsed(name);
        },
    }

    init() {
        this.recordHelper = this.options.recordHelper;
        this.scope = this.model.entityType;

        this.applyStoredCollapseState();
    }

    data() {
        return {
            hiddenPanels: this.recordHelper.getHiddenPanels(),
            hiddenFields: this.recordHelper.getHiddenFields(),
            collapsedPanels: this.getCollapsedPanels(),
        };
    }

    afterRender() {
        Object.keys(this.getCollapsedPanels()).forEach(name => {
            this.ensurePanelHeadingChevron(name);
            this.applyPanelCollapsedState(name, this.isPanelCollapsed(name));
        });

        this.$el.find('> .panel[data-name]').each((i, el) => {
            const name = $(el).attr('data-name');

            if (!name) {
                return;
            }

            if (this.recordHelper.getPanelStateParam(name, 'collapsed') === null) {
                this.recordHelper.setPanelStateParam(name, 'collapsed', this.getPanelCollapsedStored(name));
            }

            this.ensurePanelHeadingChevron(name);
            this.applyPanelCollapsedState(name, this.isPanelCollapsed(name));
        });
    }

    /**
     * @private
     * @param {string} name
     */
    ensurePanelHeadingChevron(name) {
        if (!this.isRendered()) {
            return;
        }

        const $panel = this.$el.find(`.panel[data-name="${name}"]`).first();

        if (!$panel.length) {
            return;
        }

        const $title = $panel.find('> .panel-heading > .panel-title').first();

        if (!$title.length || $title.find('> .panel-collapse-chevron').length) {
            return;
        }

        $title.prepend('<span class="panel-collapse-chevron fas"></span> ');
    }

    /**
     * @private
     */
    applyStoredCollapseState() {
        this.getCollapsiblePanelNameList().forEach(name => {
            if (this.recordHelper.getPanelStateParam(name, 'collapsed') !== null) {
                return;
            }

            this.recordHelper.setPanelStateParam(name, 'collapsed', this.getPanelCollapsedStored(name));
        });
    }

    /**
     * @private
     * @return {string[]}
     */
    getCollapsiblePanelNameList() {
        const layoutDefs = this.options.layoutDefs;

        const panelList = Array.isArray(layoutDefs) ?
            layoutDefs :
            (Array.isArray(layoutDefs?.layout) ? layoutDefs.layout : []);

        return panelList
            .filter(panel => panel && panel.name && panel.label)
            .map(panel => panel.name);
    }

    /**
     * @private
     * @return {Object.<string, boolean>}
     */
    getCollapsedPanels() {
        const map = {};

        this.getCollapsiblePanelNameList().forEach(name => {
            map[name] = this.isPanelCollapsed(name);
        });

        return map;
    }

    /**
     * @private
     * @param {string} name
     * @return {boolean}
     */
    isPanelCollapsed(name) {
        const value = this.recordHelper.getPanelStateParam(name, 'collapsed');

        if (value === null) {
            return this.getPanelCollapsedStored(name);
        }

        return !!value;
    }

    /**
     * @private
     * @param {string} name
     * @return {string}
     */
    getPanelCollapsedStorageKey(name) {
        const userId = this.getUser().id || 'anonymous';

        return `record-panel-collapse:${userId}:${this.scope}:middle:${name}`;
    }

    /**
     * @private
     * @param {string} name
     * @return {boolean}
     */
    getPanelCollapsedStored(name) {
        try {
            return localStorage.getItem(this.getPanelCollapsedStorageKey(name)) === 'true';
        }
        catch (e) {
            return false;
        }
    }

    /**
     * @private
     * @param {string} name
     * @param {boolean} collapsed
     */
    setPanelCollapsedStored(name, collapsed) {
        try {
            localStorage.setItem(this.getPanelCollapsedStorageKey(name), collapsed ? 'true' : 'false');
        }
        catch (e) {}
    }

    /**
     * @private
     * @param {string} name
     */
    togglePanelCollapsed(name) {
        const collapsed = !this.isPanelCollapsed(name);

        this.recordHelper.setPanelStateParam(name, 'collapsed', collapsed);
        this.setPanelCollapsedStored(name, collapsed);
        this.applyPanelCollapsedState(name, collapsed);
    }

    /**
     * @private
     * @param {string} name
     * @param {boolean} collapsed
     */
    applyPanelCollapsedState(name, collapsed) {
        if (!this.isRendered()) {
            return;
        }

        this.ensurePanelHeadingChevron(name);

        const $panel = this.$el.find(`.panel[data-name="${name}"]`).first();

        if (!$panel.length) {
            return;
        }

        $panel.toggleClass('is-collapsed', collapsed);
        $panel.find('> .panel-body').toggleClass('hidden', collapsed);

        const $icon = $panel.find('> .panel-heading .panel-collapse-chevron').first();

        if ($icon.length) {
            $icon
                .toggleClass('fa-chevron-down', !collapsed)
                .toggleClass('fa-chevron-right', collapsed);
        }
    }

    /**
     * Show a panel.
     *
     * @param {string} name
     */
    showPanel(name) {
        if (this.recordHelper.getPanelStateParam(name, 'hiddenLocked')) {
            return;
        }

        this.showPanelInternal(name);

        this.recordHelper.setPanelStateParam(name, 'hidden', false);
    }

    /**
     * @param {string} name
     */
    showPanelInternal(name) {
        if (this.isRendered()) {
            this.$el.find('.panel[data-name="'+name+'"]').removeClass('hidden');
        }

        const wasShown = !this.recordHelper.getPanelStateParam(name, 'hidden');

        if (
            !wasShown &&
            this.options.panelFieldListMap &&
            this.options.panelFieldListMap[name]
        ) {
            this.options.panelFieldListMap[name].forEach(field => {
                const view = this.getFieldView(field);

                if (!view) {
                    return;
                }

                view.reRender();
            });
        }
    }

    /**
     * Hide a panel.
     *
     * @param {string} name
     */
    hidePanel(name) {
        this.hidePanelInternal(name);

        this.recordHelper.setPanelStateParam(name, 'hidden', true);
    }

    /**
     * @public
     * @param {string} name A name.
     */
    hidePanelInternal(name) {
        if (this.isRendered()) {
            this.$el.find('.panel[data-name="'+name+'"]').addClass('hidden');
        }
    }

    /**
     * Hide a field.
     *
     * @param {string} name A name.
     */
    hideField(name) {
        this.recordHelper.setFieldStateParam(name, 'hidden', true);

        const processHtml = () => {
            const fieldView = this.getFieldView(name);

            if (fieldView) {
                const $field = fieldView.$el;
                const $cell = $field.closest('.cell[data-name="' + name + '"]');
                const $label = $cell.find('label.control-label[data-name="' + name + '"]');

                $field.addClass('hidden');
                $label.addClass('hidden');
                $cell.addClass('hidden-cell');
            }
            else {
                this.$el.find('.cell[data-name="' + name + '"]').addClass('hidden-cell');
                this.$el.find('.field[data-name="' + name + '"]').addClass('hidden');
                this.$el.find('label.control-label[data-name="' + name + '"]').addClass('hidden');
            }
        };

        if (this.isRendered()) {
            processHtml();
        }
        else {
            this.once('after:render', () => {
                processHtml();
            });
        }

        const view = this.getFieldView(name);

        if (view) {
            view.setDisabled();
        }
    }

    /**
     * Show a field.
     *
     * @param {string} name A name.
     */
    showField(name) {
        if (this.recordHelper.getFieldStateParam(name, 'hiddenLocked')) {
            return;
        }

        this.recordHelper.setFieldStateParam(name, 'hidden', false);

        const processHtml = () => {
            const fieldView = this.getFieldView(name);

            if (fieldView) {
                const $field = fieldView.$el;
                const $cell = $field.closest('.cell[data-name="' + name + '"]');
                const $label = $cell.find('label.control-label[data-name="' + name + '"]');

                $field.removeClass('hidden');
                $label.removeClass('hidden');
                $cell.removeClass('hidden-cell');
            }
            else {
                this.$el.find('.cell[data-name="' + name + '"]').removeClass('hidden-cell');
                this.$el.find('.field[data-name="' + name + '"]').removeClass('hidden');
                this.$el.find('label.control-label[data-name="' + name + '"]').removeClass('hidden');
            }
        };

        if (this.isRendered()) {
            processHtml();
        }
        else {
            this.once('after:render', () => {
                processHtml();
            });
        }

        const view = this.getFieldView(name);

        if (view) {
            if (!view.disabledLocked) {
                view.setNotDisabled();
            }
        }
    }

    /**
     * Get field views.
     *
     * @return {Object.<string, module:views/fields/base>}
     */
    getFieldViews() {
        const fieldViews = {};

        for (const viewKey in this.nestedViews) {
            // noinspection JSUnresolvedReference
            const name = this.nestedViews[viewKey].name;

            fieldViews[name] = this.nestedViews[viewKey];
        }

        return fieldViews;
    }

    /**
     * Get a field view.
     *
     * @param {string} name A field name.
     * @return {module:views/fields/base}
     */
    getFieldView(name) {
        return (this.getFieldViews() || {})[name];
    }

    /**
     * For backward compatibility.
     *
     * @todo Remove.
     */
    getView(name) {
        let view = super.getView(name);

        if (!view) {
            view = this.getFieldView(name);
        }

        return view;
    }
}

// noinspection JSUnusedGlobalSymbols
export default DetailMiddleRecordView;
