define("feature-journey:views/fields/entity-where", [
    "views/fields/base",
    "feature-journey:helpers/custom-fields",
], function (Dep, CustomFieldsHelper) {
    /**
     * Espo where-array builder with native attributes + CustomField bag leaves
     * (`customFields.<valueKey>`). Used by goalEntityFilter and reusable by
     * conditions-group entityFilter leaves.
     */
    return Dep.extend({
        type: "jsonObject",

        editTemplateContent:
            '<div class="journey-entity-where">' +
                '<div class="where-rows"></div>' +
                '<div class="margin-top-sm">' +
                    '<button type="button" class="btn btn-default btn-sm" data-action="addWhereRow">' +
                        '<span class="fas fa-plus"></span> {{translate "addFilterRow" category="labels" scope="JourneyTransition"}}' +
                    '</button>' +
                    '<button type="button" class="btn btn-link btn-sm" data-action="toggleWhereAdvanced" style="margin-left:8px">' +
                        '{{translate "advancedJson" category="labels" scope="JourneyStageAction"}}' +
                    '</button>' +
                '</div>' +
                '<div class="where-raw-wrap hidden margin-top-sm">' +
                    '<textarea class="form-control where-raw" rows="6"></textarea>' +
                '</div>' +
                '<p class="text-muted small" style="margin-top:8px">' +
                    '{{translate "entityFilterHint" category="messages" scope="JourneyTransition"}}' +
                '</p>' +
            '</div>',

        detailTemplateContent:
            '{{#if isNotEmpty}}' +
            '<div class="small">{{{summaryHtml}}}</div>' +
            '{{else}}' +
            '<span class="none-value">{{translate "None"}}</span>' +
            '{{/if}}',

        listTemplateContent:
            '{{#if isNotEmpty}}' +
            '<span class="text-muted">{{summary}}</span>' +
            '{{else}}' +
            '<span class="none-value">{{translate "None"}}</span>' +
            '{{/if}}',

        setup: function () {
            Dep.prototype.setup.call(this);
            this.cfHelper = new CustomFieldsHelper(this);
            this._rows = this.normalizeWhere(this.model.get(this.name));
            this._attrOptions = [];
            this._advancedOpen = false;
            this._rawDirty = false;
            this._context = { tenantId: null, entityType: null };
        },

        data: function () {
            const where = this.normalizeWhere(this.model.get(this.name));
            return {
                ...Dep.prototype.data.call(this),
                isNotEmpty: where.length > 0,
                summaryHtml: this.renderSummaryHtml(where),
                summary: where.length ? where.length + " filter(s)" : "",
            };
        },

        normalizeWhere: function (value) {
            if (!value) {
                return [];
            }

            let v = value;

            if (typeof v === "string") {
                try {
                    v = JSON.parse(v);
                } catch (e) {
                    return [];
                }
            }

            if (v && typeof v === "object" && !Array.isArray(v)) {
                if (v.type || v.attribute) {
                    v = [v];
                } else {
                    return [];
                }
            }

            if (!Array.isArray(v)) {
                return [];
            }

            return v.map((item) => {
                if (!item || typeof item !== "object") {
                    return { type: "equals", attribute: "", value: "" };
                }

                return Object.assign({}, item);
            });
        },

        afterRender: function () {
            if (!this.isEditMode()) {
                return;
            }

            this._rows = this.normalizeWhere(this.model.get(this.name));
            this.$rows = this.$el.find(".where-rows");
            this.$raw = this.$el.find(".where-raw");
            this.$rawWrap = this.$el.find(".where-raw-wrap");

            this.$el.find('[data-action="addWhereRow"]').on("click", () => {
                this._rows.push({ type: "equals", attribute: "", value: "" });
                this._rawDirty = false;
                this.renderRows();
                this.trigger("change");
            });

            this.$el.find('[data-action="toggleWhereAdvanced"]').on("click", () => {
                this._advancedOpen = !this._advancedOpen;
                this.$rawWrap.toggleClass("hidden", !this._advancedOpen);

                if (this._advancedOpen) {
                    this.$raw.val(JSON.stringify(this._rows, null, 2));
                }
            });

            this.$raw.on("change input", () => {
                this._rawDirty = true;

                try {
                    this._rows = this.normalizeWhere(
                        JSON.parse(String(this.$raw.val() || "[]"))
                    );
                    this._parseError = false;
                } catch (e) {
                    this._parseError = true;
                }

                this.trigger("change");
            });

            this.cfHelper.resolveContext().then((ctx) => {
                this._context = ctx;

                return this.cfHelper.loadAttributeOptions(ctx.entityType, ctx.tenantId);
            }).then((opts) => {
                this._attrOptions = opts || [];
                this.renderRows();
            });
        },

        renderRows: function () {
            if (!this.$rows) {
                return;
            }

            this.$rows.empty();

            if (!this._rows.length) {
                this.$rows.append(
                    $("<div>")
                        .addClass("text-muted")
                        .text(
                            this.translate(
                                "entityFilterEmpty",
                                "messages",
                                "JourneyTransition"
                            )
                        )
                );

                return;
            }

            this._rows.forEach((row, index) => {
                this.$rows.append(this.buildRow(row, index));
            });
        },

        buildRow: function (row, index) {
            const $row = $("<div>")
                .addClass("row")
                .css({ marginBottom: "6px" })
                .attr("data-index", index);

            const $colAttr = $("<div>").addClass("col-sm-5");
            const $colOp = $("<div>").addClass("col-sm-3");
            const $colVal = $("<div>").addClass("col-sm-3");
            const $colRm = $("<div>").addClass("col-sm-1");

            const $attr = $("<select>").addClass("form-control input-sm where-attr");
            $attr.append($("<option>").val("").text("—"));
            this._attrOptions.forEach((opt) => {
                const $o = $("<option>").val(opt.value).text(opt.label);

                if (opt.value === row.attribute) {
                    $o.prop("selected", true);
                }

                $attr.append($o);
            });

            if (row.attribute && !this._attrOptions.some((o) => o.value === row.attribute)) {
                $attr.append(
                    $("<option>")
                        .val(row.attribute)
                        .text(row.attribute + " *")
                        .prop("selected", true)
                );
            }

            const $op = $("<select>").addClass("form-control input-sm where-op");
            this.cfHelper.whereOperators().forEach((op) => {
                const $o = $("<option>").val(op).text(op);

                if (op === (row.type || "equals")) {
                    $o.prop("selected", true);
                }

                $op.append($o);
            });

            const needsValue = this.cfHelper.operatorNeedsValue(row.type || "equals");
            let displayVal = "";

            if (Array.isArray(row.value)) {
                displayVal = row.value.join(", ");
            } else if (row.value !== undefined && row.value !== null) {
                displayVal = String(row.value);
            }

            const $val = $("<input>")
                .attr("type", "text")
                .addClass("form-control input-sm where-val")
                .prop("disabled", !needsValue)
                .val(displayVal);

            const $rm = $("<button>")
                .attr("type", "button")
                .addClass("btn btn-default btn-sm")
                .html('<span class="fas fa-times"></span>');

            $colAttr.append($attr);
            $colOp.append($op);
            $colVal.append($val);
            $colRm.append($rm);
            $row.append($colAttr).append($colOp).append($colVal).append($colRm);

            const sync = () => {
                row.attribute = $attr.val() || "";
                row.type = $op.val() || "equals";

                if (!this.cfHelper.operatorNeedsValue(row.type)) {
                    delete row.value;
                    $val.prop("disabled", true).val("");
                } else {
                    $val.prop("disabled", false);
                    row.value = this.cfHelper.coerceValue($val.val(), row.type);
                }

                this.onChanged();
            };

            $attr.on("change", sync);
            $op.on("change", sync);
            $val.on("change input", sync);
            $rm.on("click", () => {
                this._rows.splice(index, 1);
                this.renderRows();
                this.onChanged();
            });

            return $row;
        },

        onChanged: function () {
            this._rawDirty = false;

            if (this._advancedOpen && this.$raw) {
                this.$raw.val(JSON.stringify(this._rows, null, 2));
            }

            this.trigger("change");
        },

        renderSummaryHtml: function (where) {
            if (!where.length) {
                return "";
            }

            let html = "<ul style=\"margin:0 0 0 16px;padding:0\">";
            where.forEach((item) => {
                const line =
                    (item.attribute || "?") +
                    " " +
                    (item.type || "equals") +
                    (item.value !== undefined
                        ? " " +
                          this.getHelper().escapeString(
                              Array.isArray(item.value)
                                  ? item.value.join(",")
                                  : String(item.value)
                          )
                        : "");
                html +=
                    "<li>" + this.getHelper().escapeString(line) + "</li>";
            });
            html += "</ul>";

            return html;
        },

        fetch: function () {
            const data = {};

            if (this._rawDirty && this.$raw) {
                if (this._parseError) {
                    data[this.name] = this.model.get(this.name);

                    return data;
                }
            }

            const rows = (this._rows || []).filter((r) => r && r.attribute);
            data[this.name] = rows.length ? rows : null;

            return data;
        },

        validate: function () {
            if (this._rawDirty && this._parseError) {
                this.showValidationMessage(
                    this.translate("jsonParseError", "messages", "JourneyStageAction")
                );

                return true;
            }

            return false;
        },

        /**
         * Public: render where-row UI into an external $container (conditions-group).
         */
        mountInline: function ($container, where, onChange) {
            this._rows = this.normalizeWhere(where);
            this._inlineOnChange = onChange;
            this.$el = $container;
            this.mode = "edit";

            $container.html(
                '<div class="where-rows"></div>' +
                    '<div class="margin-top-sm">' +
                    '<button type="button" class="btn btn-default btn-xs" data-action="addWhereRow">' +
                    '<span class="fas fa-plus"></span></button></div>'
            );
            this.$rows = $container.find(".where-rows");

            $container.find('[data-action="addWhereRow"]').on("click", () => {
                this._rows.push({ type: "equals", attribute: "", value: "" });
                this.renderRows();
                this.emitInline();
            });

            // override onChanged for inline
            this.onChanged = () => {
                this.emitInline();
            };

            return this.cfHelper.resolveContext().then((ctx) => {
                this._context = ctx;

                return this.cfHelper.loadAttributeOptions(ctx.entityType, ctx.tenantId);
            }).then((opts) => {
                this._attrOptions = opts || [];
                this.renderRows();

                return this;
            });
        },

        emitInline: function () {
            if (typeof this._inlineOnChange === "function") {
                this._inlineOnChange(
                    (this._rows || []).filter((r) => r && r.attribute)
                );
            }
        },

        getWhere: function () {
            return (this._rows || []).filter((r) => r && r.attribute);
        },
    });
});
