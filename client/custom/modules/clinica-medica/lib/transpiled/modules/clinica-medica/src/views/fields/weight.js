define("modules/clinica-medica/views/fields/weight", ["exports", "views/fields/float", "ui/select"], function (_exports, _float, _select) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.default = void 0;
  _float = _interopRequireDefault(_float);
  _select = _interopRequireDefault(_select);
  function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }
  /************************************************************************
   * This file is part of EspoCRM.
   *
   * EspoCRM – Open Source CRM application.
   * Copyright (C) 2014-2025 EspoCRM, Inc.
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

  /** @module clinica-medica:views/fields/weight */

  /**
   * A weight field.
   *
   * @extends FloatFieldView<module:clinica-medica:views/fields/weight~params>
   */
  class WeightFieldView extends _float.default {
    /**
     * @typedef {Object} module:clinica-medica:views/fields/weight~options
     * @property {
     *     module:clinica-medica:views/fields/weight~params &
     *     module:views/fields/base~params &
     *     Record
     * } [params] Parameters.
     */

    /**
     * @typedef {Object} module:clinica-medica:views/fields/weight~params
     * @property {number} [min] A min value.
     * @property {number} [max] A max value.
     * @property {boolean} [required] Required.
     * @property {boolean} [disableFormatting] Disable formatting.
     * @property {number|null} [decimalPlaces] A number of decimal places.
     * @property {boolean} [onlyDefaultUnit] Only the default unit.
     * @property {boolean} [decimal] Stored as decimal.
     * @property {number} [scale] Scale (for decimal).
     */

    /**
     * @param {
     *     module:clinica-medica:views/fields/weight~options &
     *     module:views/fields/base~options
     * } options Options.
     */
    constructor(options) {
      super(options);
    }
    type = 'weight';
    editTemplate = 'clinica-medica:fields/weight/edit';
    detailTemplate = 'clinica-medica:fields/weight/detail';
    listTemplate = 'clinica-medica:fields/weight/list';
    maxDecimalPlaces = 4;

    /**
     * @inheritDoc
     * @type {Array<(function (): boolean)|string>}
     */
    validations = ['required', 'number', 'range'];

    /** @inheritDoc */
    data() {
      const unitValue = this.model.get(this.unitFieldName) || this.getConfig().get('defaultWeightUnit') || this.defaultUnit;
      const multipleUnits = !this.isSingleUnit || unitValue !== this.defaultUnit;
      return {
        ...super.data(),
        unitFieldName: this.unitFieldName,
        unitValue: unitValue,
        unitList: this.unitList,
        unitSymbol: this.getMetadata().get(['app', 'weight', 'symbolMap', unitValue]) || '',
        multipleUnits: multipleUnits,
        defaultUnit: this.defaultUnit
      };
    }

    /** @inheritDoc */
    setup() {
      super.setup();
      this.unitFieldName = this.name + 'Unit';
      this.defaultUnit = this.getConfig().get('defaultWeightUnit') || 'kg';
      this.unitList = this.getMetadata().get(['app', 'weight', 'unitList']) || ['kg', 'g', 'mg', 'lb', 'oz', 't'];
      this.decimalPlaces = this.params.decimalPlaces !== undefined ? this.params.decimalPlaces : 2;
      if (this.params.onlyDefaultUnit) {
        this.unitList = [this.defaultUnit];
      }
      this.isSingleUnit = this.unitList.length <= 1;
      const unitValue = this.unitValue = this.model.get(this.unitFieldName) || this.defaultUnit;
      if (!this.unitList.includes(unitValue)) {
        this.unitList = Espo.Utils.clone(this.unitList);
        this.unitList.push(unitValue);
      }
    }

    /** @inheritDoc */
    setupAutoNumericOptions() {
      this.autoNumericOptions = {
        digitGroupSeparator: this.thousandSeparator || '',
        decimalCharacter: this.decimalMark,
        modifyValueOnWheel: false,
        selectOnFocus: false,
        decimalPlaces: this.decimalPlaces,
        allowDecimalPadding: true,
        showWarnings: false,
        formulaMode: true
      };
      if (this.decimalPlaces === null) {
        this.autoNumericOptions.decimalPlaces = this.decimalPlacesRawValue;
        this.autoNumericOptions.decimalPlacesRawValue = this.decimalPlacesRawValue;
        this.autoNumericOptions.allowDecimalPadding = false;
      }
    }
    formatNumber(value) {
      return this.formatNumberDetail(value);
    }
    formatNumberDetail(value) {
      if (value !== null) {
        const weightDecimalPlaces = this.decimalPlaces;
        if (weightDecimalPlaces === 0) {
          value = Math.round(value);
        } else if (weightDecimalPlaces) {
          value = Math.round(value * Math.pow(10, weightDecimalPlaces)) / Math.pow(10, weightDecimalPlaces);
        } else {
          value = Math.round(value * Math.pow(10, this.maxDecimalPlaces)) / Math.pow(10, this.maxDecimalPlaces);
        }
        const parts = value.toString().split(".");
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, this.thousandSeparator);
        if (weightDecimalPlaces === 0) {
          return parts[0];
        } else if (weightDecimalPlaces) {
          let decimalPartLength = 0;
          if (parts.length > 1) {
            decimalPartLength = parts[1].length;
          } else {
            parts[1] = '';
          }
          if (weightDecimalPlaces && decimalPartLength < weightDecimalPlaces) {
            const limit = weightDecimalPlaces - decimalPartLength;
            for (let i = 0; i < limit; i++) {
              parts[1] += '0';
            }
          }
        }
        return parts.join(this.decimalMark);
      }
      return '';
    }
    parse(value) {
      value = value !== '' ? value : null;
      if (value === null) {
        return null;
      }
      value = value.split(this.thousandSeparator).join('');
      value = value.split(this.decimalMark).join('.');
      if (this.params.decimal) {
        const scale = this.params.scale || 4;
        const parts = value.split('.');
        const decimalPart = parts[1] || '';
        if (decimalPart.length < scale) {
          value = parts[0] + '.' + decimalPart.padEnd(scale, '0');
        }
      }
      if (!this.params.decimal) {
        value = parseFloat(value);
      }
      return value;
    }
    afterRender() {
      super.afterRender();
      if (this.mode === this.MODE_EDIT) {
        this.$unit = this.$el.find(`[data-name="${this.unitFieldName}"]`);
        if (this.$unit.length) {
          this.$unit.on('change', () => {
            this.model.set(this.unitFieldName, this.$unit.val(), {
              ui: true
            });
          });
          _select.default.init(this.$unit);
        }
      }
    }
    validateNumber() {
      if (!this.params.decimal) {
        return this.validateFloat();
      }
      const value = this.model.get(this.name);
      if (Number.isNaN(Number(value))) {
        const msg = this.translate('fieldShouldBeNumber', 'messages').replace('{field}', this.getLabelText());
        this.showValidationMessage(msg);
        return true;
      }
    }
    fetch() {
      let value = this.$element.val().trim();
      value = this.parse(value);
      const data = {};
      let unitValue = this.$unit.length ? this.$unit.val() : this.defaultUnit;
      if (value === null) {
        unitValue = null;
      }
      data[this.name] = value;
      data[this.unitFieldName] = unitValue;
      return data;
    }
  }
  var _default = _exports.default = WeightFieldView;
});
//# sourceMappingURL=weight.js.map ;