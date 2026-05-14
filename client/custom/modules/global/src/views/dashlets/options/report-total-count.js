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
 * Options modal for the "Report Total + Count + Percentage" dashlet.
 *
 * Extends the Advanced Report options view to reuse: report picker, runtime
 * filters, report fetch on selection. Overrides column-handling to populate
 * three enum selectors (total / count / optional percentage) instead of a
 * single column enum.
 */
define('global:views/dashlets/options/report-total-count', [
    'advanced:views/dashlets/options/report',
], function (Dep) {

    const COLUMN_FIELDS = ['totalColumn', 'countColumn', 'percentageColumn'];

    // Field types accepted as the dashboard date-range anchor field.
    const DATE_FIELD_TYPES = ['date', 'datetime', 'datetimeOptional'];

    // Top-level aggregator prefixes recognised by `reportHelper.isColumnSummary`.
    const AGGREGATE_PREFIX_RE = /^(SUM|COUNT|AVG|MIN|MAX):/;

    return Dep.extend({

        // Reuse the parent's template (form + runtime filters panel).
        // template: 'advanced:dashlets/options/report' is inherited.

        setup: function () {
            Dep.prototype.setup.call(this);

            // ------------------------------------------------------------
            // Widen `isColumnNumeric` so complex aggregate expressions are
            // exposed in the column dropdowns.
            //
            // The default helper does:
            //   getGroupFieldData(col).function in ['COUNT','SUM','AVG'] ||
            //   getGroupFieldData(col).fieldType in [...numeric types...]
            // and `getGroupFieldData` early-returns `undefined` for any
            // expression containing `:(` — so `SUM:IF:(...)`, `SUM:ROUND:(...)`
            // and similar are silently dropped from the dropdown unless the
            // user manually flips Type → "Summary" in the column editor.
            //
            // Patch: treat any column whose top-level function is an
            // aggregator (SUM/COUNT/AVG/MIN/MAX) as numeric. This mirrors
            // `isColumnSummary` and matches the backend's view of the column.
            //
            // The override is instance-local; it does not leak to other
            // dashlets/views that may construct their own ReportHelper.
            const originalIsColumnNumeric =
                this.reportHelper.isColumnNumeric.bind(this.reportHelper);

            this.reportHelper.isColumnNumeric = function (column, ctx) {
                if (originalIsColumnNumeric(column, ctx)) {
                    return true;
                }

                return AGGREGATE_PREFIX_RE.test(column || '');
            };

            // ------------------------------------------------------------
            // Remove the parent's single-column field — we use our own trio.
            if (this.optionsFields && this.optionsFields.column) {
                delete this.optionsFields.column;
            }

            // When the report changes, the parent setup clears column-related
            // model attrs. Mirror that for our three column fields so we never
            // persist stale column references.
            this.listenTo(this.model, 'change:reportId', () => {
                this.model.set('totalColumn', null);
                this.model.set('countColumn', null);
                this.model.set('percentageColumn', null);
                this.model.set('dashboardDateRangeField', null);
            });

            // Show / hide the date-range field selector based on the binding
            // checkbox. The initial state is applied in `afterRender` so that
            // the recordView is already constructed.
            this.listenTo(this.model, 'change:bindDashboardDateRange', () => {
                this.controlDashboardDateRangeFieldVisibility();
                this.controlShowFilterInfoVisibility();
            });
        },

        afterRender: function () {
            if (Dep.prototype.afterRender) {
                Dep.prototype.afterRender.call(this);
            }

            this.controlDashboardDateRangeFieldVisibility();
            this.controlShowFilterInfoVisibility();
        },

        /**
         * The `showFilterInfo` toggle is purely cosmetic — it controls the
         * right-side filter description chip on the dashlet card. When the
         * dashlet has nothing to display there (no per-dashlet runtime
         * filters AND no dashboard date-range binding), the toggle is
         * irrelevant and we hide it to keep the modal clean.
         */
        controlShowFilterInfoVisibility: function () {
            const recordView = this.getView('record');

            if (!recordView) {
                return;
            }

            const hasRuntimeFilters = typeof this.hasRuntimeFilters === 'function'
                ? this.hasRuntimeFilters()
                : false;

            const dashboardBinding = !!this.model.get('bindDashboardDateRange');

            const visible = hasRuntimeFilters || dashboardBinding;

            if (visible) {
                this.$el.find('.cell-showFilterInfo').removeClass('hidden');

                if ('showField' in recordView) {
                    recordView.showField('showFilterInfo');
                }
            } else {
                this.$el.find('.cell-showFilterInfo').addClass('hidden');

                if ('hideField' in recordView) {
                    recordView.hideField('showFilterInfo');
                }
            }
        },

        /**
         * Show `dashboardDateRangeField` only when the binding checkbox is on
         * and an entity is known (so the dropdown is populated).
         */
        controlDashboardDateRangeFieldVisibility: function () {
            const recordView = this.getView('record');

            if (!recordView) {
                return;
            }

            const enabled = !!this.model.get('bindDashboardDateRange');
            const entityType = this.reportData ? this.reportData.entityType : null;

            if (enabled && entityType) {
                this.populateDashboardDateRangeFieldOptions(entityType);
            }

            if (enabled) {
                this.$el.find('.cell-dashboardDateRangeField').removeClass('hidden');

                if ('showField' in recordView) {
                    recordView.showField('dashboardDateRangeField');
                }
            } else {
                this.$el.find('.cell-dashboardDateRangeField').addClass('hidden');

                if ('hideField' in recordView) {
                    recordView.hideField('dashboardDateRangeField');
                }
            }
        },

        /**
         * Populate the `dashboardDateRangeField` enum with the date/datetime
         * fields of the report's primary entity. Translations come from the
         * standard entity field labels.
         */
        populateDashboardDateRangeFieldOptions: function (entityType) {
            const recordView = this.getView('record');

            if (!recordView) {
                return;
            }

            const fieldView = recordView.getFieldView('dashboardDateRangeField');

            if (!fieldView) {
                return;
            }

            const fields = this.getMetadata().get(['entityDefs', entityType, 'fields']) || {};

            const options = [''];
            const translated = {'': '— ' + this.translate('None') + ' —'};

            Object.keys(fields).sort().forEach(name => {
                const type = (fields[name] || {}).type;

                if (!type || DATE_FIELD_TYPES.indexOf(type) === -1) {
                    return;
                }

                if (fields[name].disabled || fields[name].utility) {
                    return;
                }

                options.push(name);
                translated[name] = this.translate(name, 'fields', entityType);
            });

            fieldView.params.options = options;
            fieldView.translatedOptions = translated;

            const current = this.model.get('dashboardDateRangeField');

            if (current && options.indexOf(current) === -1) {
                this.model.set('dashboardDateRangeField', null);
            }

            fieldView.reRender();
        },

        /**
         * Replaces the parent implementation.
         * The parent populates a single `column` enum field; we populate three.
         */
        handleColumnField: function () {
            const recordView = this.getView('record');

            if (!recordView) {
                return;
            }

            const type = this.reportData ? this.reportData.type : null;

            // Only Grid/JointGrid reports expose aggregated numeric columns.
            const supported = type === 'Grid' || type === 'JointGrid';

            if (!supported) {
                this.hideColumnsField();

                return;
            }

            const columns = Espo.Utils.clone(this.reportData.columns || []);
            const columnsData = this.reportData.columnsData || {};

            const translatedOptions = {};

            columns.forEach(col => {
                const label = (columnsData[col] || {}).label;

                if (label) {
                    translatedOptions[col] = label;
                }
            });

            COLUMN_FIELDS.forEach(fieldName => {
                const fieldView = recordView.getFieldView(fieldName);

                if (!fieldView) {
                    return;
                }

                const options = Espo.Utils.clone(columns);
                const tOptions = Espo.Utils.clone(translatedOptions);

                // The percentage column is optional — prepend an empty option.
                if (fieldName === 'percentageColumn') {
                    options.unshift('');
                    tOptions[''] = '— ' + this.translate('None') + ' —';
                }

                fieldView.params.options = options;
                fieldView.translatedOptions = tOptions;

                // Provide a sensible default so the field is never silently
                // invalid after first picking a report.
                if (fieldName !== 'percentageColumn') {
                    const current = this.model.get(fieldName);

                    if (!current || columns.indexOf(current) === -1) {
                        this.model.set(fieldName, columns[0] || null);
                    }
                }

                fieldView.reRender();

                this.$el.find('.cell-' + fieldName).removeClass('hidden');

                if ('showField' in recordView) {
                    recordView.showField(fieldName);
                }
            });

            // Now that we know the entity, re-evaluate the date-range field.
            this.controlDashboardDateRangeFieldVisibility();
            this.controlShowFilterInfoVisibility();
        },

        /**
         * Replaces the parent implementation.
         */
        hideColumnsField: function () {
            const recordView = this.getView('record');

            COLUMN_FIELDS.forEach(fieldName => {
                this.$el.find('.cell-' + fieldName).addClass('hidden');

                if (recordView && 'hideField' in recordView) {
                    recordView.hideField(fieldName);
                }
            });
        },

        /**
         * Parent shows `useSiMultiplier` only when displayTotal/displayOnlyCount
         * are set. This dashlet always shows totals, so the field is always
         * meaningful.
         */
        controlUseSiMultiplierField: function () {
            this.showField('useSiMultiplier');
        },

        /**
         * Replaces the parent implementation.
         *
         * The bundled parent only validates the `report` field and then does
         * `t.prototype.fetchAttributes.call(this) || {}`. When base validation
         * fails on any other required field (e.g. totalColumn / countColumn),
         * the `|| {}` swallows the `null` and the modal still saves a partial
         * `{entityType, runtimeFilters, type, columns, depth, columnsData}`
         * payload — corrupting the dashlet config. We re-implement it so a
         * validation failure blocks save and keeps the modal open.
         */
        fetchAttributes: function () {
            const recordView = this.getRecordView();

            // Pull all field values first so validate() sees the latest model.
            const attributes = recordView.fetch();

            if (recordView.validate()) {
                return null;
            }

            if (this.hasRuntimeFilters()) {
                const runtimeFiltersView = this.getView('runtimeFilters');

                if (runtimeFiltersView) {
                    attributes.filtersData = runtimeFiltersView.fetchRaw();
                }
            }

            const reportData = this.reportData || {};

            attributes.entityType = reportData.entityType;
            attributes.runtimeFilters = reportData.runtimeFilters;
            attributes.type = reportData.type;
            attributes.columns = reportData.columns;
            attributes.depth = reportData.depth;
            attributes.columnsData = reportData.columnsData;

            return attributes;
        },
    });
});
