/**
 * Adds a "Field Type" dropdown to the report column editor (gear icon next to
 * each column in a Grid report). The value is stored under
 * `columnsData[<column>].fieldType` and the backend honors it as an override
 * for the auto-detected column type (see
 * `custom/Espo/Modules/Advanced/Tools/Report/GridType/Data.php::getColumnFieldType`
 * and `ResultHelper.php::populateColumnInfo`).
 *
 * Use case: mark a complex-expression column (e.g. `SUM:IF:(...)`) as
 * `currencyConverted` so dashlets / result views / PDF / XLSX format it with
 * the system default currency symbol — no `CONCAT('R$ ', ...)` needed.
 *
 * Monkey patch (see https://docs.espocrm.com/development/frontend/monkey-patching/).
 * Loaded via `custom/Espo/Custom/Resources/metadata/app/client.json`.
 */
require([
    'advanced:views/report/fields/columns',
    'advanced:views/report/modals/edit-columns',
    'advanced:views/report/fields/columns/item',
    'model',
], (ColumnsField, EditColumnsModal, ColumnItem, Model) => {    const FIELD_TYPE_OPTIONS = [
        '',
        'currencyConverted',
        'int',
        'float',
        'duration',
    ];

    // ---------- 1. Item view (one row per column) ----------

    // Inject a 4th cell into the second row of the existing template, holding
    // the fieldType enum. Resize neighbours to col-md-3 so the row still fits.
    ColumnItem.prototype.templateContent = `
            <div class="row">
                <div class="cell form-group col-md-10">
                    <label class="control-label">#{{number}}</label>
                    <div class="field" data-name="expression">{{{expression}}}</div>
                </div>
            </div>
            <div class="row">
                <div class="cell form-group col-md-3">
                    <label class="control-label">{{translate 'Label' scope='Report'}}</label>
                    <div class="field" data-name="label">{{{label}}}</div>
                </div>
                <div class="cell form-group col-md-3">
                    <label class="control-label">{{translate 'Type' scope='Report'}}</label>
                    <div class="field" data-name="type">{{{type}}}</div>
                </div>
                <div class="cell form-group col-md-3">
                    <label class="control-label">{{translate 'Decimal Places' scope='Report'}}</label>
                    <div class="field" data-name="decimalPlaces">{{{decimalPlaces}}}</div>
                </div>
                <div class="cell form-group col-md-3">
                    <label class="control-label">{{translate 'Field Type' scope='Report'}}</label>
                    <div class="field" data-name="fieldType">{{{fieldType}}}</div>
                </div>
            </div>
        `;

    ColumnItem.prototype.setup = function () {
        const entityType = this.options.entityType;
        const onChange = this.options.onChange;

        const model = new Model();

        model.set({
            expression: this.options.expression || null,
            label: this.options.label || null,
            type: this.options.type || null,
            decimalPlaces: this.options.decimalPlaces,
            fieldType: this.options.fieldType || null,
        });

        this.listenTo(model, 'change', () => {
            let expr = model.attributes.expression;

            if (expr !== null) {
                expr = expr.trim();
            }

            onChange(
                expr,
                model.attributes.label,
                model.attributes.type,
                model.attributes.decimalPlaces,
                model.attributes.fieldType
            );
        });

        this.createView('expression', 'views/fields/complex-expression', {
            model: model,
            name: 'expression',
            selector: ' [data-name="expression"]',
            mode: 'edit',
            height: 50,
            targetEntityType: entityType,
            smallFont: true,
        });

        this.createView('label', 'views/fields/varchar', {
            model: model,
            name: 'label',
            selector: ' [data-name="label"]',
            mode: 'edit',
            maxLength: 64,
        });

        this.createView('type', 'views/fields/enum', {
            model: model,
            name: 'type',
            selector: ' [data-name="type"]',
            mode: 'edit',
            params: {
                options: ['', 'Summary'],
                translation: 'Report.options.columnType',
            },
        });

        this.createView('decimalPlaces', 'views/fields/int', {
            model: model,
            name: 'decimalPlaces',
            selector: ' [data-name="decimalPlaces"]',
            mode: 'edit',
            params: {min: 0, max: 8},
            labelText: this.translate('Decimal Places', 'labels', 'Report'),
        });

        this.createView('fieldType', 'views/fields/enum', {
            model: model,
            name: 'fieldType',
            selector: ' [data-name="fieldType"]',
            mode: 'edit',
            params: {
                options: FIELD_TYPE_OPTIONS,
                translation: 'Report.options.columnFieldType',
            },
        });
    };

    // ---------- 2. Edit-columns modal ----------

    const originalModalSetup = EditColumnsModal.prototype.setup;

    EditColumnsModal.prototype.setup = function () {
        this.fieldTypes = Espo.Utils.clone(this.options.fieldTypes || []);

        originalModalSetup.call(this);
    };

    EditColumnsModal.prototype.getItemList = function () {
        return this.expressions.map((expression, i) => ({
            key: i.toString(),
            number: i + 1,
            expression: expression,
            label: this.labels[i] || null,
            type: this.types[i] || null,
            decimalPlaces: this.decimals[i],
            fieldType: this.fieldTypes[i] || null,
        }));
    };

    EditColumnsModal.prototype.createItemView = function (i) {
        const item = this.getItemList()[i];

        if (!item) {
            throw new Error(`No item ${i}.`);
        }

        return this.createView(
            item.key,
            'advanced:views/report/fields/columns/item',
            {
                selector: `[data-key="${item.key}"]`,
                expression: item.expression,
                label: item.label,
                type: item.type,
                decimalPlaces: item.decimalPlaces,
                fieldType: item.fieldType,
                entityType: this.entityType,
                number: item.number,
                onChange: (expr, label, type, decimalPlaces, fieldType) => {
                    this.expressions[i] = expr;
                    this.labels[i] = label;
                    this.types[i] = type;
                    this.decimals[i] = decimalPlaces;
                    this.fieldTypes[i] = fieldType;
                },
            }
        );
    };

    EditColumnsModal.prototype.actionApply = function () {
        let valid = true;

        this.getItemList().forEach(item => {
            if (this.getView(item.key).validate()) {
                valid = false;
            }
        });

        if (!valid) {
            return;
        }

        const expressions = [];
        const labels = [];
        const types = [];
        const decimals = [];
        const fieldTypes = [];

        this.expressions.forEach((expr, i) => {
            if (expr === null) return;

            expr = expr.replace(/(?:\r\n|\r|\n)/g, '\t');

            if (expr === '') return;

            expressions.push(expr);
            labels.push(this.labels[i] || null);
            types.push(this.types[i] || null);
            decimals.push(this.decimals[i]);
            fieldTypes.push(this.fieldTypes[i] || null);
        });

        this.trigger('apply', expressions, labels, types, decimals, fieldTypes);
        this.remove();
    };

    EditColumnsModal.prototype.addItem = function () {
        this.expressions.push(null);
        this.labels.push(null);
        this.types.push(null);
        this.decimals.push(null);
        this.fieldTypes.push(null);

        this.createItemView(this.expressions.length - 1).then(() => this.reRender());
    };

    // ---------- 3. Columns field view (entry point) ----------

    ColumnsField.prototype.actionEditColumns = function () {
        const expressions = this.model.get(this.name) || [];
        const columnsData = this.model.get('columnsData') || {};

        const labels = expressions.map(e => (columnsData[e] || {}).label || null);
        const types = expressions.map(e => (columnsData[e] || {}).type || null);
        const decimals = expressions.map(e => {
            const v = (columnsData[e] || {}).decimalPlaces;
            return v === undefined ? null : v;
        });
        const fieldTypes = expressions.map(e => (columnsData[e] || {}).fieldType || null);

        this.createView('dialog', 'advanced:views/report/modals/edit-columns', {
            expressions: expressions,
            labels: labels,
            types: types,
            decimals: decimals,
            fieldTypes: fieldTypes,
            entityType: this.model.get('entityType'),
        }, view => {
            view.render();

            this.listenToOnce(view, 'apply', (exprs, lbls, tps, dpl, fts) => {
                const newColumnsData = exprs.reduce((acc, expr, i) => {
                    const entry = {
                        label: lbls[i] || null,
                        type: tps[i] || null,
                        decimalPlaces: dpl[i],
                    };

                    if (fts[i]) {
                        entry.fieldType = fts[i];
                    }

                    acc[expr] = entry;

                    return acc;
                }, {});

                this.model.set(
                    {
                        [this.name]: exprs,
                        columnsData: newColumnsData,
                    },
                    {ui: true}
                );

                this.clearView('dialog');
                this.reRender();
            });
        });
    };
});

// ---------- 4. Result rendering: honor columnTypeMap currencyConverted ----------
//
// The Advanced Pack's `tables/grid2` view (also used by `tables/grid1` via
// delegation) decides whether a cell should be rendered as currency based on
// `getGroupFieldData(expr).fieldType`. That helper looks up the field's native
// fieldType from entity metadata, but it is explicitly skipped for any
// expression containing `:(` (e.g. `SUM:IF:(...)`, `ROUND:(...)`, etc.).
// As a result, complex-expression columns never render with a currency symbol
// even when the backend exports `columnTypeMap[col] === 'currencyConverted'`.
//
// This patch makes `formatCellValue` fall back to `result.columnTypeMap[col]`
// so that the per-column `fieldType` override (see Data.php::getColumnFieldType
// and ResultHelper.php::populateColumnInfo) actually produces R$/$/€ output
// in the table-style Grid result view.
require(['advanced:views/report/reports/tables/grid2'], (Grid2View) => {
    const originalFormatCellValue = Grid2View.prototype.formatCellValue;

    Grid2View.prototype.formatCellValue = function (value, expr, hasValue) {
        if (!this.options.reportHelper.isColumnNumeric(expr, this.result)) {
            if (this.result.cellValueMaps && this.result.cellValueMaps[expr]) {
                value = this.result.cellValueMaps[expr][value] || value || '';
            }

            return Array.isArray(value) ? value.join(', ') : value;
        }

        value = value || 0;

        let isCurrency = false;
        let parts = expr.split(':');

        if (parts.length === 1) {
            parts = ['', expr];
        }

        if (parts.length > 1 && !expr.includes(':(')) {
            const data = this.reportHelper.getGroupFieldData(expr, this.result);

            if (data) {
                const {entityType, field, fieldType} = data;

                isCurrency = ['currency', 'currencyConverted'].includes(fieldType) ||
                    (entityType === 'Opportunity' && field === 'amountWeightedConverted');
            }
        }

        // Honor backend `columnsData[col].fieldType = 'currencyConverted'` override.
        if (
            !isCurrency &&
            this.result.columnTypeMap &&
            this.result.columnTypeMap[expr] === 'currencyConverted'
        ) {
            isCurrency = true;
        }

        if (!hasValue && value == 0) {
            return ~expr.indexOf('COUNT:')
                ? '<span class="text-muted">0</span>'
                : '<span class="text-muted">' + this.formatNumber(0) + '</span>';
        }

        if (~expr.indexOf('COUNT:')) {
            return this.formatNumber(value);
        }

        const decimals = (this.result.columnDecimalPlacesMap || {})[expr];

        return this.reportHelper.formatNumber(value, isCurrency, null, null, null, decimals);
    };

    // Mark the patch (handy when debugging from the browser console).
    Grid2View.prototype._formatCellValuePatched = true;
});

// ---------- 5. Report helper: honor columnTypeMap in dashlets/charts ----------
//
// `advanced:report-helper.formatCellValue` is used by:
//   - `advanced:views/dashlets/report`        (Total / OnlyCount layouts of the
//                                              built-in Report dashlet)
//   - `modules/advanced/views/report/reports/charts/base` (chart cell labels)
//   - `global:views/dashlets/report-total-count` (our custom dashlet)
//
// The original implementation derives "is currency" from
// `getGroupFieldData(expr).fieldType`, which returns no fieldType for a
// complex expression like `SUM:IF:(...)`. As a result, dashlets and charts
// render plain numbers (e.g. `440` instead of `R$ 440`) for any column that
// is not a direct aggregate over a currency field.
//
// This patch makes the helper consult `result.columnTypeMap[expr]` so the
// backend `columnsData[col].fieldType = 'currencyConverted'` override
// reaches the dashlet/chart rendering path too.
require(['advanced:report-helper'], (ReportHelper) => {
    const proto = ReportHelper.prototype;

    proto.formatCellValue = function (value, expr, result, useSi) {
        // Non-numeric columns (string, enum, link, etc.) — pass through.
        if (typeof this.isColumnNumeric === 'function' &&
            !this.isColumnNumeric(expr, result)
        ) {
            if (result && result.cellValueMaps && result.cellValueMaps[expr]) {
                value = result.cellValueMaps[expr][value] || value || '';
            }

            return Array.isArray(value) ? value.join(', ') : value;
        }

        let isCurrency = false;
        let parts = (expr || '').split(':');

        if (parts.length === 1) {
            parts = ['', expr];
        }

        if (parts.length > 1) {
            const data = this.getGroupFieldData(expr, result) || {};
            const {entityType, field, fieldType} = data;

            isCurrency = ['currency', 'currencyConverted'].includes(fieldType) ||
                (entityType === 'Opportunity' && field === 'amountWeightedConverted');
        }

        // Honor backend `columnsData[col].fieldType = 'currencyConverted'` override.
        if (
            !isCurrency &&
            result &&
            result.columnTypeMap &&
            result.columnTypeMap[expr] === 'currencyConverted'
        ) {
            isCurrency = true;
        }

        const decimals = ((result || {}).columnDecimalPlacesMap || {})[expr];

        return this.formatNumber(value, isCurrency, useSi, null, null, decimals);
    };

    proto._formatCellValuePatched = true;
});
