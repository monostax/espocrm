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

/** @module views/fields/person-name */

import VarcharFieldView, {VarcharOptions, VarcharParams} from 'views/fields/varchar';
import Select from 'ui/select';
import {BaseViewSchema, FieldValidator} from 'views/fields/base';
import {AddressOptions, AddressParams} from 'views/fields/address';

/**
 * Parameters.
 */
export interface PersonNameParams extends VarcharParams {
}

/**
 * Options.
 */
export interface PersonNameOptions extends VarcharOptions {}

/**
 * A person name field.
 */
class PersonNameFieldView<
    S extends BaseViewSchema = BaseViewSchema,
    O extends PersonNameOptions = AddressOptions,
    P extends PersonNameParams = AddressParams,
> extends VarcharFieldView<S, O, P> {

    readonly type: string = 'personName'

    protected detailTemplate = 'fields/person-name/detail'
    protected editTemplate = 'fields/person-name/edit'
    // noinspection JSUnusedGlobalSymbols
    protected editTemplateLastFirst = 'fields/person-name/edit-last-first'
    // noinspection JSUnusedGlobalSymbols
    protected  editTemplateLastFirstMiddle = 'fields/person-name/edit-last-first-middle'
    // noinspection JSUnusedGlobalSymbols
    protected  editTemplateFirstMiddleLast = 'fields/person-name/edit-first-middle-last'

    protected validations: (FieldValidator | string)[] = [
        'required',
        'pattern',
    ]

    /**
     * @since 9.3.0
     */
    protected salutationField: string

    protected firstField: string

    protected lastField: string

    protected middleField: string

    protected salutationOptions: string

    private format: string

    private $salutation: JQuery
    private $first: JQuery
    private $last: JQuery
    private $middle: JQuery

    protected data(): Record<string, any> {
        const data = super.data();

        data.ucName = Espo.Utils.upperCaseFirst(this.name);
        data.salutationValue = this.model.get(this.salutationField);
        data.firstValue = this.model.get(this.firstField);
        data.lastValue = this.model.get(this.lastField);
        data.middleValue = this.model.get(this.middleField);
        data.salutationOptions = this.salutationOptions;
        data.salutationField = this.salutationField;

        if (this.isEditMode()) {
            data.firstMaxLength = this.model.getFieldParam(this.firstField, 'maxLength');
            data.lastMaxLength = this.model.getFieldParam(this.lastField, 'maxLength');
            data.middleMaxLength = this.model.getFieldParam(this.middleField, 'maxLength');
        }

        data.valueIsSet = this.model.has(this.firstField) || this.model.has(this.lastField);

        if (this.isDetailMode()) {
            data.isNotEmpty = !!data.firstValue || !!data.lastValue ||
                !!data.salutationValue || !!data.middleValue;
        }
        else if (this.isListMode()) {
            data.isNotEmpty = !!data.firstValue || !!data.lastValue || !!data.middleValue;
        }

        if (
            data.isNotEmpty && this.isDetailMode() ||
            this.isListMode()
        ) {
            data.formattedValue = this.getFormattedValue();
        }

        return data;
    }

    protected setup() {
        super.setup();

        this.searchTypeList =
            this.searchTypeList.filter(it => !['anyOf', 'noneOf'].includes(it));

        const ucName = Espo.Utils.upperCaseFirst(this.name);

        this.salutationField = 'salutation' + ucName;
        this.firstField = 'first' + ucName;
        this.lastField = 'last' + ucName;
        this.middleField = 'middle' + ucName;

        this.salutationOptions = this.model.getFieldParam(this.salutationField, 'options');
    }

    protected afterRender() {
        super.afterRender();

        if (this.isEditMode()) {
            this.$salutation = this.$el.find('[data-name="' + this.salutationField + '"]');
            this.$first = this.$el.find('[data-name="' + this.firstField + '"]');
            this.$last = this.$el.find('[data-name="' + this.lastField + '"]');

            if (this.formatHasMiddle()) {
                this.$middle = this.$el.find('[data-name="' + this.middleField + '"]');
            }

            this.$salutation.on('change', () => {
                this.trigger('change');
            });

            this.$first.on('change', () => {
                this.trigger('change');
            });

            this.$last.on('change', () => {
                this.trigger('change');
            });

            Select.init(this.$salutation);
        }
    }

    protected getFormattedValue(): string | null {
        let salutation = this.model.get(this.salutationField);
        const first = this.model.get(this.firstField);
        const last = this.model.get(this.lastField);
        const middle = this.model.get(this.middleField);

        if (salutation) {
            salutation = this.getLanguage()
                .translateOption(salutation, 'salutationName', this.model.entityType);
        }

        return this.formatName({
            salutation: salutation,
            first: first,
            middle: middle,
            last: last,
        });
    }

    /**
     * @internal
     */
    protected _getTemplateName(): string | null {
        if (this.isEditMode()) {
            const prop = 'editTemplate' + Espo.Utils.upperCaseFirst(this.getFormat().toString());

            if (prop in this) {
                // @ts-ignore
                return this[prop];
            }
        }

        return super._getTemplateName();
    }

    protected getFormat(): string {
        this.format = this.format || this.getConfig().get('personNameFormat') || 'firstLast';

        return this.format;
    }

    protected formatHasMiddle(): boolean {
        const format = this.getFormat();

        return format === 'firstMiddleLast' || format === 'lastFirstMiddle';
    }

    validateRequired(): boolean {
        const isRequired = this.isRequired();

        const validate = (name: string) => {
            if (this.model.isRequired(name)) {
                if (!this.model.get(name)) {
                    const msg = this.translate('fieldIsRequired', 'messages')
                        .replace('{field}', this.translate(name, 'fields', this.model.entityType));
                    this.showValidationMessage(msg, '[data-name="' + name + '"]');

                    return true;
                }
            }
        };

        if (isRequired) {
            if (!this.model.get(this.firstField) && !this.model.get(this.lastField)) {
                const msg = this.translate('fieldIsRequired', 'messages')
                    .replace('{field}', this.getLabelText());

                this.showValidationMessage(msg, '[data-name="'+this.lastField+'"]');

                return true;
            }
        }

        let result = false;

        result = validate(this.salutationField) || result;
        result = validate(this.firstField) || result;
        result = validate(this.lastField) || result;
        result = validate(this.middleField) || result;

        return result;
    }

    protected validatePattern(): boolean {
        let result = false;

        result = this.fieldValidatePattern(this.firstField) || result;
        result = this.fieldValidatePattern(this.lastField) || result;
        result = this.fieldValidatePattern(this.middleField) || result;

        return result;
    }

    protected hasRequiredMarker(): boolean {
        if (this.isRequired()) {
            return true;
        }

        return this.model.getFieldParam(this.salutationField, 'required') ||
            this.model.getFieldParam(this.firstField, 'required') ||
            this.model.getFieldParam(this.middleField, 'required') ||
            this.model.getFieldParam(this.lastField, 'required');
    }

    fetch(): Record<string, unknown> {
        const data = {} as any;

        data[this.salutationField] = (this.$salutation.val()) || null;
        data[this.firstField] = (this.$first.val() as string).trim() || null;
        data[this.lastField] = (this.$last.val() as string).trim() || null;

        if (this.formatHasMiddle()) {
            data[this.middleField] = (this.$middle.val() as string).trim() || null;
        }

        data[this.name] = this.formatName({
            first: data[this.firstField],
            last: data[this.lastField],
            middle: data[this.middleField],
        });

        return data;
    }

    fetchSearch(): Record<string, any> | null {
        if (this.fetchSearchType() === 'isNotEmpty') {
            return {
                type: 'isNotNull',
                attribute: this.name,
                value: null,
                data: {
                    type: 'isNotEmpty',
                },
            };
        }

        return super.fetchSearch();
    }

    private formatName(
        data: {
            first?: string;
            last?: string;
            middle?: string;
            salutation?: string;
        }
    ): string | null {

        let name: string | null;
        const format = this.getFormat();
        const arr = [];

        arr.push(data.salutation);

        if (format === 'firstLast') {
            arr.push(data.first);
            arr.push(data.last);
        } else if (format === 'lastFirst') {
            arr.push(data.last);
            arr.push(data.first);
        } else if (format === 'firstMiddleLast') {
            arr.push(data.first);
            arr.push(data.middle);
            arr.push(data.last);
        }
        else if (format === 'lastFirstMiddle') {
            arr.push(data.last);
            arr.push(data.first);
            arr.push(data.middle);
        } else {
            arr.push(data.first);
            arr.push(data.last);
        }

        name = arr.filter(item => !!item).join(' ').trim();

        if (name === '') {
            name = null;
        }

        return name;
    }

    protected focusOnInlineEdit() {
        const input = this.element.querySelector<HTMLInputElement>('input.form-control[type="text"]');

        if (!input) {
            return;
        }

        input.focus({preventScroll: true});
    }
}

export default PersonNameFieldView;
