define("feature-journey:views/fields/entity-where", [
    "views/fields/base",
    "feature-journey:helpers/custom-fields",
    "feature-journey:helpers/expression-input",
], function (Dep, CustomFieldsHelper, ExpressionInput) {
    const MAX_DEPTH = 5;

    return Dep.extend({
        type: "jsonObject",

        editTemplateContent:
            '<div class="journey-entity-where">' +
                '<div class="alert alert-warning formula-override-warning hidden"></div>' +
                '<div class="where-tree"></div>' +
                '<div class="margin-top-sm">' +
                    '<button type="button" class="btn btn-link btn-sm" data-action="toggleWhereAdvanced">' +
                        '{{translate "advancedJson" category="labels" scope="JourneyStageAction"}}' +
                    '</button>' +
                '</div>' +
                '<div class="where-raw-wrap hidden margin-top-sm">' +
                    '<textarea class="form-control where-raw" rows="8"></textarea>' +
                '</div>' +
                '<p class="text-muted small" style="margin-top:8px">' +
                    '{{translate "conditionsHint" category="messages" scope="JourneyStageAction"}}' +
                '</p>' +
                '<p class="text-muted small condition-value-formula-hint hidden">' +
                    '{{translate "conditionValueFormulaHint" category="messages" scope="JourneyStageAction"}}' +
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
            this._tree = this.normalizeTree(this.model.get(this.name));
            this._attrOptions = [];
            this._advancedOpen = false;
            this._rawDirty = false;
            this._parseError = false;
            this._loadGeneration = 0;
            this._valueInputs = [];
            this._removed = false;

            this.listenTo(
                this.model,
                "change:stageId change:journeyId change:targetEntityType change:tenantId",
                () => this.loadOptions()
            );
            this.listenTo(this.model, "change:conditionFormula", () => {
                this.renderFormulaOverrideWarning();
            });
        },

        data: function () {
            const tree = this.cleanTree(this.normalizeTree(this.model.get(this.name)));
            const count = this.countLeaves(tree);

            return {
                ...Dep.prototype.data.call(this),
                isNotEmpty: count > 0,
                summaryHtml: this.renderSummaryHtml(tree),
                summary: count ? count + " condition(s)" : "",
            };
        },

        afterRender: function () {
            if (!this.isEditMode()) {
                return;
            }

            this._tree = this.normalizeTree(this.model.get(this.name));
            this.$tree = this.$el.find(".where-tree");
            this.$raw = this.$el.find(".where-raw");
            this.$rawWrap = this.$el.find(".where-raw-wrap");

            this.$el.find('[data-action="toggleWhereAdvanced"]').on("click", () => {
                this._advancedOpen = !this._advancedOpen;
                this.$rawWrap.toggleClass("hidden", !this._advancedOpen);

                if (this._advancedOpen) {
                    this.$raw.val(JSON.stringify(this.cleanTree(this._tree), null, 2));
                }
            });

            this.$raw.on("change input", () => {
                this._rawDirty = true;

                try {
                    const parsed = JSON.parse(String(this.$raw.val() || "null"));
                    if (!this.isEditableTree(parsed)) {
                        throw new Error("Invalid condition tree");
                    }

                    this._tree = this.normalizeTree(parsed);
                    this._parseError = false;
                    this.renderTree();
                } catch (e) {
                    this._parseError = true;
                }

                this.trigger("change");
            });

            this.renderTree();
            this.renderFormulaOverrideWarning();
            this.$el.find(".condition-value-formula-hint")
                .toggleClass("hidden", !this.allowsValueFormula());
            this.loadOptions();
        },

        normalizeTree: function (value) {
            let current = value;

            if (typeof current === "string") {
                try {
                    current = JSON.parse(current);
                } catch (e) {
                    current = null;
                }
            }

            if (!current) {
                return {type: "and", value: []};
            }

            current = this.cloneValue(current);

            if (Array.isArray(current)) {
                return {type: "and", value: current};
            }

            if (current && typeof current === "object") {
                if (current.type === "and" || current.type === "or") {
                    current.value = Array.isArray(current.value) ? current.value : [];

                    return current;
                }

                if (current.type || current.attribute) {
                    return {type: "and", value: [current]};
                }
            }

            return {type: "and", value: []};
        },

        cloneValue: function (value) {
            return JSON.parse(JSON.stringify(value));
        },

        isEditableTree: function (value) {
            if (value === null || (Array.isArray(value) && !value.length)) {
                return true;
            }

            if (Array.isArray(value)) {
                return value.every((node) => this.isEditableNode(node, 1));
            }

            return this.isEditableNode(value, 1);
        },

        isEditableNode: function (node, depth) {
            if (!node || typeof node !== "object" || Array.isArray(node) || depth > MAX_DEPTH) {
                return false;
            }

            if (node.type === "and" || node.type === "or") {
                return Array.isArray(node.value) &&
                    node.value.every((child) => this.isEditableNode(child, depth + 1));
            }

            return typeof node.attribute === "string";
        },

        loadOptions: function () {
            if (!this.isEditMode() || !this.cfHelper) {
                return Promise.resolve([]);
            }

            const generation = ++this._loadGeneration;

            return this.cfHelper.resolveContext()
                .then((context) => {
                    return this.cfHelper.loadAttributeOptions(
                        context.entityType,
                        context.tenantId
                    );
                })
                .then((options) => {
                    if (this._removed || generation !== this._loadGeneration) {
                        return options || [];
                    }

                    this._attrOptions = options || [];
                    this.renderTree();

                    return this._attrOptions;
                });
        },

        renderTree: function () {
            if (!this.$tree) {
                return;
            }

            this.destroyValueInputs();
            this.$tree.empty().append(this.buildGroup(this._tree, null, 1));
        },

        buildGroup: function (group, parent, depth) {
            const $group = $("<div>")
                .addClass("panel panel-default")
                .css({marginBottom: "8px"});
            const $heading = $("<div>")
                .addClass("panel-heading")
                .css({display: "flex", gap: "6px", alignItems: "center", flexWrap: "wrap"});
            const $mode = $("<select>").addClass("form-control input-sm").css({width: "auto"});

            $mode.append(
                $("<option>")
                    .val("and")
                    .text(this.translate("matchAll", "labels", "JourneyStageAction"))
            );
            $mode.append(
                $("<option>")
                    .val("or")
                    .text(this.translate("matchAny", "labels", "JourneyStageAction"))
            );
            $mode.val(group.type === "or" ? "or" : "and");

            const $addCondition = $("<button>")
                .attr("type", "button")
                .addClass("btn btn-default btn-sm")
                .prop("disabled", depth >= MAX_DEPTH || this.countLeaves(this._tree) >= 50)
                .html('<span class="fas fa-plus"></span> ' +
                    this.translate("addCondition", "labels", "JourneyStageAction"));
            const $addGroup = $("<button>")
                .attr("type", "button")
                .addClass("btn btn-default btn-sm")
                .prop("disabled", depth >= MAX_DEPTH - 1)
                .html('<span class="fas fa-layer-group"></span> ' +
                    this.translate("addConditionGroup", "labels", "JourneyStageAction"));

            $heading.append($mode).append($addCondition).append($addGroup);

            if (parent) {
                const $remove = $("<button>")
                    .attr("type", "button")
                    .addClass("btn btn-link btn-sm text-danger")
                    .css({marginLeft: "auto"})
                    .html('<span class="fas fa-times"></span>');
                $remove.on("click", () => {
                    parent.value = parent.value.filter((node) => node !== group);
                    this.renderTree();
                    this.onChanged();
                });
                $heading.append($remove);
            }

            const $body = $("<div>").addClass("panel-body").css({padding: "10px"});
            const children = Array.isArray(group.value) ? group.value : [];

            if (!children.length) {
                $body.append(
                    $("<div>")
                        .addClass("text-muted")
                        .text(this.translate("conditionsEmpty", "messages", "JourneyStageAction"))
                );
            }

            children.forEach((node) => {
                if (node && (node.type === "and" || node.type === "or")) {
                    $body.append(this.buildGroup(node, group, depth + 1));
                } else {
                    $body.append(this.buildLeaf(node || {}, group));
                }
            });

            $mode.on("change", () => {
                group.type = $mode.val() === "or" ? "or" : "and";
                this.onChanged();
            });
            $addCondition.on("click", () => {
                if (depth >= MAX_DEPTH || this.countLeaves(this._tree) >= 50) {
                    return;
                }

                group.value.push({type: "equals", attribute: "", value: ""});
                this.renderTree();
                this.onChanged();
            });
            $addGroup.on("click", () => {
                if (depth >= MAX_DEPTH - 1) {
                    return;
                }

                group.value.push({type: "and", value: []});
                this.renderTree();
                this.onChanged();
            });

            return $group.append($heading).append($body);
        },

        buildLeaf: function (leaf, parent) {
            const $row = $("<div>").addClass("row").css({marginBottom: "6px"});
            const $attr = $("<select>").addClass("form-control input-sm");
            const $op = $("<select>").addClass("form-control input-sm");
            const $valueHost = $("<div>");

            $attr.append($("<option>").val("").text("-"));
            this._attrOptions.forEach((option) => {
                $attr.append($("<option>").val(option.value).text(option.label));
            });
            if (leaf.attribute && !this._attrOptions.some((option) => option.value === leaf.attribute)) {
                $attr.append($("<option>").val(leaf.attribute).text(leaf.attribute + " *"));
            }
            $attr.val(leaf.attribute || "");

            const operators = this.cfHelper.whereOperators();
            operators.forEach((operator) => {
                $op.append(
                    $("<option>")
                        .val(operator)
                        .text(this.translate(operator, "conditionOperators", "JourneyStageAction"))
                );
            });
            if (leaf.type && operators.indexOf(leaf.type) === -1) {
                $op.append($("<option>").val(leaf.type).text(leaf.type + " *"));
            }
            $op.val(leaf.type || "equals");

            const $remove = $("<button>")
                .attr("type", "button")
                .addClass("btn btn-default btn-sm")
                .html('<span class="fas fa-times"></span>');

            $row.append($("<div>").addClass("col-sm-4").append($attr));
            $row.append($("<div>").addClass("col-sm-3").append($op));
            $row.append($("<div>").addClass("col-sm-4").append($valueHost));
            $row.append($("<div>").addClass("col-sm-1").append($remove));

            if (
                this.cfHelper.operatorNeedsValue(leaf.type || "equals") &&
                this.allowsValueFormula()
            ) {
                const displayValue = Array.isArray(leaf.value)
                    ? leaf.value.join(", ")
                    : leaf.value === undefined || leaf.value === null
                        ? ""
                        : String(leaf.value);
                let valueInput;

                valueInput = new ExpressionInput(this, {
                    paramKey: "conditionValue",
                    mode: leaf.valueFormula ? "expression" : "fixed",
                    fixedValue: displayValue,
                    expressionValue: leaf.valueFormula || "",
                    snippets: this.valueSnippets(),
                    onChange: () => {
                        const state = valueInput.getState();
                        leaf.value = this.cfHelper.coerceValue(
                            state.fixed,
                            leaf.type || "equals"
                        );

                        if (state.mode === "expression" && state.expression) {
                            leaf.valueFormula = state.expression;
                        } else {
                            delete leaf.valueFormula;
                        }

                        this.onChanged();
                    },
                }).mount($valueHost);
                this._valueInputs.push(valueInput);
            } else if (this.cfHelper.operatorNeedsValue(leaf.type || "equals")) {
                const displayValue = Array.isArray(leaf.value)
                    ? leaf.value.join(", ")
                    : leaf.value === undefined || leaf.value === null
                        ? ""
                        : String(leaf.value);
                const $staticValue = $("<input>")
                    .attr("type", "text")
                    .addClass("form-control input-sm")
                    .val(displayValue);
                $staticValue.on("change input", () => {
                    leaf.value = this.cfHelper.coerceValue(
                        $staticValue.val(),
                        leaf.type || "equals"
                    );
                    this.onChanged();
                });
                $valueHost.append($staticValue);
            } else {
                $valueHost.append(
                    $("<input>")
                        .attr("type", "text")
                        .addClass("form-control input-sm")
                        .prop("disabled", true)
                );
            }

            const sync = () => {
                leaf.attribute = $attr.val() || "";
                leaf.type = $op.val() || "equals";

                if (!this.cfHelper.operatorNeedsValue(leaf.type)) {
                    delete leaf.value;
                    delete leaf.valueFormula;
                }

                this.renderTree();
                this.onChanged();
            };

            $attr.on("change", sync);
            $op.on("change", sync);
            $remove.on("click", () => {
                parent.value = parent.value.filter((node) => node !== leaf);
                this.renderTree();
                this.onChanged();
            });

            return $row;
        },

        onChanged: function () {
            this._rawDirty = false;
            this._parseError = false;

            if (this._advancedOpen && this.$raw) {
                this.$raw.val(JSON.stringify(this.cleanTree(this._tree), null, 2));
            }

            this.trigger("change");
        },

        cleanTree: function (tree) {
            return this.cleanNode(tree);
        },

        cleanNode: function (node) {
            if (!node || typeof node !== "object" || Array.isArray(node)) {
                return null;
            }

            if (node.type === "and" || node.type === "or") {
                const children = (Array.isArray(node.value) ? node.value : [])
                    .map((child) => this.cleanNode(child))
                    .filter((child) => child !== null);

                return children.length ? {type: node.type, value: children} : null;
            }

            const attribute = String(node.attribute || "").trim();
            if (!attribute) {
                return null;
            }

            const type = node.type || "equals";
            const clean = {type: type, attribute: attribute};

            if (this.cfHelper.operatorNeedsValue(type)) {
                clean.value = type === "in" && Array.isArray(node.value)
                    ? this.cloneValue(node.value)
                    : this.cfHelper.coerceValue(
                        node.value === undefined ? "" : node.value,
                        type
                    );

                if (
                    this.allowsValueFormula() &&
                    typeof node.valueFormula === "string" &&
                    node.valueFormula.trim()
                ) {
                    clean.valueFormula = node.valueFormula.trim();
                }
            }

            return clean;
        },

        countLeaves: function (node) {
            if (!node) {
                return 0;
            }

            if (node.type === "and" || node.type === "or") {
                return (node.value || []).reduce(
                    (count, child) => count + this.countLeaves(child),
                    0
                );
            }

            return node.attribute ? 1 : 0;
        },

        renderSummaryHtml: function (node) {
            if (!node) {
                return "";
            }

            const escapeHtml = (value) => this.getHelper().escapeString(String(value));

            if (node.type === "and" || node.type === "or") {
                const label = node.type === "or"
                    ? this.translate("matchAny", "labels", "JourneyStageAction")
                    : this.translate("matchAll", "labels", "JourneyStageAction");
                const children = (node.value || [])
                    .map((child) => "<li>" + this.renderSummaryHtml(child) + "</li>")
                    .join("");

                return "<strong>" + escapeHtml(label) + "</strong><ul>" + children + "</ul>";
            }

            const operator = this.translate(
                node.type || "equals",
                "conditionOperators",
                "JourneyStageAction"
            );
            const value = node.value === undefined
                ? ""
                : " " + escapeHtml(Array.isArray(node.value) ? node.value.join(", ") : node.value);
            const formula = node.valueFormula
                ? " <code>fx</code> " + escapeHtml(node.valueFormula)
                : "";

            return escapeHtml(node.attribute || "?") + " " + escapeHtml(operator) + formula + value;
        },

        valueSnippets: function () {
            const prefix = this.cfHelper.attributeName() + ".";

            return [{
                kind: "field",
                label: this.translate("snippetGroupTarget", "labels", "JourneyStageAction"),
                items: this._attrOptions.map((option) => {
                    const insert = option.value.indexOf(prefix) === 0
                        ? "object\\get(entity\\attribute('" +
                            this.cfHelper.attributeName() + "'), '" +
                            option.value.slice(prefix.length).replace(/'/g, "\\'") + "')"
                        : "entity\\attribute('" + option.value.replace(/'/g, "\\'") + "')";

                    return {
                        label: option.label,
                        chip: option.label,
                        insert: insert,
                    };
                }),
            }];
        },

        allowsValueFormula: function () {
            return !!this.model &&
                this.model.entityType === "JourneyStageAction" &&
                this.name === "conditionsGroup";
        },

        destroyValueInputs: function () {
            (this._valueInputs || []).forEach((input) => {
                input.destroy();
            });
            this._valueInputs = [];
        },

        renderFormulaOverrideWarning: function () {
            if (!this.$el) {
                return;
            }

            const formula = this.model.get("conditionFormula");
            this.$el.find(".formula-override-warning")
                .toggleClass("hidden", !(typeof formula === "string" && formula.trim()))
                .text(this.translate(
                    "conditionFormulaOverride",
                    "messages",
                    "JourneyStageAction"
                ));
        },

        fetch: function () {
            const data = {};

            if (this._rawDirty && this._parseError) {
                data[this.name] = this.model.get(this.name);

                return data;
            }

            data[this.name] = this.cleanTree(this._tree);

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

        remove: function () {
            this._removed = true;
            this._loadGeneration++;
            this.destroyValueInputs();
            this.$tree = null;

            return Dep.prototype.remove.call(this);
        },
    });
});
