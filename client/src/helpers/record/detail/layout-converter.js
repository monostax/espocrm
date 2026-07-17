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

import {inject} from 'di';
import Language from 'language';
import ViewHelper from 'view-helper';
import FieldManager from 'field-manager';

/**
 * @internal
 */
class LayoutConverter {

    /**
     * @type {Language}
     * @private
     */
    @inject(Language)
    language

    /**
     * @type {ViewHelper}
     * @private
     */
    @inject(ViewHelper)
    viewHelper

    /**
     * @type {FieldManager}
     * @private
     */
    @inject(FieldManager)
    fieldManager

    /**
     * Convert a detail layout to an internal layout.
     *
     * @param {import('views/record/detail').PanelDefs[]} simplifiedLayout A detail layout.
     * @param {{
     *     selector: string,
     *     id: string,
     *     entityType: string | null,
     *     model: import('model').default,
     *     panelFieldListMap: Record<string, string[]>,
     *     middlePanelDefs: Record<string, any>,
     *     middlePanelDefsList: Record<string, any>[],
     *     underShowMoreDetailPanelList: string[],
     *     dynamicLogic: import('dynamic-logic').default | null,
     *     dynamicLogicDefs: import('dynamic-logic').Defs | null,
     *     recordHelper: import('view-record-helper').default,
     *     hidePanel: (name: string) => void,
     *     validateField: (name: string) => boolean,
     *     readOnly: boolean,
     *     readOnlyLocked: boolean,
     *     inlineEditDisabled: boolean,
     *     dataObject: Record<string, any>,
     *     fieldsMode: 'detail' | 'edit' | 'list',
     * }} options
     * @return {Record<string, any>[]}
     */
    convert(simplifiedLayout, options) {
        const layout = [];
        const el = options.selector || '#' + options.id;

        const panelFieldListMap = options.panelFieldListMap;
        const middlePanelDefs = options.middlePanelDefs;
        const middlePanelDefsList = options.middlePanelDefsList;
        const underShowMoreDetailPanelList = options.underShowMoreDetailPanelList;

        const recordHelper = options.recordHelper;
        const dynamicLogic = options.dynamicLogic;
        const dynamicLogicDefs = options.dynamicLogicDefs;
        const entityType = options.entityType;

        let tabNumber = -1;

        for (let p = 0; p < simplifiedLayout.length; p++) {
            const item = simplifiedLayout[p];

            const panel = {};

            const tabBreak = item.tabBreak || p === 0;

            if (tabBreak) {
                tabNumber++;
            }

            if ('customLabel' in item) {
                panel.label = item.customLabel;

                if (panel.label) {
                    panel.label = this.language.translate(panel.label, 'panelCustomLabels', entityType);
                }
            } else {
                panel.label = item.label || null;

                if (panel.label) {
                    panel.label = panel.label[0] === '$' ?
                        this.language.translate(panel.label.substring(1), 'panels', entityType) :
                        this.language.translate(panel.label, 'labels', entityType);
                }
            }

            panel.name = item.name || 'panel-' + p.toString();
            panel.style = item.style || 'default';
            panel.rows = [];
            panel.tabNumber = tabNumber;
            panel.noteText = item.noteText;
            panel.noteStyle = item.noteStyle || 'info';

            if (panel.noteText) {
                if (panel.noteText.startsWith('$') && !panel.noteText.includes(' ')) {
                    const label = panel.noteText.substring(1);

                    panel.noteText = this.language.translate(label, 'panelNotes', entityType);
                }

                panel.noteText = this.viewHelper.transformMarkdownText(panel.noteText);
            }

            middlePanelDefs[panel.name] = {
                name: panel.name,
                style: panel.style,
                tabNumber: panel.tabNumber,
                tabBreak: tabBreak,
                tabLabel: item.tabLabel,
            };

            middlePanelDefsList.push(middlePanelDefs[panel.name]);

            // noinspection JSUnresolvedReference
            if (item.dynamicLogicVisible && dynamicLogic) {
                dynamicLogic.addPanelVisibleCondition(panel.name, item.dynamicLogicVisible);
            }

            // noinspection JSUnresolvedReference
            if (item.dynamicLogicStyled && dynamicLogic) {
                dynamicLogic.addPanelStyledCondition(panel.name, item.dynamicLogicStyled);
            }

            // noinspection JSUnresolvedReference
            if (item.hidden && tabNumber === 0) {
                panel.hidden = true;

                options.hidePanel(panel.name);

                underShowMoreDetailPanelList.push(panel.name);
            }

            let lType = 'rows';

            if (item.columns) {
                lType = 'columns';

                panel.columns = [];
            }

            if (panel.name) {
                panelFieldListMap[panel.name] = [];
            }

            for (const [i, itemI] of item[lType].entries()) {
                const row = [];

                for (const cellDefs of itemI) {
                    if (cellDefs === false) {
                        row.push(false);

                        continue;
                    }

                    let view = cellDefs.view;
                    let name = cellDefs.name;

                    if (!name && view && typeof view === 'object') {
                        name = view.name;
                    }

                    if (!name) {
                        console.warn(`No 'name' specified in detail layout cell.`);

                        continue;
                    }

                    let selector;

                    if (view && typeof view === 'object') {
                        view.model = options.model;
                        view.mode = options.fieldsMode;

                        if (options.readOnly) {
                            view.setReadOnly();
                        }

                        selector = `.field[data-name="${name}"]`;
                    }

                    if (panel.name) {
                        panelFieldListMap[panel.name].push(name);
                    }

                    const type = cellDefs.type || options.model.getFieldType(name) || 'base';

                    view = view ||
                        options.model.getFieldParam(name, 'view') ||
                        this.fieldManager.getViewName(type);

                    const o = {
                        fullSelector: `${el} .middle .field[data-name="${name}"]`,
                        defs: {
                            name: name,
                            params: cellDefs.params || {},
                        },
                        mode: options.fieldsMode,
                    };

                    if (options.readOnly) {
                        o.readOnly = true;
                    }

                    if (cellDefs.readOnly) {
                        o.readOnly = true;
                        o.readOnlyLocked = true;
                    }

                    if (options.readOnlyLocked) {
                        o.readOnlyLocked = true;
                    }

                    if (options.inlineEditDisabled || cellDefs.inlineEditDisabled) {
                        o.inlineEditDisabled = true;
                    }

                    // noinspection JSUnresolvedReference
                    let fullWidth = cellDefs.fullWidth || false;

                    if (!fullWidth) {
                        if (item[lType][i].length === 1) {
                            fullWidth = true;
                        }
                    }

                    if (recordHelper.getFieldStateParam(name, 'hidden')) {
                        o.disabled = true;
                    }

                    if (recordHelper.getFieldStateParam(name, 'hiddenLocked')) {
                        o.disabledLocked = true;
                    }

                    if (recordHelper.getFieldStateParam(name, 'readOnly')) {
                        o.readOnly = true;
                    }

                    if (!o.readOnlyLocked && recordHelper.getFieldStateParam(name, 'readOnlyLocked')) {
                        o.readOnlyLocked = true;
                    }

                    if (recordHelper.getFieldStateParam(name, 'required') !== null) {
                        o.defs.params = o.defs.params || {};
                        o.defs.params.required = recordHelper.getFieldStateParam(name, 'required');
                    }

                    if (recordHelper.hasFieldOptionList(name)) {
                        o.customOptionList = recordHelper.getFieldOptionList(name);
                    }

                    o.validateCallback = () => options.validateField(name);

                    o.recordHelper = recordHelper;
                    o.dataObject = options.dataObject;

                    if (cellDefs.options) {
                        for (const optionName in cellDefs.options) {
                            if (typeof o[optionName] !== 'undefined') {
                                continue;
                            }

                            o[optionName] = cellDefs.options[optionName];
                        }
                    }

                    if (dynamicLogicDefs?.cascadingFields?.[name]) {
                        o.cascadingLogic = dynamicLogicDefs?.cascadingFields?.[name];
                    }

                    const cell = {
                        name: `${name}Field`,
                        view: view,
                        field: name,
                        fullSelector: `${el} .middle .field[data-name="${name}"]`,
                        fullWidth: fullWidth,
                        options: o,
                    };

                    if (selector) {
                        cell.selector = selector;
                    }

                    if ('labelText' in cellDefs) {
                        o.labelText = cellDefs.labelText;
                        cell.customLabel = cellDefs.labelText;
                    } else if (cellDefs.labelTranslation) {
                        o.labelText = this.language.translatePath(cellDefs.labelTranslation);
                        cell.customLabel = o.labelText;
                    }

                    if ('customLabel' in cellDefs) {
                        cell.customLabel = cellDefs.customLabel;
                    }

                    if ('label' in cellDefs) {
                        cell.label = cellDefs.label;
                    }

                    if (
                        view &&
                        typeof view === 'object' &&
                        !cell.customLabel &&
                        !cell.label &&
                        view.getLabelText()
                    ) {
                        cell.customLabel = view.getLabelText();
                    }

                    if ('customCode' in cellDefs) {
                        cell.customCode = cellDefs.customCode;
                    }

                    if ('noLabel' in cellDefs) {
                        cell.noLabel = cellDefs.noLabel;
                    }

                    if ('span' in cellDefs) {
                        cell.span = cellDefs.span;
                    }

                    row.push(cell);
                }

                panel[lType].push(row);
            }

            layout.push(panel);
        }

        return layout;
    }
}

export default LayoutConverter;
