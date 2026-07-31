define("feature-journey:views/journey-stage-action/fields/params", [
    "views/fields/base",
    "model",
    "feature-journey:helpers/custom-fields",
    "feature-journey:helpers/expression-input",
], function (Dep, Model, CustomFieldsHelper, ExpressionInput) {
    /**
     * Zapier-style typed params form driven by app.journeyActionTypes.types.*.paramDefs.
     * n8n-style fx expression inputs map to params.paramFormulas (MODE_CONDITION).
     * Falls back to raw JSON for unknown shapes / Advanced toggle.
     */
    return Dep.extend({
        type: "jsonObject",

        editTemplateContent:
            '<div class="journey-params-field">' +
                '{{#if tierPlatform}}' +
                '<div class="alert alert-warning" style="padding:8px 12px;margin-bottom:10px">' +
                    '{{translate "platformActionHint" category="messages" scope="JourneyStageAction"}}' +
                '</div>' +
                '{{/if}}' +
                '{{#if noType}}' +
                '<span class="text-muted">{{translate "selectActionTypeFirst" category="messages" scope="JourneyStageAction"}}</span>' +
                '{{else}}' +
                '{{#if useFormulaOnly}}' +
                '<p class="text-muted small" style="margin-top:0">' +
                    '{{translate "useFormulaField" category="messages" scope="JourneyStageAction"}}' +
                '</p>' +
                '{{/if}}' +
                '<div class="params-form-container"></div>' +
                '<div class="params-advanced margin-top">' +
                    '<a role="button" tabindex="0" data-action="toggleAdvanced" class="small">' +
                        '{{translate "advancedJson" category="labels" scope="JourneyStageAction"}}' +
                    '</a>' +
                    '<div class="params-raw-wrap hidden margin-top-sm">' +
                        '<textarea class="form-control params-raw" rows="6"></textarea>' +
                        '<p class="text-muted small" style="margin-top:4px">' +
                            '{{translate "paramFormulasHint" category="messages" scope="JourneyStageAction"}}' +
                        '</p>' +
                    '</div>' +
                '</div>' +
                '{{/if}}' +
            '</div>',

        detailTemplateContent:
            '<div class="journey-params-detail">' +
                '{{#if isNotEmpty}}' +
                '{{#if detailRows.length}}' +
                '<div class="list-group" style="margin-bottom:0">' +
                    '{{#each detailRows}}' +
                    '<div class="list-group-item" style="padding:6px 10px">' +
                        '<span class="text-muted">{{this.label}}:</span> ' +
                        '{{{this.value}}}' +
                    '</div>' +
                    '{{/each}}' +
                '</div>' +
                '{{else}}' +
                '<pre style="margin:0;white-space:pre-wrap;font-size:12px">{{rawJson}}</pre>' +
                '{{/if}}' +
                '{{else}}' +
                '<span class="none-value">{{translate "None"}}</span>' +
                '{{/if}}' +
            '</div>',

        listTemplateContent:
            '{{#if isNotEmpty}}' +
            '<span class="text-muted" title="{{rawJson}}">{{translate "Configured" category="labels" scope="Global"}}</span>' +
            '{{else}}' +
            '<span class="none-value">{{translate "None"}}</span>' +
            '{{/if}}',

        setup: function () {
            Dep.prototype.setup.call(this);

            this.cfHelper = new CustomFieldsHelper(this);
            this._paramViews = [];
            this._helperModel = null;
            this._advancedOpen = false;
            this._fieldMapRows = [];
            this._fieldMapOptions = [];
            this._fieldMapOptionsReady = null;
            this._expressionInputs = {};
            this._snippets = null;
            this._targetEntityType = null;

            // Param defs come from type — re-paint when user picks/changes action.
            this.listenTo(this.model, "change:type", () => {
                this._snippets = null;
                this._fieldMapOptionsReady = null;
                this._fieldMapOptions = [];
                this.clearParamViews();
                this.reRender();
            });
        },

        getParamsObject: function () {
            const value = this.model.get(this.name);

            if (!value) {
                return {};
            }

            if (typeof value === "string") {
                try {
                    return JSON.parse(value) || {};
                } catch (e) {
                    return {};
                }
            }

            if (typeof value === "object") {
                return Object.assign({}, value);
            }

            return {};
        },

        getParamFormulas: function (params) {
            const raw = (params && params.paramFormulas) || {};

            if (typeof raw !== "object" || raw === null) {
                return {};
            }

            const out = {};

            Object.keys(raw).forEach((k) => {
                if (typeof raw[k] === "string" && raw[k].trim() !== "") {
                    out[k] = raw[k];
                }
            });

            return out;
        },

        getTypeMeta: function () {
            const type = this.model.get("type");

            if (!type) {
                return null;
            }

            return (
                this.getMetadata().get(["app", "journeyActionTypes", "types", type]) ||
                {}
            );
        },

        data: function () {
            const meta = this.getTypeMeta() || {};
            const params = this.getParamsObject();
            const defs = meta.paramDefs || [];
            const data = Dep.prototype.data.call(this);

            data.noType = !this.model.get("type");
            data.tierPlatform = meta.tier === "platform";
            data.useFormulaOnly =
                !!meta.hasFormulaField && (!defs || defs.length === 0);
            data.isNotEmpty = Object.keys(params).length > 0;
            data.rawJson = data.isNotEmpty
                ? JSON.stringify(params, null, 2)
                : "";
            data.detailRows = this.buildDetailRows(defs, params);

            return data;
        },

        formatFxDetail: function (formula) {
            return (
                '<span class="journey-fx-badge">fx</span>' +
                '<span class="journey-fx-value">' +
                this.getHelper().escapeString(String(formula)) +
                "</span>"
            );
        },

        buildDetailRows: function (defs, params) {
            const rows = [];
            const formulas = this.getParamFormulas(params);
            const skip = { paramFormulas: true };
            const seen = {};

            (defs || []).forEach((def) => {
                if (def.type === "whatsappTemplate") {
                    const managedKeys = def.managedKeys || [
                        "templateName",
                        "templateLanguage",
                        "templateCategory",
                        "parameterMapping",
                        "templateBody",
                        "headerMediaUrl",
                        "headerMediaType",
                    ];

                    managedKeys.forEach((k) => {
                        seen[k] = true;
                    });

                    const name = params.templateName;
                    const lang = params.templateLanguage;
                    const cat = params.templateCategory;

                    if (!name && !lang) {
                        return;
                    }

                    let display = String(name || "");

                    if (lang) {
                        display += " (" + lang + ")";
                    }

                    if (cat) {
                        display += " [" + cat + "]";
                    }

                    rows.push({
                        label: def.label || "Template",
                        value: this.getHelper().escapeString(display),
                    });

                    if (params.parameterMapping && typeof params.parameterMapping === "object") {
                        rows.push({
                            label: "Parameter mapping",
                            value: this.getHelper().escapeString(
                                JSON.stringify(params.parameterMapping)
                            ),
                        });
                    }

                    return;
                }

                if (def.type === "emailTemplate") {
                    const managedKeys = def.managedKeys || [
                        "emailTemplateId",
                        "emailTemplateName",
                    ];

                    managedKeys.forEach((k) => {
                        seen[k] = true;
                    });

                    const display =
                        params.emailTemplateName || params.emailTemplateId || "";

                    if (!display) {
                        return;
                    }

                    rows.push({
                        label: def.label || "Email Template",
                        value: this.getHelper().escapeString(String(display)),
                    });

                    return;
                }

                if (def.type === "fieldMap") {
                    const fields = params.fields || {};
                    const keys = Object.keys(fields);
                    const fxKeys = Object.keys(formulas).filter((k) =>
                        k.indexOf("fields.") === 0
                    );

                    if (!keys.length && !fxKeys.length) {
                        return;
                    }

                    const parts = keys.map((k) => {
                        const fk = "fields." + k;

                        if (formulas[fk]) {
                            return (
                                this.getHelper().escapeString(k) +
                                " = " +
                                this.formatFxDetail(formulas[fk])
                            );
                        }

                        return this.getHelper().escapeString(
                            k + " = " + String(fields[k])
                        );
                    });

                    fxKeys.forEach((fk) => {
                        const k = fk.slice("fields.".length);

                        if (Object.prototype.hasOwnProperty.call(fields, k)) {
                            return;
                        }

                        parts.push(
                            this.getHelper().escapeString(k) +
                                " = " +
                                this.formatFxDetail(formulas[fk])
                        );
                        seen[fk] = true;
                    });

                    keys.forEach((k) => {
                        seen[def.name] = true;
                        seen["fields." + k] = true;
                    });

                    rows.push({
                        label: def.label || "Fields",
                        value: parts.join("<br>"),
                    });

                    return;
                }

                seen[def.name] = true;

                if (formulas[def.name]) {
                    rows.push({
                        label: def.label || def.name,
                        value: this.formatFxDetail(formulas[def.name]),
                    });

                    return;
                }

                const val = params[def.name];

                if (val === undefined || val === null || val === "") {
                    return;
                }

                let display = val;

                if (def.type === "bool") {
                    display = val ? "Yes" : "No";
                } else if (def.type === "link" && def.nameKey && params[def.nameKey]) {
                    display = params[def.nameKey];
                } else if (def.type === "json" && typeof val === "object") {
                    display = JSON.stringify(val);
                }

                rows.push({
                    label: def.label || def.name,
                    value: this.getHelper().escapeString(String(display)),
                });
            });

            Object.keys(formulas).forEach((key) => {
                if (seen[key] || key.indexOf("fields.") === 0) {
                    return;
                }

                rows.push({
                    label: key,
                    value: this.formatFxDetail(formulas[key]),
                });
                seen[key] = true;
            });

            Object.keys(params).forEach((key) => {
                if (skip[key] || seen[key]) {
                    return;
                }

                if ((defs || []).some((d) => d.name === key || d.type === "fieldMap")) {
                    return;
                }

                if (key.endsWith("Name")) {
                    return;
                }

                rows.push({
                    label: key,
                    value: this.getHelper().escapeString(
                        typeof params[key] === "object"
                            ? JSON.stringify(params[key])
                            : String(params[key])
                    ),
                });
            });

            return rows;
        },

        afterRender: function () {
            if (!this.isEditMode()) {
                return;
            }

            this.$form = this.$el.find(".params-form-container");
            this.$raw = this.$el.find(".params-raw");
            this.$rawWrap = this.$el.find(".params-raw-wrap");

            this.$el.find('[data-action="toggleAdvanced"]').on("click", () => {
                this._advancedOpen = !this._advancedOpen;
                this.$rawWrap.toggleClass("hidden", !this._advancedOpen);

                if (this._advancedOpen) {
                    this.syncRawFromModel();
                }
            });

            if (this._advancedOpen) {
                this.$rawWrap.removeClass("hidden");
            }

            this.$raw.on("change input", () => {
                this._rawDirty = true;
                this.trigger("change");
            });

            this.clearParamViews();
            this.renderParamForm();
        },

        clearParamViews: function () {
            (this._paramViews || []).forEach((name) => {
                this.clearView(name);
            });
            this._paramViews = [];
            this._helperModel = null;
            this._fieldMapRows = [];
            this._fieldMapOptionsReady = null;
            this._fieldMapOptions = [];

            Object.keys(this._expressionInputs || {}).forEach((k) => {
                const ex = this._expressionInputs[k];

                if (ex && typeof ex.destroy === "function") {
                    ex.destroy();
                }
            });
            this._expressionInputs = {};
        },

        markChanged: function () {
            this._rawDirty = false;
            this.trigger("change");
        },

        resolveSnippets: function () {
            if (this._snippets) {
                return Promise.resolve(this._snippets);
            }

            return this.cfHelper
                .resolveContext()
                .then((ctx) => {
                    this._targetEntityType = (ctx && ctx.entityType) || null;
                    this._snippets = ExpressionInput.defaultSnippets(
                        this,
                        this._targetEntityType
                    );

                    return this._snippets;
                })
                .catch(() => {
                    this._snippets = ExpressionInput.defaultSnippets(this, null);

                    return this._snippets;
                });
        },

        renderParamForm: function () {
            if (!this.$form || !this.$form.length) {
                return;
            }

            this.$form.empty();
            const meta = this.getTypeMeta();

            if (!meta) {
                return;
            }

            const defs = meta.paramDefs || [];
            const params = this.getParamsObject();

            this._helperModel = new Model();
            this._helperModel.name = "JourneyActionParams";
            this._helperModel.set(params);

            if (!defs.length) {
                this.syncRawFromModel();

                return;
            }

            this.resolveSnippets().then(() => {
                this.renderTypeHint();

                defs.forEach((def) => {
                    if (def.type === "fieldMap") {
                        this.renderFieldMap(def, params);
                    } else {
                        this.renderParamDef(def, params);
                    }
                });

                this.renderOrphanParamFormulas(params, defs);
            });
        },

        /**
         * Optional per-type help from messages.<type>Hint (JourneyStageAction).
         */
        renderTypeHint: function () {
            if (!this.$form || !this.$form.length) {
                return;
            }

            const type = this.model.get("type");

            if (!type) {
                return;
            }

            const key = type + "Hint";
            const text = this.translate(key, "messages", "JourneyStageAction");

            if (!text || text === key) {
                return;
            }

            this.$form.prepend(
                $("<p>")
                    .addClass("text-muted small")
                    .css({ marginTop: 0, marginBottom: "10px" })
                    .text(text)
            );
        },

        mountExpression: function (key, $host, options) {
            const ex = new ExpressionInput(
                this,
                Object.assign(
                    {
                        paramKey: key,
                        snippets: this._snippets || [],
                        onChange: () => this.markChanged(),
                    },
                    options || {}
                )
            );

            ex.mount($host);
            this._expressionInputs[key] = ex;

            return ex;
        },

        renderParamDef: function (def, params) {
            const name = def.name;
            const viewName = "param-" + name;
            const formulas = this.getParamFormulas(params);
            const supportsExpr = ExpressionInput.supportsType(def.type);

            const $group = $("<div>")
                .addClass("form-group")
                .attr("data-param", name);
            const $label = $("<label>")
                .addClass("control-label")
                .text(def.label || name);

            if (def.required) {
                $label.append(' <span class="text-danger">*</span>');
            }

            $group.append($label);

            if (def.type === "link" && supportsExpr) {
                this.renderLinkParam(def, params, formulas, $group);
                this.$form.append($group);

                return;
            }

            if (def.type === "whatsappTemplate") {
                this.renderWhatsAppTemplateParam(def, params, $group);
                this.$form.append($group);

                return;
            }

            if (def.type === "emailTemplate") {
                this.renderEmailTemplateParam(def, params, $group);
                this.$form.append($group);

                return;
            }

            if (
                supportsExpr &&
                (def.type === "varchar" ||
                    def.type === "text" ||
                    def.type === "json" ||
                    !def.type)
            ) {
                const $host = $("<div>").addClass("journey-expression-host");
                $group.append($host);
                this.$form.append($group);

                let fixed = params[name];

                if (def.type === "json" && fixed && typeof fixed === "object") {
                    fixed = JSON.stringify(fixed, null, 2);
                }

                if (fixed === undefined || fixed === null) {
                    fixed = def.default !== undefined ? def.default : "";
                }

                this.mountExpression(name, $host, {
                    multiline: def.type === "text" || def.type === "json",
                    rows: def.type === "json" ? 4 : 3,
                    placeholder: def.placeholder || "",
                    fixedValue: fixed === false || fixed === true ? String(fixed) : fixed,
                    expressionValue: formulas[name] || "",
                    mode: formulas[name] ? "expression" : "fixed",
                });

                return;
            }

            // bool / enum (no expression) — standard field views
            const $field = $("<div>").addClass("field").attr("data-name", name);
            $group.append($field);
            this.$form.append($group);

            let viewClass = "views/fields/varchar";
            const options = {
                model: this._helperModel,
                name: name,
                el: this.getSelector() + ' [data-param="' + name + '"] .field',
                mode: "edit",
                defs: {
                    name: name,
                    type: def.type || "varchar",
                },
                params: {},
            };

            if (def.placeholder) {
                options.params.placeholder = def.placeholder;
            }

            switch (def.type) {
                case "bool":
                    viewClass = "views/fields/bool";

                    if (
                        this._helperModel.get(name) === undefined &&
                        def.default !== undefined
                    ) {
                        this._helperModel.set(name, def.default);
                    }

                    break;
                case "enum":
                    viewClass = "views/fields/enum";
                    options.params.options = def.options || [];

                    if (
                        this._helperModel.get(name) === undefined &&
                        def.default !== undefined
                    ) {
                        this._helperModel.set(name, def.default);
                    }

                    break;
                default:
                    viewClass = "views/fields/varchar";

                    if (
                        this._helperModel.get(name) === undefined &&
                        def.default !== undefined
                    ) {
                        this._helperModel.set(name, def.default);
                    }
            }

            this._paramViews.push(viewName);
            this.createView(viewName, viewClass, options, (view) => {
                view.render();
                this.listenTo(view, "change", () => {
                    this.markChanged();
                });
            });
        },

        renderWhatsAppTemplateParam: function (def, params, $group) {
            const name = def.name;
            const viewName = "param-" + name;
            const managedKeys = def.managedKeys || [
                "templateName",
                "templateLanguage",
                "templateCategory",
                "parameterMapping",
                "templateBody",
                "headerMediaUrl",
                "headerMediaType",
            ];

            this._whatsappTemplateManaged = this._whatsappTemplateManaged || {};
            this._whatsappTemplateManaged[viewName] = managedKeys;

            managedKeys.forEach((key) => {
                if (params[key] !== undefined) {
                    this._helperModel.set(key, params[key]);
                }
            });

            const $field = $("<div>")
                .addClass("field journey-whatsapp-template-field")
                .attr("data-name", name);
            $group.append($field);

            $group.append(
                $("<p>")
                    .addClass("text-muted small")
                    .css({ marginTop: "4px", marginBottom: 0 })
                    .text(
                        this.translate(
                            "sendWhatsAppTemplateHint",
                            "messages",
                            "JourneyStageAction"
                        )
                    )
            );

            this._paramViews.push(viewName);
            this.createView(
                viewName,
                "feature-journey:views/journey-stage-action/fields/whatsapp-template",
                {
                    model: this._helperModel,
                    name: name,
                    el:
                        this.getSelector() +
                        ' [data-param="' +
                        name +
                        '"] .journey-whatsapp-template-field',
                    mode: "edit",
                    defs: {
                        name: name,
                        type: "varchar",
                    },
                    params: {},
                },
                (view) => {
                    view.render();
                    this.listenTo(view, "change", () => {
                        this.markChanged();
                    });
                    this.listenTo(this._helperModel, "change", () => {
                        this.markChanged();
                    });
                }
            );
        },

        renderEmailTemplateParam: function (def, params, $group) {
            const name = def.name;
            const viewName = "param-" + name;
            const managedKeys = def.managedKeys || [
                "emailTemplateId",
                "emailTemplateName",
            ];

            this._emailTemplateManaged = this._emailTemplateManaged || {};
            this._emailTemplateManaged[viewName] = managedKeys;

            managedKeys.forEach((key) => {
                if (params[key] !== undefined) {
                    this._helperModel.set(key, params[key]);
                }
            });

            // Link field name is emailTemplate → emailTemplateId / emailTemplateName
            if (params.emailTemplateId) {
                this._helperModel.set("emailTemplateId", params.emailTemplateId);
                this._helperModel.set(
                    "emailTemplateName",
                    params.emailTemplateName || params.emailTemplateId
                );
            }

            const $field = $("<div>")
                .addClass("field journey-email-template-field")
                .attr("data-name", name);
            $group.append($field);

            $group.append(
                $("<p>")
                    .addClass("text-muted small")
                    .css({ marginTop: "4px", marginBottom: 0 })
                    .text(
                        this.translate(
                            "sendEmailTemplateHint",
                            "messages",
                            "JourneyStageAction"
                        )
                    )
            );

            this._paramViews.push(viewName);
            this.createView(
                viewName,
                "feature-journey:views/journey-stage-action/fields/email-template",
                {
                    model: this._helperModel,
                    name: name,
                    el:
                        this.getSelector() +
                        ' [data-param="' +
                        name +
                        '"] .journey-email-template-field',
                    mode: "edit",
                    defs: {
                        name: name,
                        type: "varchar",
                    },
                    params: {},
                },
                (view) => {
                    view.render();
                    this.listenTo(view, "change", () => {
                        this.markChanged();
                    });
                    this.listenTo(this._helperModel, "change:emailTemplateId", () => {
                        this.markChanged();
                    });
                }
            );
        },

        renderLinkParam: function (def, params, formulas, $group) {
            const name = def.name;
            const viewName = "param-" + name;
            const base = name.replace(/Id$/, "");
            const nameKey = def.nameKey || base + "Name";
            const hasFx = !!formulas[name];

            const $wrap = $("<div>")
                .addClass("journey-param-link-expr")
                .toggleClass("is-expression", hasFx);

            const $toolbar = $("<div>").addClass("journey-link-expr-toolbar");
            const $fxBtn = $("<button>")
                .attr({
                    type: "button",
                    title: this.translate(
                        "expressionToggle",
                        "labels",
                        "JourneyStageAction"
                    ),
                })
                .addClass("journey-expression-fx")
                .toggleClass("active", hasFx)
                .html("<span>fx</span>");
            $toolbar.append($fxBtn);

            const $linkHost = $("<div>")
                .addClass("field link-field-host")
                .attr("data-name", name);
            const $exHost = $("<div>").addClass("journey-expression-host");

            $wrap.append($toolbar).append($linkHost).append($exHost);
            $group.append($wrap);
            $group.append(
                $("<p>")
                    .addClass("text-muted small")
                    .css({ marginTop: "4px", marginBottom: 0 })
                    .text(
                        this.translate(
                            "linkExpressionHint",
                            "messages",
                            "JourneyStageAction"
                        )
                    )
            );

            const idVal = hasFx ? null : params[name] || null;
            const nameVal = hasFx ? null : params[nameKey] || idVal || null;

            this._helperModel.set(base + "Id", idVal);
            this._helperModel.set(base + "Name", nameVal);

            this._linkBaseMap = this._linkBaseMap || {};
            this._linkBaseMap[viewName] = {
                base: base,
                idKey: name,
                nameKey: nameKey,
            };

            this._paramViews.push(viewName);
            const linkParams = {};

            if (def.primaryFilter) {
                linkParams.primaryFilter = def.primaryFilter;
            }

            this.createView(
                viewName,
                "views/fields/link",
                {
                    model: this._helperModel,
                    name: base,
                    foreignScope: def.entity || "User",
                    el:
                        this.getSelector() +
                        ' [data-param="' +
                        name +
                        '"] .link-field-host',
                    mode: "edit",
                    defs: {
                        name: base,
                        type: "link",
                    },
                    params: linkParams,
                },
                (view) => {
                    view.render();
                    this.listenTo(view, "change", () => {
                        this.markChanged();
                    });
                }
            );

            const ex = this.mountExpression(name, $exHost, {
                multiline: false,
                placeholder: "",
                fixedValue: "",
                expressionValue: formulas[name] || "",
                mode: hasFx ? "expression" : "fixed",
                expressionPlaceholder: "entity\\attribute('assignedUserId')",
            });

            // Hide inner fx (toolbar owns toggle for link layout)
            if (ex.$fx) {
                ex.$fx.addClass("hidden");
            }

            if (ex.$tools) {
                // keep picker on the expression row
            }

            const applyMode = (mode) => {
                const isEx = mode === "expression";
                $wrap.toggleClass("is-expression", isEx);
                $fxBtn.toggleClass("active", isEx);
                ex.setMode(mode);

                if (isEx) {
                    this._helperModel.set(base + "Id", null);
                    this._helperModel.set(base + "Name", null);
                }

                this.markChanged();
            };

            $fxBtn.on("click", (e) => {
                e.preventDefault();
                e.stopPropagation();
                applyMode(
                    ex.getMode() === "expression" ? "fixed" : "expression"
                );
            });
        },

        renderFieldMap: function (def, params) {
            const fields = (params && params.fields) || {};
            const formulas = this.getParamFormulas(params);
            const $wrap = $("<div>").addClass("form-group field-map-wrap");
            $wrap.append(
                $("<label>")
                    .addClass("control-label")
                    .text(def.label || "Fields")
            );
            $wrap.append(
                $("<p>")
                    .addClass("text-muted small")
                    .css({ marginTop: 0 })
                    .text(
                        this.translate(
                            "updateTargetFieldsHint",
                            "messages",
                            "JourneyStageAction"
                        )
                    )
            );
            const $rows = $("<div>").addClass("field-map-rows");
            $wrap.append($rows);

            const $add = $("<button>")
                .attr("type", "button")
                .addClass("btn btn-default btn-sm")
                .html(
                    '<span class="fas fa-plus"></span> ' +
                        this.translate("Add Field", "labels", "JourneyStageAction")
                );
            $wrap.append($("<div>").addClass("margin-top-sm").append($add));

            this.$form.append($wrap);

            const addRow = (key, value, formula) => {
                const $row = $("<div>")
                    .addClass("row field-map-row")
                    .css({ marginBottom: "8px" });

                const $col1 = $("<div>").addClass("col-sm-4");
                const $col2 = $("<div>").addClass("col-sm-7");
                const $col3 = $("<div>").addClass("col-sm-1");

                const $select = $("<select>").addClass("form-control field-map-key");
                this.populateFieldMapSelect($select, key);

                const $valHost = $("<div>").addClass("journey-expression-host");
                $col2.append($valHost);

                const $rm = $("<button>")
                    .attr("type", "button")
                    .addClass("btn btn-default btn-sm")
                    .html('<span class="fas fa-times"></span>');

                $col1.append($select);
                $col3.append($rm);
                $row.append($col1).append($col2).append($col3);
                $rows.append($row);

                let displayVal = "";

                if (value !== undefined && value !== null && !formula) {
                    displayVal =
                        typeof value === "object"
                            ? JSON.stringify(value)
                            : String(value);
                }

                const pathKey = key ? "fields." + key : "fields.__new_" + Date.now();
                const ex = this.mountExpression(pathKey, $valHost, {
                    multiline: false,
                    fixedValue: displayVal,
                    expressionValue: formula || "",
                    mode: formula ? "expression" : "fixed",
                    expressionPlaceholder: "entity\\attribute('name')",
                });

                const row = {
                    $select: $select,
                    $row: $row,
                    expression: ex,
                    pathKey: pathKey,
                };
                this._fieldMapRows.push(row);

                const onKeyChange = () => {
                    this.markChanged();
                };
                $select.on("change", onKeyChange);
                $rm.on("click", () => {
                    $row.remove();

                    if (ex && typeof ex.destroy === "function") {
                        ex.destroy();
                    }

                    delete this._expressionInputs[row.pathKey];
                    this._fieldMapRows = this._fieldMapRows.filter((r) => r !== row);
                    this.markChanged();
                });
            };

            const paint = () => {
                const painted = {};

                Object.keys(fields).forEach((k) => {
                    const fk = "fields." + k;
                    addRow(k, fields[k], formulas[fk] || null);
                    painted[fk] = true;
                });

                Object.keys(formulas).forEach((fk) => {
                    if (fk.indexOf("fields.") !== 0 || painted[fk]) {
                        return;
                    }

                    addRow(fk.slice("fields.".length), "", formulas[fk]);
                });

                if (!this._fieldMapRows.length) {
                    addRow("", "", null);
                }
            };

            this.loadFieldMapOptions().then(() => paint());

            $add.on("click", () => addRow("", "", null));
        },

        populateFieldMapSelect: function ($select, key) {
            $select.empty();
            $select.append($("<option>").val("").text("—"));
            (this._fieldMapOptions || []).forEach((opt) => {
                const $o = $("<option>").val(opt.value).text(opt.label);

                if (opt.value === key) {
                    $o.prop("selected", true);
                }

                $select.append($o);
            });

            if (
                key &&
                !(this._fieldMapOptions || []).some((o) => o.value === key)
            ) {
                $select.append(
                    $("<option>")
                        .val(key)
                        .text(key + " *")
                        .prop("selected", true)
                );
            }
        },

        loadFieldMapOptions: function () {
            if (this._fieldMapOptionsReady) {
                return this._fieldMapOptionsReady;
            }

            this._fieldMapOptionsReady = this.cfHelper
                .resolveContext()
                .then((ctx) => {
                    const stageId = this.model.get("stageId");

                    if (ctx.entityType && ctx.tenantId) {
                        return this.cfHelper.loadUpdateTargetOptions(
                            ctx.entityType,
                            ctx.tenantId
                        );
                    }

                    if (!stageId) {
                        return this.cfHelper.loadUpdateTargetOptions(
                            ctx.entityType,
                            ctx.tenantId
                        );
                    }

                    return Espo.Ajax.getRequest("JourneyStage/" + stageId, {
                        select: "journeyId",
                    })
                        .then((stage) => {
                            if (!stage || !stage.journeyId) {
                                return ctx;
                            }

                            return Espo.Ajax.getRequest(
                                "Journey/" + stage.journeyId,
                                { select: "targetEntityType,tenantId" }
                            ).then((journey) => ({
                                entityType:
                                    (journey && journey.targetEntityType) ||
                                    ctx.entityType,
                                tenantId:
                                    (journey && journey.tenantId) || ctx.tenantId,
                            }));
                        })
                        .catch(() => ctx)
                        .then((resolved) =>
                            this.cfHelper.loadUpdateTargetOptions(
                                resolved.entityType,
                                resolved.tenantId
                            )
                        );
                })
                .then((opts) => {
                    this._fieldMapOptions = opts || [];

                    return this._fieldMapOptions;
                })
                .catch(() => {
                    this._fieldMapOptions = [];

                    return [];
                });

            return this._fieldMapOptionsReady;
        },

        coerceFieldMapValue: function (raw) {
            const s = String(raw ?? "").trim();

            if (s === "") {
                return "";
            }

            if (s === "true") {
                return true;
            }

            if (s === "false") {
                return false;
            }

            if ((s.charAt(0) === "{" || s.charAt(0) === "[") && s.length > 1) {
                try {
                    return JSON.parse(s);
                } catch (e) {
                    // keep string
                }
            }

            if (s !== "" && !isNaN(Number(s)) && /^-?\d+(\.\d+)?$/.test(s)) {
                return Number(s);
            }

            return s;
        },

        /**
         * Legacy/advanced: formulas for keys not covered by paramDefs UI.
         */
        renderOrphanParamFormulas: function (params, defs) {
            const formulas = this.getParamFormulas(params);
            const managed = {};

            (defs || []).forEach((d) => {
                managed[d.name] = true;
            });

            const orphans = Object.keys(formulas).filter((k) => {
                if (k.indexOf("fields.") === 0) {
                    return false;
                }

                return !managed[k];
            });

            if (!orphans.length) {
                return;
            }

            const $wrap = $("<div>").addClass(
                "form-group param-formulas-wrap margin-top"
            );
            $wrap.append(
                $("<label>")
                    .addClass("control-label")
                    .text(
                        this.translate(
                            "orphanParamFormulas",
                            "labels",
                            "JourneyStageAction"
                        )
                    )
            );
            $wrap.append(
                $("<p>")
                    .addClass("text-muted small")
                    .css({ marginTop: 0 })
                    .text(
                        this.translate(
                            "orphanParamFormulasHint",
                            "messages",
                            "JourneyStageAction"
                        )
                    )
            );

            orphans.forEach((k) => {
                const $row = $("<div>").css({ marginBottom: "8px" });
                $row.append(
                    $("<div>")
                        .addClass("text-muted small")
                        .text(k)
                );
                const $host = $("<div>").addClass("journey-expression-host");
                $row.append($host);
                $wrap.append($row);
                this.mountExpression(k, $host, {
                    multiline: true,
                    rows: 2,
                    fixedValue: "",
                    expressionValue: formulas[k],
                    mode: "expression",
                });
            });

            this.$form.append($wrap);
        },

        syncRawFromModel: function () {
            if (!this.$raw || !this.$raw.length) {
                return;
            }

            const params = this.getParamsObject();
            this.$raw.val(
                Object.keys(params).length ? JSON.stringify(params, null, 2) : ""
            );
            this._rawDirty = false;
        },

        collectExpressionFormulas: function (outFormulas, managedExprKeys) {
            Object.keys(this._expressionInputs || {}).forEach((key) => {
                // fieldMap expressions collected separately with live select key
                if (key.indexOf("fields.") === 0 || key.indexOf("fields.__new_") === 0) {
                    return;
                }

                const ex = this._expressionInputs[key];

                if (!ex) {
                    return;
                }

                const state = ex.getState();
                managedExprKeys[key] = true;

                if (state.mode === "expression" && state.expression) {
                    outFormulas[key] = state.expression;
                }
            });
        },

        fetchFromForm: function () {
            const meta = this.getTypeMeta() || {};
            const defs = meta.paramDefs || [];
            const out = {};
            const outFormulas = {};
            const managedExprKeys = {};
            const existing = this.getParamsObject();
            const existingFormulas = this.getParamFormulas(existing);

            for (let di = 0; di < defs.length; di++) {
                const def = defs[di];

                if (def.type === "fieldMap") {
                    const fields = {};

                    (this._fieldMapRows || []).forEach((row) => {
                        const k = String(row.$select.val() || "").trim();

                        if (!k) {
                            return;
                        }

                        const state = row.expression
                            ? row.expression.getState()
                            : { mode: "fixed", fixed: "", expression: "" };

                        if (state.mode === "expression" && state.expression) {
                            outFormulas["fields." + k] = state.expression;
                            managedExprKeys["fields." + k] = true;
                        } else if (state.fixed !== "") {
                            fields[k] = this.coerceFieldMapValue(state.fixed);
                        }
                    });

                    if (Object.keys(fields).length) {
                        out.fields = fields;
                    }

                    continue;
                }

                if (
                    def.type === "whatsappTemplate" ||
                    def.type === "emailTemplate"
                ) {
                    const view = this.getView("param-" + def.name);
                    const registry =
                        def.type === "emailTemplate"
                            ? this._emailTemplateManaged
                            : this._whatsappTemplateManaged;
                    const defaultKeys =
                        def.type === "emailTemplate"
                            ? ["emailTemplateId", "emailTemplateName"]
                            : [
                                  "templateName",
                                  "templateLanguage",
                                  "templateCategory",
                                  "parameterMapping",
                                  "templateBody",
                                  "headerMediaUrl",
                                  "headerMediaType",
                              ];
                    const managedKeys =
                        def.managedKeys ||
                        (registry && registry["param-" + def.name]) ||
                        defaultKeys;

                    let fetched = null;

                    if (view && typeof view.fetch === "function") {
                        fetched = view.fetch();
                    }

                    managedKeys.forEach((key) => {
                        let val =
                            fetched &&
                            Object.prototype.hasOwnProperty.call(fetched, key)
                                ? fetched[key]
                                : this._helperModel
                                  ? this._helperModel.get(key)
                                  : undefined;

                        if (val === null || val === undefined || val === "") {
                            return;
                        }

                        // Keep plain objects (parameterMapping) even without string coercion.
                        if (typeof val === "object" && !Array.isArray(val)) {
                            if (!Object.keys(val).length) {
                                return;
                            }

                            out[key] = val;

                            return;
                        }

                        out[key] = val;
                    });

                    continue;
                }

                if (def.type === "link") {
                    const ex = this._expressionInputs[def.name];

                    if (ex && ex.getMode() === "expression") {
                        const state = ex.getState();
                        managedExprKeys[def.name] = true;

                        if (state.expression) {
                            outFormulas[def.name] = state.expression;
                        }

                        continue;
                    }

                    const base = def.name.replace(/Id$/, "");
                    const id = this._helperModel
                        ? this._helperModel.get(base + "Id")
                        : null;
                    const nm = this._helperModel
                        ? this._helperModel.get(base + "Name")
                        : null;

                    if (id) {
                        out[def.name] = id;

                        if (def.nameKey && nm) {
                            out[def.nameKey] = nm;
                        }
                    }

                    continue;
                }

                const ex = this._expressionInputs[def.name];

                if (ex) {
                    const state = ex.getState();
                    managedExprKeys[def.name] = true;

                    if (state.mode === "expression") {
                        if (state.expression) {
                            outFormulas[def.name] = state.expression;
                        }

                        continue;
                    }

                    if (state.fixed !== "") {
                        if (def.type === "json") {
                            try {
                                out[def.name] = JSON.parse(state.fixed);
                            } catch (e) {
                                out[def.name] = state.fixed;
                            }
                        } else {
                            out[def.name] = state.fixed;
                        }
                    }

                    continue;
                }

                if (def.type === "json") {
                    const raw = this._helperModel
                        ? this._helperModel.get(def.name)
                        : null;

                    if (raw !== null && raw !== undefined && raw !== "") {
                        if (typeof raw === "object") {
                            out[def.name] = raw;
                        } else {
                            try {
                                out[def.name] = JSON.parse(String(raw));
                            } catch (e) {
                                out[def.name] = raw;
                            }
                        }
                    }

                    continue;
                }

                if (!this._helperModel) {
                    continue;
                }

                let handled = false;
                const view = this.getView("param-" + def.name);

                if (view && typeof view.fetch === "function") {
                    const fetched = view.fetch();

                    if (
                        fetched &&
                        Object.prototype.hasOwnProperty.call(fetched, def.name)
                    ) {
                        if (
                            fetched[def.name] !== null &&
                            fetched[def.name] !== undefined &&
                            fetched[def.name] !== ""
                        ) {
                            out[def.name] = fetched[def.name];
                        }

                        handled = true;
                    }
                }

                if (!handled) {
                    const val = this._helperModel.get(def.name);

                    if (val !== null && val !== undefined && val !== "") {
                        out[def.name] = val;
                    } else if (def.type === "bool") {
                        out[def.name] = !!val;
                    }
                }
            }

            this.collectExpressionFormulas(outFormulas, managedExprKeys);

            // Preserve orphan formulas not edited by managed inputs
            Object.keys(existingFormulas).forEach((k) => {
                if (managedExprKeys[k]) {
                    return;
                }

                if (outFormulas[k] === undefined) {
                    // Drop fields.* orphans when field map handled
                    if (k.indexOf("fields.") === 0) {
                        return;
                    }

                    // If we mounted an expression for it, it is managed
                    if (this._expressionInputs[k]) {
                        return;
                    }

                    outFormulas[k] = existingFormulas[k];
                }
            });

            if (Object.keys(outFormulas).length) {
                out.paramFormulas = outFormulas;
            }

            const managed = {};
            defs.forEach((d) => {
                managed[d.name] = true;

                if (d.nameKey) {
                    managed[d.nameKey] = true;
                }

                if (d.type === "fieldMap") {
                    managed.fields = true;
                }

                if (
                    (d.type === "whatsappTemplate" || d.type === "emailTemplate") &&
                    Array.isArray(d.managedKeys)
                ) {
                    d.managedKeys.forEach((k) => {
                        managed[k] = true;
                    });
                }

                if (d.type === "emailTemplate" && d.nameKey) {
                    managed[d.nameKey] = true;
                }
            });
            managed.paramFormulas = true;

            Object.keys(existing).forEach((k) => {
                if (!managed[k] && out[k] === undefined) {
                    if (k.endsWith("Name")) {
                        const idKey = k.slice(0, -4) + "Id";

                        if (managed[idKey] && !out[idKey]) {
                            return;
                        }
                    }

                    out[k] = existing[k];
                }
            });

            // When expression mode owns a key, strip stale static
            Object.keys(outFormulas).forEach((k) => {
                if (k.indexOf("fields.") === 0) {
                    return;
                }

                delete out[k];

                if (k.endsWith("Id")) {
                    delete out[k.slice(0, -2) + "Name"];
                }
            });

            return out;
        },

        fetch: function () {
            const data = {};

            if (
                this._rawDirty &&
                this.$raw &&
                this.$raw.length &&
                !this.$rawWrap.hasClass("hidden")
            ) {
                const raw = String(this.$raw.val() || "").trim();

                if (!raw) {
                    data[this.name] = null;

                    return data;
                }

                try {
                    data[this.name] = JSON.parse(raw);
                } catch (e) {
                    data[this.name] = this.model.get(this.name);
                    this._parseError = true;
                }

                return data;
            }

            this._parseError = false;
            const obj = this.fetchFromForm();
            data[this.name] = Object.keys(obj).length ? obj : null;

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
    });
});
