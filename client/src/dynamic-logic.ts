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

import type BaseRecordView from 'views/record/base';

/**
 * Definitions.
 */
export interface Defs {
    /**
     * Fields.
     */
    fields?: Record<string, FieldDefs>;
    /**
     * Panels.
     */
    panels?: Record<string, PanelDefs>;
    /**
     * Options.
     */
    options?: Record<string, Record<string, any>[]>;
    /**
     * Cascading fields.
     */
    cascadingFields?: Record<string, CascadingFieldDefs>;
}

/**
 * Field definitions.
 */
interface FieldDefs {
    /**
     * Visibility conditions.
     */
    visible?: { conditionGroup: ConditionGroup; };
    /**
     * Requiring conditions.
     */
    required?: { conditionGroup: ConditionGroup; };
    /**
     * Read-only conditions.
     */
    readOnly?: { conditionGroup: ConditionGroup; };
    /**
     * Read-only saved conditions.
     */
    readOnlySaved?: { conditionGroup: ConditionGroup; };
    /**
     * Invalidity conditions.
     */
    invalid?: { conditionGroup: ConditionGroup; };
}

/**
 * Panel definitions.
 */
interface PanelDefs {
    /**
     * Visibility conditions.
     */
    visible?: {
        conditionGroup: ConditionGroup;
    };
    /**
     * Style condition.
     */
    styled?: {
        conditionGroup: ConditionGroup;
    };
}

interface CascadingFieldDefs {
    items?: {
        localField: string;
        foreignField: string;
        matchRequired: boolean;
    }[];
}

type ConditionGroup = Record<string, unknown>[];

import {inject} from 'di';
import FieldManager from 'field-manager';

/**
 * Dynamic logic. Handles form appearance and behavior depending on conditions.
 *
 * @internal Instantiated in advanced-pack.
 */
class DynamicLogic {

    private defs: Defs

    private recordView: BaseRecordView

    private readonly fieldTypeList: ('visible' | 'required' | 'readOnlySaved' | 'readOnly')[] = [
        'visible',
        'required',
        'readOnlySaved',
        'readOnly',
    ]

    private readonly panelTypeList: ('visible' | 'styled')[] = [
        'visible',
        'styled',
    ]

    private readonly cascadingClearDefs: Record<string, string[]>

    @inject(FieldManager)
    private fieldManager: FieldManager

    /**
     * @param  defs Definitions.
     * @param  recordView A record view.
     */
    constructor(defs: Defs, recordView: BaseRecordView) {
        this.defs = defs ?? {};
        this.recordView = recordView;

        this.cascadingClearDefs = this.buildCascadingClearDefs();
    }

    /**
     * Process.
     *
     * @param options Options.
     */
    process(options: {action?: string | 'ui'} = {}) {
        const fields = this.defs.fields ?? {};

        Object.keys(fields).forEach(field => {
            const item = fields[field] || {};

            let readOnlyIsProcessed = false;

            this.fieldTypeList.forEach(type => {
                if (!(type in item) || !item[type]) {
                    return;
                }

                const typeItem = item[type] || {} as {conditionGroup?: ConditionGroup};

                if (!typeItem.conditionGroup) {
                    return;
                }

                if (type === 'readOnlySaved') {
                    if (this.recordView.model.isNew()) {
                        return;
                    }

                    if (this.checkConditionGroupInternal(typeItem.conditionGroup, 'and', true)) {
                        this.makeFieldReadOnlyTrue(field);

                        readOnlyIsProcessed = true;
                    } else {
                        this.makeFieldReadOnlyFalse(field);
                    }

                    return
                }

                const result = this.checkConditionGroupInternal(typeItem.conditionGroup);

                if (type === 'required' && !readOnlyIsProcessed) {
                    result ?
                        this.makeFieldRequiredTrue(field) :
                        this.makeFieldRequiredFalse(field);

                    return;
                }

                if (type === 'readOnly') {
                    result ?
                        this.makeFieldReadOnlyTrue(field) :
                        this.makeFieldReadOnlyFalse(field);

                    return;
                }

                if (type === 'visible') {
                    result ?
                        this.makeFieldVisibleTrue(field) :
                        this.makeFieldVisibleFalse(field);
                }
            });
        });

        const panels = this.defs.panels ?? {};

        Object.keys(panels).forEach(panel => {
            this.panelTypeList.forEach(type => {
                this.processPanel(panel, type);
            });
        });

        const optionsDefs = this.defs.options ?? {};

        Object.keys(optionsDefs).forEach(field => {
            const itemList = optionsDefs[field];

            if (!optionsDefs[field]) {
                return;
            }

            let isMet = false;

            for (const item of itemList) {
                if (this.checkConditionGroupInternal(item.conditionGroup)) {
                    this.setOptionList(field, item.optionList || []);

                    isMet = true;

                    break;
                }
            }

            if (!isMet) {
                this.resetOptionList(field);
            }
        });

        if (options.action === 'ui') {
            this.processCascadingClear();
        }
    }

    private buildCascadingClearDefs(): Record<string, string[]> {
        const fields = this.defs.cascadingFields ?? null;

        if (!fields || Object.keys(fields).length === 0) {
            return {};
        }

        const model = this.recordView.model;

        const map: Record<string, string[]> = {};

        for (const [field, defs] of Object.entries(fields)) {
            const items = defs?.items;

            if (!items || !items.length) {
                continue;
            }

            for (const item of items) {
                if (!item.matchRequired) {
                    continue;
                }

                const type = model.getFieldType(item.localField);

                const idAttribute = type === 'linkMultiple' ? item.localField + 'Ids' : item.localField + 'Id';

                map[idAttribute] ??= [];

                const attributeList = model.entityType ?
                    this.fieldManager.getEntityTypeFieldAttributeList(model.entityType, field) : [];

                map[idAttribute].push(...attributeList);
            }
        }

        for (const [attribute, a] of Object.entries(map)) {
            map[attribute] = a.filter((it, i) => a.indexOf(it) === i);
        }

        return map;
    }

    private processCascadingClear() {
        const attributeList = Object.keys(this.cascadingClearDefs);
        const model = this.recordView.model;

        const fieldsToUnset = [];

        for (const attribute of attributeList) {
            if (model.hasChanged(attribute)) {
                fieldsToUnset.push(...this.cascadingClearDefs[attribute]);
            }
        }

        if (!fieldsToUnset.length) {
            return;
        }

        const map = fieldsToUnset.reduce((p, it) => {
            p[it] = null;

            return p;
        }, {} as any)

        setTimeout(() => {
            model.setMultiple(map)
        }, 0)
    }

    /**
     * @param panel A panel name.
     * @param type A type.
     */
    private processPanel(panel: string, type: 'visible' | 'styled') {
        const panels = this.defs.panels ?? {};
        const item = (panels[panel] || {});

        if (!(type in item)) {
            return;
        }

        const typeItem = (item[type] ?? {}) as {conditionGroup?: ConditionGroup};

        if (!typeItem.conditionGroup) {
            return;
        }

        const result = this.checkConditionGroupInternal(typeItem.conditionGroup);

        if (type === 'visible') {
            result ?
                this.makePanelVisibleTrue(panel) :
                this.makePanelVisibleFalse(panel);
        } if (type === 'styled') {
            result ?
                this.makePanelStyledTrue(panel) :
                this.makePanelStyledFalse(panel);
        }
    }

    /**
     * Check a condition group.
     *
     * @param data A condition group.
     * @returns {boolean}
     */
    checkConditionGroup(data: ConditionGroup): boolean {
        return this.checkConditionGroupInternal(data);
    }

    private checkConditionGroupInternal(
        data: ConditionGroup,
        type: 'and' | 'or' | 'not' = 'and',
        preSave: boolean = false,
    ): boolean {

        type = type || 'and';

        let list: Defs[];
        let result = false;

        if (type === 'and') {
            list = data || [];

            result = true;

            for (const it of list) {
                if (!this.checkCondition(it, preSave)) {
                    result = false;

                    break;
                }
            }
        } else if (type === 'or') {
            list = data || [];

            for (const it of list) {
                if (this.checkCondition(it, preSave)) {
                    result = true;

                    break;
                }
            }
        } else if (type === 'not') {
            if (data) {
                result = !this.checkCondition(data, preSave);
            }
        }

        return result;
    }

    private getAttributeValue(attribute: string, preSave: boolean): unknown | undefined {
        if (attribute.startsWith('$')) {
            if (attribute === '$user.id') {
                return this.recordView.getUser().id;
            }

            if (attribute === '$user.teamsIds') {
                return this.recordView.getUser().getTeamIdList();
            }

            if (attribute === '$user.rolesIds') {
                return this.recordView.getUser().getRoleIdList();
            }

            if (attribute === '$user.featureVerticals') {
                return this.recordView.getHelper().getAppParam('userFeatureVerticals') || [];
            }
        }

        if (preSave) {
            return this.recordView.getPreSaveAttributes()[attribute];
        }

        if (!this.recordView.model.has(attribute)) {
            return undefined;
        }

        return this.recordView.model.get(attribute);
    }

    private checkCondition(defs: Record<string, any>, preSave: boolean): boolean {
        defs = defs || {};

        const type = defs.type || 'equals';

        if (['or', 'and', 'not'].includes(type)) {
            return this.checkConditionGroupInternal(defs.value, type, preSave);
        }

        const attribute = defs.attribute;
        const value = defs.value;

        if (!attribute) {
            return false;
        }

        const setValue = this.getAttributeValue(attribute, preSave) as any;

        if (type === 'equals') {
            return setValue === value;
        }

        if (type === 'notEquals') {
            return setValue !== value;
        }

        if (type === 'isEmpty') {
            if (Array.isArray(setValue)) {
                return !setValue.length;
            }

            return setValue === null || (setValue === '') || typeof setValue === 'undefined';
        }

        if (type === 'isNotEmpty') {
            if (Array.isArray(setValue)) {
                return !!setValue.length;
            }

            return setValue !== null && (setValue !== '') && typeof setValue !== 'undefined';
        }

        if (type === 'isTrue') {
            return !!setValue;
        }

        if (type === 'isFalse') {
            return !setValue;
        }

        if (type === 'contains' || type === 'has') {
            if (!setValue || !hasIncludes(setValue)) {
                return false;
            }

            return setValue.includes(value);
        }

        if (type === 'notContains' || type === 'notHas') {
            if (!setValue || !hasIncludes(setValue)) {
                return true;
            }

            return !setValue.includes(value);
        }

        if (type === 'startsWith') {
            if (!setValue || !hasIndexOf(setValue)) {
                return false;
            }

            return setValue.indexOf(value) === 0;
        }

        if (type === 'endsWith') {
            if (!setValue || !hasIndexOf(setValue)) {
                return false;
            }

            return setValue.indexOf(value) === (setValue as any).length - value.length;
        }

        if (type === 'matches') {
            if (!setValue || typeof setValue !== 'string') {
                return false;
            }

            const match = /^\/(.*)\/([a-z]*)$/.exec(value);

            if (!match || match.length < 2) {
                return false;
            }

            return (new RegExp(match[1], match[2])).test(setValue);
        }

        if (type === 'greaterThan') {
            return setValue > value;
        }

        if (type === 'lessThan') {
            return setValue < value;
        }

        if (type === 'greaterThanOrEquals') {
            return setValue >= value;
        }

        if (type === 'lessThanOrEquals') {
            return setValue <= value;
        }

        if (type === 'in') {
            return !!~value.indexOf(setValue);
        }

        if (type === 'notIn') {
            return !~value.indexOf(setValue);
        }

        if (type === 'isToday') {
            const dateTime = this.recordView.getDateTime();

            if (!setValue || typeof setValue !== 'string') {
                return false;
            }

            if (setValue.length > 10) {
                return dateTime.toMoment(setValue).isSame(dateTime.getNowMoment(), 'day');
            }

            return dateTime.toMomentDate(setValue).isSame(dateTime.getNowMoment(), 'day');
        }

        if (type === 'inFuture') {
            const dateTime = this.recordView.getDateTime();

            if (!setValue || typeof setValue !== 'string') {
                return false;
            }

            if (setValue.length > 10) {
                return dateTime.toMoment(setValue).isAfter(dateTime.getNowMoment(), 'second');
            }

            return dateTime.toMomentDate(setValue).isAfter(dateTime.getNowMoment(), 'day');
        }

        if (type === 'inPast') {
            const dateTime = this.recordView.getDateTime();

            if (!setValue || typeof setValue !== 'string') {
                return false;
            }

            if (setValue.length > 10) {
                return dateTime.toMoment(setValue).isBefore(dateTime.getNowMoment(), 'second');
            }

            return dateTime.toMomentDate(setValue).isBefore(dateTime.getNowMoment(), 'day');
        }

        return false;
    }

    private setOptionList(field: string, optionList: string[]) {
        this.recordView.setFieldOptionList(field, optionList);
    }

    private resetOptionList(field: string) {
        this.recordView.resetFieldOptionList(field);
    }

    private makeFieldVisibleTrue(field: string) {
        this.recordView.showField(field);
    }

    private makeFieldVisibleFalse(field: string) {
        this.recordView.hideField(field);
    }

    private makeFieldRequiredTrue(field: string) {
        this.recordView.setFieldRequired(field);
    }

    private makeFieldRequiredFalse(field: string) {
        this.recordView.setFieldNotRequired(field);
    }

    private makeFieldReadOnlyTrue(field: string) {
        this.recordView.setFieldReadOnly(field);
    }

    private makeFieldReadOnlyFalse(field: string) {
        this.recordView.setFieldNotReadOnly(field);
    }

    private makePanelVisibleTrue(panel: string) {
        this.recordView.showPanel(panel, 'dynamicLogic');
    }

    private makePanelVisibleFalse(panel: string) {
        this.recordView.hidePanel(panel, false, 'dynamicLogic');
    }

    private makePanelStyledTrue(panel: string) {
        this.recordView.stylePanel(panel);
    }

    private makePanelStyledFalse(panel: string) {
        this.recordView.unstylePanel(panel);
    }

    /**
     * Add a panel-visible condition.
     *
     * @param name A panel name.
     * @param item Condition definitions.
     */
    addPanelVisibleCondition(name: string, item: {conditionGroup: ConditionGroup}) {
        this.defs.panels = this.defs.panels ?? {};
        this.defs.panels[name] = this.defs.panels[name] ?? {};

        this.defs.panels[name].visible = item;

        this.processPanel(name, 'visible');
    }

    /**
     * Add a panel-styled condition.
     *
     * @param name A panel name.
     * @param item Condition definitions.
     */
    addPanelStyledCondition(name: string, item: {conditionGroup: ConditionGroup}) {
        this.defs.panels = this.defs.panels ?? {};
        this.defs.panels[name] = this.defs.panels[name] ?? {};

        this.defs.panels[name].styled = item;

        this.processPanel(name, 'styled');
    }
}

export default DynamicLogic;

type HasIncludes = {
    includes(value: any): boolean;
};

function hasIncludes(item: any): item is HasIncludes {
    return typeof item?.includes === 'function';
}

type HasIndexOf = {
    indexOf(value: any): number;
};

function hasIndexOf(item: any): item is HasIndexOf {
    return typeof item?.indexOf === 'function';
}
