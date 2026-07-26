define("feature-journey:views/journey-transition/fields/conditions-group", [
    "views/fields/base",
    "feature-journey:helpers/custom-fields",
    "feature-journey:helpers/transition-rules",
], function (Dep, CustomFieldsHelper, TransitionRules) {
    /**
     * Unified “when to advance” rules builder.
     * User edits one AND/OR tree; engine wakes/eventCodes/waitPeriod are derived on fetch.
     */
    return Dep.extend({
        type: "jsonObject",

        operators: [
            "equals",
            "notEquals",
            "contains",
            "notContains",
            "exists",
            "notExists",
            "in",
            "greaterThan",
            "lessThan",
            "greaterThanOrEquals",
            "lessThanOrEquals",
        ],

        getOperatorOptions: function () {
            return this.operators.map((operator) => {
                return {
                    value: operator,
                    label:
                        this.translate(
                            operator,
                            "conditionOperators",
                            "JourneyTransition"
                        ) || operator,
                };
            });
        },

        editTemplateContent:
            '<div class="journey-conditions-builder">' +
                '<p class="text-muted small" style="margin-top:0;margin-bottom:10px">' +
                    '{{translate "conditionsBuilderHint" category="messages" scope="JourneyTransition"}}' +
                '</p>' +
                '<div class="conditions-toolbar" style="margin-bottom:10px">' +
                    '<div class="btn-group">' +
                        '<button type="button" class="btn btn-default btn-sm" data-action="setRootOp" data-op="and">' +
                            '{{translate "matchAll" category="labels" scope="JourneyTransition"}}</button>' +
                        '<button type="button" class="btn btn-default btn-sm" data-action="setRootOp" data-op="or">' +
                            '{{translate "matchAny" category="labels" scope="JourneyTransition"}}</button>' +
                    '</div>' +
                    '<div class="btn-group" style="margin-left:8px">' +
                        '<button type="button" class="btn btn-primary btn-sm dropdown-toggle" data-toggle="dropdown">' +
                            '<span class="fas fa-plus"></span> ' +
                            '{{translate "addCondition" category="labels" scope="JourneyTransition"}}' +
                            ' <span class="caret"></span>' +
                        '</button>' +
                        '<ul class="dropdown-menu">' +
                            '<li><a role="button" tabindex="0" data-action="addLeaf" data-leaf="incomingEvent">' +
                                '{{translate "incomingEvent" category="conditionTypes" scope="JourneyTransition"}}</a></li>' +
                            '<li><a role="button" tabindex="0" data-action="addLeaf" data-leaf="eventHistory">' +
                                '{{translate "eventHistory" category="conditionTypes" scope="JourneyTransition"}}</a></li>' +
                            '<li><a role="button" tabindex="0" data-action="addLeaf" data-leaf="elapsedInStage">' +
                                '{{translate "elapsedInStage" category="conditionTypes" scope="JourneyTransition"}}</a></li>' +
                            '<li><a role="button" tabindex="0" data-action="addLeaf" data-leaf="entityFilter">' +
                                '{{translate "entityFilter" category="conditionTypes" scope="JourneyTransition"}}</a></li>' +
                        '</ul>' +
                    '</div>' +
                    '<button type="button" class="btn btn-link btn-sm" data-action="toggleAdvanced" style="margin-left:8px">' +
                        '{{translate "advancedJson" category="labels" scope="JourneyStageAction"}}' +
                    '</button>' +
                '</div>' +
                '<div class="conditions-extra" style="margin-bottom:12px">' +
                    '<label class="checkbox-inline" style="margin-right:16px">' +
                        '<input type="checkbox" class="opt-allow-entity-change"> ' +
                        '{{translate "allowEntityChange" category="labels" scope="JourneyTransition"}}' +
                    '</label>' +
                    '<label class="checkbox-inline">' +
                        '<input type="checkbox" class="opt-allow-manual"> ' +
                        '{{translate "allowManual" category="labels" scope="JourneyTransition"}}' +
                    '</label>' +
                '</div>' +
                '<div class="conditions-tree"></div>' +
                '<div class="conditions-raw-wrap hidden margin-top-sm">' +
                    '<textarea class="form-control conditions-raw" rows="8"></textarea>' +
                '</div>' +
            '</div>',

        detailTemplateContent:
            '{{#if isNotEmpty}}' +
            '<div class="journey-conditions-detail">{{{treeHtml}}}</div>' +
            '{{else}}' +
            '<span class="none-value">{{translate "conditionsEmptyDetail" category="messages" scope="JourneyTransition"}}</span>' +
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
            this.rulesHelper = TransitionRules;

            const decoded = this.rulesHelper.decompile({
                conditionsGroup: this.model.get(this.name),
                eventCodes: this.model.get("eventCodes"),
                waitPeriod: this.model.get("waitPeriod"),
                wakeSources: this.model.get("wakeSources"),
                triggerType: this.model.get("triggerType"),
            });

            this._tree = this.prepareVisualTree(decoded.tree);
            this._allowManual = !!decoded.allowManual;
            this._allowEntityChange = !!decoded.allowEntityChange;
            this._advancedOpen = false;
            this._rawDirty = false;
            this._attrOptions = [];
            this._context = { tenantId: null, entityType: null };

            this.listenTo(this.model, "sync", () => {
                const d = this.rulesHelper.decompile({
                    conditionsGroup: this.model.get(this.name),
                    eventCodes: this.model.get("eventCodes"),
                    waitPeriod: this.model.get("waitPeriod"),
                    wakeSources: this.model.get("wakeSources"),
                    triggerType: this.model.get("triggerType"),
                });
                this._tree = this.prepareVisualTree(d.tree);
                this._allowManual = !!d.allowManual;
                this._allowEntityChange = !!d.allowEntityChange;

                if (this.isRendered()) {
                    this.reRender();
                }
            });
        },

        data: function () {
            const tree = this._tree || this.normalizeTree(this.model.get(this.name));
            return {
                ...Dep.prototype.data.call(this),
                isNotEmpty: !this.isEmptyTree(tree),
                treeHtml: this.renderTreeHtml(tree),
                summary: this.summarize(tree),
            };
        },

        isEmptyTree: function (tree) {
            if (!tree || typeof tree !== "object") {
                return true;
            }
            if (tree.and && Array.isArray(tree.and)) {
                return tree.and.length === 0;
            }
            if (tree.or && Array.isArray(tree.or)) {
                return tree.or.length === 0;
            }
            if (tree.not) {
                return false;
            }
            if (tree.type) {
                return false;
            }
            return Object.keys(tree).length === 0;
        },

        normalizeTree: function (value) {
            if (!value) {
                return { and: [] };
            }

            let v = value;
            if (typeof v === "string") {
                try {
                    v = JSON.parse(v);
                } catch (e) {
                    return { and: [] };
                }
            }

            if (typeof v !== "object" || v === null) {
                return { and: [] };
            }

            // Espo sometimes gives stdClass-like plain objects.
            if (v.and && Array.isArray(v.and)) {
                return { and: v.and.slice() };
            }
            if (v.or && Array.isArray(v.or)) {
                return { or: v.or.slice() };
            }
            if (v.not && typeof v.not === "object") {
                return { not: v.not };
            }

            // Tutorial legacy: { type: 'and', children: [...] }
            if (v.type === "and" && Array.isArray(v.children)) {
                return { and: v.children.map((c) => this.normalizeLeaf(c)) };
            }
            if (v.type === "or" && Array.isArray(v.children)) {
                return { or: v.children.map((c) => this.normalizeLeaf(c)) };
            }

            // Bare leaf
            if (v.type) {
                return { and: [this.normalizeLeaf(v)] };
            }

            return { and: [] };
        },

        normalizeLeaf: function (node) {
            if (!node || typeof node !== "object") {
                return {
                    type: "payloadPath",
                    path: "",
                    operator: "equals",
                    value: "",
                };
            }
            // legacy children node already typed
            if (node.type === "and" || node.type === "or") {
                return node;
            }
            return Object.assign({}, node);
        },

        /**
         * Current-event payload checks belong to the event that supplies their payload.
         * Grouping adjacent leaves under a root AND is semantics-preserving. Under OR,
         * standalone payload leaves are intentionally kept because nesting would change it.
         */
        prepareVisualTree: function (value) {
            const tree = this.normalizeTree(value);
            if (tree.not) {
                return tree;
            }
            const op = tree.or ? "or" : "and";
            const children = tree[op] || [];
            const grouped = [];

            for (let i = 0; i < children.length; i++) {
                const node = children[i];

                if (
                    node &&
                    (node.type === "currentSignal" || node.type === "anySignal")
                ) {
                    const eventChildren = [node];

                    if (op === "and") {
                        while (
                            children[i + 1] &&
                            children[i + 1].type === "payloadPath"
                        ) {
                            eventChildren.push(children[++i]);
                        }
                    }

                    grouped.push({and: eventChildren});
                    continue;
                }

                if (op === "and" && this.isIncomingEventGroup(node)) {
                    const eventChildren = node.and.slice();
                    while (
                        children[i + 1] &&
                        children[i + 1].type === "payloadPath"
                    ) {
                        eventChildren.push(children[++i]);
                    }
                    grouped.push({and: eventChildren});
                    continue;
                }

                grouped.push(node);
            }

            return op === "or" ? {or: grouped} : {and: grouped};
        },

        isIncomingEventGroup: function (node) {
            if (!node || !Array.isArray(node.and) || !node.and.length) {
                return false;
            }

            const source = node.and[0];
            if (
                !source ||
                (source.type !== "currentSignal" && source.type !== "anySignal")
            ) {
                return false;
            }

            return node.and.slice(1).every((child) => {
                return child && child.type === "payloadPath";
            });
        },

        getRootOp: function () {
            if (this._tree && this._tree.or) {
                return "or";
            }
            return "and";
        },

        getRootChildren: function () {
            const op = this.getRootOp();
            return (this._tree && this._tree[op]) || [];
        },

        afterRender: function () {
            if (!this.isEditMode()) {
                return;
            }

            // Keep in-memory tree (decompiled / edited); do not clobber from raw model.
            this._tree = this.normalizeTree(this._tree || this.model.get(this.name));
            this.$tree = this.$el.find(".conditions-tree");
            this.$raw = this.$el.find(".conditions-raw");
            this.$rawWrap = this.$el.find(".conditions-raw-wrap");

            this.$el.find(".opt-allow-manual").prop("checked", !!this._allowManual);
            this.$el
                .find(".opt-allow-entity-change")
                .prop("checked", !!this._allowEntityChange);

            this.$el.find(".opt-allow-manual").on("change", (e) => {
                this._allowManual = !!$(e.currentTarget).prop("checked");
                this.trigger("change");
            });

            this.$el.find(".opt-allow-entity-change").on("change", (e) => {
                this._allowEntityChange = !!$(e.currentTarget).prop("checked");
                this.trigger("change");
            });

            this.$el.find('[data-action="setRootOp"]').on("click", (e) => {
                const op = $(e.currentTarget).data("op");
                const children = this.getRootChildren();
                this._tree = this.prepareVisualTree(
                    op === "or" ? {or: children} : {and: children}
                );
                this._rawDirty = false;
                this.renderTree();
                this.trigger("change");
            });

            this.$el.find('[data-action="addLeaf"]').on("click", (e) => {
                e.preventDefault();
                const leafType =
                    $(e.currentTarget).data("leaf") || "incomingEvent";
                const children = this.getRootChildren();
                children.push(this.createLeaf(leafType));
                const op = this.getRootOp();
                this._tree[op] = children;
                this._rawDirty = false;
                this.renderTree();
                this.trigger("change");
            });

            this.$el.find('[data-action="toggleAdvanced"]').on("click", () => {
                this._advancedOpen = !this._advancedOpen;
                this.$rawWrap.toggleClass("hidden", !this._advancedOpen);
                if (this._advancedOpen) {
                    this.$raw.val(JSON.stringify(this._tree, null, 2));
                }
            });

            this.$raw.on("change input", () => {
                this._rawDirty = true;
                try {
                    const parsed = JSON.parse(String(this.$raw.val() || "{}"));
                    this._tree = this.prepareVisualTree(parsed);
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
                this.renderTree();
            });

            this.renderTree();
            this.highlightRootOp();
        },

        createLeaf: function (type) {
            if (type === "incomingEvent") {
                return {and: [this.createLeaf("currentSignal")]};
            }

            if (type === "currentSignal") {
                return {type: "currentSignal", code: ""};
            }

            if (type === "anySignal") {
                return {type: "anySignal", codes: []};
            }

            if (type === "elapsedInStage") {
                return {
                    type: "elapsedInStage",
                    period: this.model.get("waitPeriod") || "3 days",
                };
            }

            if (type === "entityFilter") {
                return {type: "entityFilter", where: []};
            }

            if (type === "payloadPath") {
                return {
                    type: "payloadPath",
                    path: "",
                    operator: "equals",
                    value: "",
                };
            }

            // default: past event (stateful)
            return {
                type: "eventHistory",
                code: "",
                minCount: 1,
                window: "",
            };
        },

        highlightRootOp: function () {
            const op = this.getRootOp();
            this.$el.find('[data-action="setRootOp"]').removeClass("active btn-primary");
            this.$el
                .find('[data-action="setRootOp"][data-op="' + op + '"]')
                .addClass("active btn-primary");
        },

        renderTree: function () {
            if (!this.$tree) {
                return;
            }
            this.$tree.empty();
            this.highlightRootOp();

            const children = this.getRootChildren();
            if (!children.length) {
                this.$tree.append(
                    $("<div>")
                        .addClass("text-muted")
                        .text(
                            this.translate(
                                "conditionsEmpty",
                                "messages",
                                "JourneyTransition"
                            )
                        )
                );
                return;
            }

            if (
                this.getRootOp() === "and" &&
                children.filter((node) => this.isIncomingEventGroup(node)).length > 1
            ) {
                this.$tree.append(
                    $("<div>")
                        .addClass("alert alert-warning small")
                        .css({padding: "8px 10px", marginBottom: "10px"})
                        .text(
                            this.translate(
                                "multipleIncomingAndWarning",
                                "messages",
                                "JourneyTransition"
                            )
                        )
                );
            }

            children.forEach((node, index) => {
                if (this.isIncomingEventGroup(node)) {
                    this.$tree.append(this.buildIncomingEventEditor(node, index));
                } else if (node && (Array.isArray(node.and) || Array.isArray(node.or))) {
                    this.$tree.append(this.buildAdvancedGroupEditor(node, index));
                } else {
                    this.$tree.append(this.buildLeafEditor(node, index));
                }
            });
        },

        removeRootNode: function (index) {
            const children = this.getRootChildren();
            children.splice(index, 1);
            this._tree[this.getRootOp()] = children;
            this.renderTree();
            this.onTreeChanged();
        },

        buildIncomingEventEditor: function (node, index) {
            const $card = $("<div>")
                .addClass("panel panel-info condition-event-group")
                .css({marginBottom: "10px"});
            const $heading = $("<div>").addClass("panel-heading");
            const $remove = $("<button>")
                .attr("type", "button")
                .attr("title", this.translate("remove", "labels", "Global"))
                .addClass("btn btn-link btn-xs pull-right")
                .css({color: "inherit", padding: "0 4px"})
                .html('<span class="fas fa-times"></span>');
            $heading
                .append($remove)
                .append(
                    $("<strong>").text(
                        this.translate(
                            "incomingEventGroup",
                            "labels",
                            "JourneyTransition"
                        )
                    )
                );
            $card.append($heading);

            const $body = $("<div>").addClass("panel-body").css({padding: "10px"});
            const $source = $("<div>").addClass("row");
            const sourceNode = node.and[0];
            const $modeCol = $("<div>").addClass("col-sm-3");
            const $eventCol = $("<div>").addClass("col-sm-9");
            const $mode = $("<select>").addClass("form-control input-sm");

            $mode.append(
                $("<option>")
                    .val("currentSignal")
                    .text(
                        this.translate(
                            "oneIncomingEvent",
                            "labels",
                            "JourneyTransition"
                        )
                    )
            );
            $mode.append(
                $("<option>")
                    .val("anySignal")
                    .text(
                        this.translate(
                            "anyIncomingEvent",
                            "labels",
                            "JourneyTransition"
                        )
                    )
            );
            $mode.val(sourceNode.type);
            $modeCol
                .append(
                    $("<label>")
                        .addClass("control-label small")
                        .text(
                            this.translate(
                                "eventMatchMode",
                                "labels",
                                "JourneyTransition"
                            )
                        )
                )
                .append($mode);

            if (sourceNode.type === "anySignal") {
                this.appendAnySignalFields($eventCol, sourceNode, () => {
                    this.renderTree();
                });
            } else {
                const codeOptions = this.getEventCodeSelectOptions(sourceNode.code);
                if (!sourceNode.code && codeOptions[0]) {
                    sourceNode.code = codeOptions[0].value;
                }
                $eventCol.append(
                    this.fieldSelect(
                        "code",
                        this.translate("eventCode", "labels", "JourneyTransition"),
                        codeOptions,
                        sourceNode.code || "",
                        (value) => {
                            sourceNode.code = value;
                            this.renderTree();
                        }
                    )
                );
            }
            $source.append($modeCol).append($eventCol);
            $body.append($source);

            const $filters = $("<div>")
                .addClass("event-payload-filters")
                .css({borderTop: "1px solid #ddd", marginTop: "8px", paddingTop: "8px"});
            const $filterTitle = $("<div>").css({marginBottom: "6px"});
            $filterTitle.append(
                $("<strong>")
                    .addClass("small")
                    .text(
                        this.translate(
                            "eventDataFilters",
                            "labels",
                            "JourneyTransition"
                        )
                    )
            );
            $filters.append($filterTitle);

            const sourceCodes = sourceNode.type === "anySignal"
                ? (sourceNode.codes || [])
                : [sourceNode.code].filter(Boolean);
            const payloadNodes = node.and.slice(1);

            if (!payloadNodes.length) {
                $filters.append(
                    $("<p>")
                        .addClass("text-muted small")
                        .css({margin: "0 0 6px"})
                        .text(
                            this.translate(
                                "eventDataFiltersEmpty",
                                "messages",
                                "JourneyTransition"
                            )
                        )
                );
            }

            payloadNodes.forEach((payloadNode, payloadIndex) => {
                const $filter = $("<div>")
                    .addClass("well well-sm")
                    .css({marginBottom: "6px", position: "relative", paddingRight: "38px"});
                const $filterFields = $("<div>");
                const $removeFilter = $("<button>")
                    .attr("type", "button")
                    .addClass("btn btn-default btn-xs")
                    .css({position: "absolute", right: "8px", top: "8px"})
                    .html('<span class="fas fa-times"></span>');

                this.appendPayloadPathFields(
                    $filterFields,
                    payloadNode,
                    sourceCodes
                );
                $removeFilter.on("click", () => {
                    node.and.splice(payloadIndex + 1, 1);
                    this.renderTree();
                    this.onTreeChanged();
                });
                $filter.append($filterFields).append($removeFilter);
                $filters.append($filter);
            });

            const $addFilter = $("<button>")
                .attr("type", "button")
                .addClass("btn btn-default btn-xs")
                .html(
                    '<span class="fas fa-plus"></span> ' +
                    this.getHelper().escapeString(
                        this.translate(
                            "addEventDataFilter",
                            "labels",
                            "JourneyTransition"
                        )
                    )
                );
            $addFilter.on("click", () => {
                node.and.push(this.createLeaf("payloadPath"));
                this.renderTree();
                this.onTreeChanged();
            });
            $filters.append($addFilter);
            $body.append($filters);
            $body.append(
                $("<p>")
                    .addClass("text-muted small")
                    .css({margin: "8px 0 0"})
                    .text(
                        this.translate(
                            "incomingEventGroupHint",
                            "messages",
                            "JourneyTransition"
                        )
                    )
            );

            $mode.on("change", () => {
                node.and[0] = this.createLeaf($mode.val());
                this.renderTree();
                this.onTreeChanged();
            });
            $remove.on("click", () => this.removeRootNode(index));
            $card.append($body);
            return $card;
        },

        buildAdvancedGroupEditor: function (node, index) {
            const $card = $("<div>")
                .addClass("panel panel-default")
                .css({marginBottom: "8px"});
            const $body = $("<div>").addClass("panel-body").css({padding: "10px"});
            const $remove = $("<button>")
                .attr("type", "button")
                .addClass("btn btn-default btn-xs pull-right")
                .html('<span class="fas fa-times"></span>');
            $body
                .append($remove)
                .append(
                    $("<strong>").text(
                        this.translate(
                            "advancedNestedGroup",
                            "labels",
                            "JourneyTransition"
                        )
                    )
                )
                .append(
                    $("<p>")
                        .addClass("text-muted small")
                        .css({margin: "4px 0 0"})
                        .text(
                            this.translate(
                                "advancedNestedGroupHint",
                                "messages",
                                "JourneyTransition"
                            )
                        )
                );
            $remove.on("click", () => this.removeRootNode(index));
            $card.append($body);
            return $card;
        },

        buildLeafEditor: function (node, index) {
            const type = node.type || "payloadPath";
            const $card = $("<div>")
                .addClass("panel panel-default condition-leaf")
                .css({ marginBottom: "8px" })
                .attr("data-index", index);

            const $body = $("<div>").addClass("panel-body").css({ padding: "10px" });
            const $row1 = $("<div>").addClass("row");

            // type
            const $typeCol = $("<div>").addClass("col-sm-3");
            $typeCol.append(
                $("<label>")
                    .addClass("control-label small")
                    .text(this.translate("conditionType", "labels", "JourneyTransition"))
            );
            const $type = $("<select>").addClass("form-control input-sm cond-type");
            const typeOptions = ["eventHistory", "elapsedInStage", "entityFilter"];
            if (type === "payloadPath") {
                typeOptions.push("payloadPath");
            }
            typeOptions.forEach((t) => {
                const $o = $("<option>")
                    .val(t)
                    .text(
                        this.translate(t, "conditionTypes", "JourneyTransition") || t
                    );
                if (t === type) {
                    $o.prop("selected", true);
                }
                $type.append($o);
            });
            $typeCol.append($type);

            // remove
            const $rmCol = $("<div>")
                .addClass("col-sm-1")
                .css({ textAlign: "right", paddingTop: "22px" });
            const $rm = $("<button>")
                .attr("type", "button")
                .addClass("btn btn-default btn-sm")
                .html('<span class="fas fa-times"></span>');
            $rmCol.append($rm);

            $row1.append($typeCol);

            const $fieldsCol = $("<div>").addClass("col-sm-8 cond-fields");
            $row1.append($fieldsCol);
            $row1.append($rmCol);
            $body.append($row1);
            $card.append($body);

            const renderFields = () => {
                $fieldsCol.empty();
                const t = $type.val();
                node.type = t;

                if (t === "payloadPath") {
                    if (this.getRootOp() === "or") {
                        $fieldsCol.append(
                            $("<p>")
                                .addClass("text-warning small")
                                .css({margin: "0 0 6px"})
                                .text(
                                    this.translate(
                                        "ungroupedPayloadWarning",
                                        "messages",
                                        "JourneyTransition"
                                    )
                                )
                        );
                    }
                    this.appendPayloadPathFields($fieldsCol, node);
                } else if (t === "eventHistory") {
                    this.appendEventHistoryFields($fieldsCol, node);
                } else if (t === "elapsedInStage") {
                    this.appendElapsedInStageFields($fieldsCol, node);
                } else if (t === "currentSignal") {
                    this.appendCurrentSignalFields($fieldsCol, node);
                } else if (t === "anySignal") {
                    this.appendAnySignalFields($fieldsCol, node);
                } else if (t === "entityFilter") {
                    $fieldsCol.append(this.buildEntityFilterEditor(node));
                }
            };

            $type.on("change", () => {
                // reset leaf keys for new type
                const t = $type.val();
                Object.keys(node).forEach((k) => {
                    if (k !== "type") {
                        delete node[k];
                    }
                });

                Object.assign(node, this.createLeaf(t));
                node.type = t;
                renderFields();
                this.onTreeChanged();
            });

            $rm.on("click", () => {
                this.removeRootNode(index);
            });

            renderFields();
            return $card;
        },

        buildEntityFilterEditor: function (node) {
            if (!Array.isArray(node.where)) {
                if (node.where && typeof node.where === "object") {
                    node.where = [node.where];
                } else {
                    node.where = [];
                }
            }

            const $wrap = $("<div>").addClass("entity-filter-editor");
            $wrap.append(
                $("<label>")
                    .addClass("control-label small")
                    .text(
                        this.translate(
                            "entityFilterWhere",
                            "labels",
                            "JourneyTransition"
                        )
                    )
            );
            const $rows = $("<div>").addClass("entity-filter-rows");
            $wrap.append($rows);

            const $add = $("<button>")
                .attr("type", "button")
                .addClass("btn btn-default btn-xs")
                .css({ marginTop: "4px" })
                .html('<span class="fas fa-plus"></span>');
            $wrap.append($add);

            $wrap.append(
                $("<p>")
                    .addClass("text-muted small")
                    .css({ margin: "4px 0 0" })
                    .text(
                        this.translate(
                            "entityFilterHint",
                            "messages",
                            "JourneyTransition"
                        )
                    )
            );

            const ops = this.cfHelper.whereOperators();

            const renderWhereRows = () => {
                $rows.empty();

                if (!node.where.length) {
                    node.where.push({ type: "equals", attribute: "", value: "" });
                }

                node.where.forEach((item, idx) => {
                    const $row = $("<div>")
                        .addClass("row")
                        .css({ marginBottom: "4px" });
                    const $c1 = $("<div>").addClass("col-sm-5");
                    const $c2 = $("<div>").addClass("col-sm-3");
                    const $c3 = $("<div>").addClass("col-sm-3");
                    const $c4 = $("<div>").addClass("col-sm-1");

                    const $attr = $("<select>").addClass("form-control input-sm");
                    $attr.append($("<option>").val("").text("—"));
                    (this._attrOptions || []).forEach((opt) => {
                        const $o = $("<option>").val(opt.value).text(opt.label);

                        if (opt.value === item.attribute) {
                            $o.prop("selected", true);
                        }

                        $attr.append($o);
                    });

                    if (
                        item.attribute &&
                        !(this._attrOptions || []).some((o) => o.value === item.attribute)
                    ) {
                        $attr.append(
                            $("<option>")
                                .val(item.attribute)
                                .text(item.attribute + " *")
                                .prop("selected", true)
                        );
                    }

                    const $op = $("<select>").addClass("form-control input-sm");
                    ops.forEach((op) => {
                        const $o = $("<option>").val(op).text(op);

                        if (op === (item.type || "equals")) {
                            $o.prop("selected", true);
                        }

                        $op.append($o);
                    });

                    const needsVal = this.cfHelper.operatorNeedsValue(
                        item.type || "equals"
                    );
                    let displayVal = "";

                    if (Array.isArray(item.value)) {
                        displayVal = item.value.join(", ");
                    } else if (item.value !== undefined && item.value !== null) {
                        displayVal = String(item.value);
                    }

                    const $val = $("<input>")
                        .attr("type", "text")
                        .addClass("form-control input-sm")
                        .prop("disabled", !needsVal)
                        .val(displayVal);

                    const $rm = $("<button>")
                        .attr("type", "button")
                        .addClass("btn btn-default btn-xs")
                        .html('<span class="fas fa-times"></span>');

                    const sync = () => {
                        item.attribute = $attr.val() || "";
                        item.type = $op.val() || "equals";

                        if (!this.cfHelper.operatorNeedsValue(item.type)) {
                            delete item.value;
                            $val.prop("disabled", true).val("");
                        } else {
                            $val.prop("disabled", false);
                            item.value = this.cfHelper.coerceValue(
                                $val.val(),
                                item.type
                            );
                        }

                        this.onTreeChanged();
                    };

                    $attr.on("change", sync);
                    $op.on("change", sync);
                    $val.on("change input", sync);
                    $rm.on("click", () => {
                        node.where.splice(idx, 1);
                        renderWhereRows();
                        this.onTreeChanged();
                    });

                    $c1.append($attr);
                    $c2.append($op);
                    $c3.append($val);
                    $c4.append($rm);
                    $row.append($c1).append($c2).append($c3).append($c4);
                    $rows.append($row);
                });
            };

            $add.on("click", () => {
                node.where.push({ type: "equals", attribute: "", value: "" });
                renderWhereRows();
                this.onTreeChanged();
            });

            renderWhereRows();

            return $wrap;
        },

        /**
         * Codes from rules tree (for payload catalog), not legacy eventCodes field.
         * @return {string[]}
         */
        getSelectedEventCodes: function () {
            const codes = [];
            const helper = this.rulesHelper || TransitionRules;

            helper.walkLeaves(this._tree || {and: []}, (node) => {
                if (typeof node.code === "string" && node.code) {
                    if (codes.indexOf(node.code) === -1) {
                        codes.push(node.code);
                    }
                }

                if (Array.isArray(node.codes)) {
                    node.codes.forEach((c) => {
                        if (typeof c === "string" && c && codes.indexOf(c) === -1) {
                            codes.push(c);
                        }
                    });
                }
            });

            if (codes.length) {
                return codes;
            }

            const raw = this.model.get("eventCodes");

            if (Array.isArray(raw)) {
                return raw.filter((c) => typeof c === "string" && c !== "");
            }

            if (typeof raw === "string" && raw !== "") {
                return [raw];
            }

            return [];
        },

        getSelectedEventCode: function () {
            const codes = this.getSelectedEventCodes();

            return codes.length ? codes[0] : null;
        },

        normalizePayloadPath: function (path) {
            if (!path || typeof path !== "string") {
                return "";
            }

            return path
                .replace(/^event\.payload\.?/, "")
                .replace(/^payload\./, "");
        },

        /**
         * @return {{value: string, label: string}[]}
         */
        getPayloadPathOptions: function (selectedPath, eventCodes) {
            const codes = Array.isArray(eventCodes)
                ? eventCodes
                : this.getSelectedEventCodes();
            const normalizedSelected = this.normalizePayloadPath(selectedPath);
            const options = [];
            const seen = {};

            codes.forEach((code) => {
                const fields =
                    this.getMetadata().get([
                        "app",
                        "journeySignalEventCodes",
                        "payloadFields",
                        code,
                    ]) || [];

                fields.forEach((path) => {
                    if (!path || typeof path !== "string" || seen[path]) {
                        return;
                    }

                    seen[path] = true;

                    const label =
                        this.translate(
                            path,
                            "payloadFields",
                            "JourneyTransition"
                        ) || path;

                    options.push({
                        value: path,
                        label: label !== path ? label : path,
                    });
                });
            });

            if (
                normalizedSelected &&
                !options.some((o) => o.value === normalizedSelected)
            ) {
                options.unshift({
                    value: normalizedSelected,
                    label:
                        normalizedSelected +
                        " — " +
                        this.translate(
                            "payloadPathUnknownKept",
                            "messages",
                            "JourneyTransition"
                        ),
                });
            }

            return options;
        },

        /**
         * @return {{value: string, label: string}[]}
         */
        getEventCodeSelectOptions: function (selectedCode) {
            const codes =
                this.getMetadata().get([
                    "app",
                    "journeySignalEventCodes",
                    "codeList",
                ]) || [];
            const options = codes.map((code) => {
                const label = this.getLanguage().translateOption(
                    code,
                    "eventCodes",
                    "JourneyTransition"
                );

                return {
                    value: code,
                    label: label && label !== code ? label : code,
                };
            });

            if (selectedCode && !options.some((o) => o.value === selectedCode)) {
                options.unshift({
                    value: selectedCode,
                    label: selectedCode,
                });
            }

            return options;
        },

        appendElapsedInStageFields: function ($fieldsCol, node) {
            if (!node.period) {
                node.period = this.model.get("waitPeriod") || "1 day";
            }

            $fieldsCol.append(
                this.fieldInput(
                    "period",
                    this.translate("elapsedPeriod", "labels", "JourneyTransition"),
                    node.period || "",
                    (v) => {
                        node.period = v;
                    },
                    "3 days"
                )
            );
            $fieldsCol.append(
                $("<p>")
                    .addClass("text-muted small")
                    .css({ margin: "4px 0 0" })
                    .text(
                        this.translate(
                            "elapsedInStageHint",
                            "messages",
                            "JourneyTransition"
                        )
                    )
            );
        },

        appendCurrentSignalFields: function ($fieldsCol, node) {
            const codeOptions = this.getEventCodeSelectOptions(node.code);

            if (!node.code && codeOptions[0]) {
                node.code = codeOptions[0].value;
            }

            $fieldsCol.append(
                this.fieldSelect(
                    "code",
                    this.translate("eventCode", "labels", "JourneyTransition"),
                    codeOptions,
                    node.code || "",
                    (v) => {
                        node.code = v;
                    }
                )
            );
            $fieldsCol.append(
                $("<p>")
                    .addClass("text-muted small")
                    .css({ margin: "4px 0 0" })
                    .text(
                        this.translate(
                            "currentSignalHint",
                            "messages",
                            "JourneyTransition"
                        )
                    )
            );
        },

        appendAnySignalFields: function ($fieldsCol, node, onCodesChanged) {
            if (!Array.isArray(node.codes)) {
                node.codes = node.code ? [node.code] : [];
            }

            const codeOptions = this.getEventCodeSelectOptions(
                node.codes[0] || null
            );
            const selected = node.codes.slice();

            const $g = $("<div>")
                .addClass("form-group")
                .css({marginBottom: "6px"});
            $g.append(
                $("<label>")
                    .addClass("control-label small")
                    .text(
                        this.translate(
                            "eventCodesMulti",
                            "labels",
                            "JourneyTransition"
                        )
                    )
            );

            const $sel = $("<select>")
                .addClass("form-control input-sm")
                .attr("multiple", "multiple")
                .css({minHeight: "88px"});

            codeOptions.forEach((opt) => {
                const $o = $("<option>")
                    .val(opt.value)
                    .text(opt.label);
                if (selected.indexOf(opt.value) !== -1) {
                    $o.prop("selected", true);
                }
                $sel.append($o);
            });

            $sel.on("change", () => {
                const vals = $sel.val() || [];
                node.codes = Array.isArray(vals) ? vals : [vals];
                if (typeof onCodesChanged === "function") {
                    onCodesChanged();
                }
                this.onTreeChanged();
            });

            $g.append($sel);
            $fieldsCol.append($g);
            $fieldsCol.append(
                $("<p>")
                    .addClass("text-muted small")
                    .css({margin: "4px 0 0"})
                    .text(
                        this.translate(
                            "anySignalHint",
                            "messages",
                            "JourneyTransition"
                        )
                    )
            );
        },

        appendPayloadPathFields: function ($fieldsCol, node, eventCodes) {
            const codes = Array.isArray(eventCodes)
                ? eventCodes
                : this.getSelectedEventCodes();
            const pathOptions = this.getPayloadPathOptions(node.path, codes);

            if (!codes.length) {
                $fieldsCol.append(
                    $("<p>")
                        .addClass("text-warning small")
                        .css({ margin: "4px 0 0" })
                        .text(
                            this.translate(
                                "payloadPathNeedEvent",
                                "messages",
                                "JourneyTransition"
                            )
                        )
                );

                return;
            }

            if (!pathOptions.length) {
                $fieldsCol.append(
                    $("<p>")
                        .addClass("text-muted small")
                        .css({ margin: "4px 0 0" })
                        .text(
                            this.translate(
                                "payloadPathEmptyCatalog",
                                "messages",
                                "JourneyTransition"
                            )
                        )
                );

                return;
            }

            const current = this.normalizePayloadPath(node.path);

            if (current && current !== node.path) {
                node.path = current;
            }

            if (!node.path && pathOptions[0]) {
                node.path = pathOptions[0].value;
            }

            $fieldsCol.append(
                this.fieldSelect(
                    "path",
                    this.translate("payloadPath", "labels", "JourneyTransition"),
                    pathOptions,
                    node.path || "",
                    (v) => {
                        node.path = v;
                    }
                )
            );
            $fieldsCol.append(
                this.fieldSelect(
                    "operator",
                    this.translate("operator", "labels", "JourneyTransition"),
                    this.getOperatorOptions(),
                    node.operator || "equals",
                    (v) => {
                        node.operator = v;
                    }
                )
            );
            $fieldsCol.append(
                this.fieldInput(
                    "value",
                    this.translate("expectedValue", "labels", "JourneyTransition"),
                    node.value !== undefined && node.value !== null
                        ? String(node.value)
                        : "",
                    (v) => {
                        node.value = v;
                    }
                )
            );
        },

        appendEventHistoryFields: function ($fieldsCol, node) {
            const codeOptions = this.getEventCodeSelectOptions(node.code);

            if (!node.code && codeOptions[0]) {
                node.code = codeOptions[0].value;
            }

            $fieldsCol.append(
                this.fieldSelect(
                    "code",
                    this.translate("eventCode", "labels", "JourneyTransition"),
                    codeOptions,
                    node.code || "",
                    (v) => {
                        node.code = v;
                    }
                )
            );
            $fieldsCol.append(
                this.fieldInput(
                    "minCount",
                    this.translate("minCount", "labels", "JourneyTransition"),
                    node.minCount !== undefined ? String(node.minCount) : "1",
                    (v) => {
                        node.minCount = parseInt(v, 10) || 1;
                    }
                )
            );
            $fieldsCol.append(
                this.fieldInput(
                    "window",
                    this.translate("window", "labels", "JourneyTransition"),
                    node.window || "",
                    (v) => {
                        node.window = v;
                    },
                    "7 days"
                )
            );
        },

        fieldInput: function (name, label, value, onSet, placeholder) {
            const $g = $("<div>")
                .addClass("form-group")
                .css({ marginBottom: "6px" });
            $g.append(
                $("<label>").addClass("control-label small").text(label)
            );
            const $input = $("<input>")
                .attr("type", "text")
                .addClass("form-control input-sm")
                .attr("placeholder", placeholder || "")
                .val(value);
            $input.on("change input", () => {
                onSet($input.val());
                this.onTreeChanged();
            });
            $g.append($input);
            return $g;
        },

        /**
         * @param {string} name
         * @param {string} label
         * @param {Array<string|{value:string,label:string}>} options
         * @param {string} value
         * @param {function(string):void} onSet
         */
        fieldSelect: function (name, label, options, value, onSet) {
            const $g = $("<div>")
                .addClass("form-group")
                .css({ marginBottom: "6px" });
            $g.append(
                $("<label>").addClass("control-label small").text(label)
            );
            const $sel = $("<select>").addClass("form-control input-sm");
            const normalized = (options || []).map((opt) => {
                if (opt && typeof opt === "object") {
                    return {
                        value: String(opt.value),
                        label: String(opt.label != null ? opt.label : opt.value),
                    };
                }

                return {value: String(opt), label: String(opt)};
            });

            if (
                value &&
                !normalized.some((o) => o.value === String(value))
            ) {
                normalized.unshift({
                    value: String(value),
                    label: String(value),
                });
            }

            if (!normalized.length) {
                $sel.append($("<option>").val("").text("—"));
            }

            normalized.forEach((opt) => {
                const $o = $("<option>").val(opt.value).text(opt.label);

                if (opt.value === String(value || "")) {
                    $o.prop("selected", true);
                }

                $sel.append($o);
            });
            $sel.on("change", () => {
                onSet($sel.val());
                this.onTreeChanged();
            });
            $g.append($sel);
            return $g;
        },

        onTreeChanged: function () {
            this._rawDirty = false;
            if (this._advancedOpen && this.$raw) {
                this.$raw.val(JSON.stringify(this._tree, null, 2));
            }
            this.trigger("change");
        },

        renderTreeHtml: function (tree) {
            if (this.isEmptyTree(tree)) {
                return "";
            }
            const op = tree.or ? "OR" : "AND";
            const children = tree.and || tree.or || [];
            let html =
                '<div class="small"><strong>' +
                op +
                "</strong><ul style=\"margin:4px 0 0 16px;padding:0\">";
            children.forEach((n) => {
                html += "<li>" + this.getHelper().escapeString(this.leafLabel(n)) + "</li>";
            });
            html += "</ul></div>";
            return html;
        },

        leafLabel: function (n) {
            if (!n || typeof n !== "object") {
                return "?";
            }
            if (this.isIncomingEventGroup(n)) {
                return n.and.map((child) => this.leafLabel(child)).join(" AND ");
            }
            if (Array.isArray(n.and)) {
                return "(" + n.and.map((child) => this.leafLabel(child)).join(" AND ") + ")";
            }
            if (Array.isArray(n.or)) {
                return "(" + n.or.map((child) => this.leafLabel(child)).join(" OR ") + ")";
            }
            if (n.not) {
                return "NOT (" + this.leafLabel(n.not) + ")";
            }
            if (n.type === "payloadPath") {
                const path = this.normalizePayloadPath(n.path) || "?";
                const pathLabel =
                    this.translate(path, "payloadFields", "JourneyTransition") ||
                    path;

                return (
                    (pathLabel !== path ? pathLabel : path) +
                    " " +
                    (n.operator || "equals") +
                    " " +
                    (n.value !== undefined ? String(n.value) : "")
                );
            }
            if (n.type === "eventHistory") {
                return (
                    "event " +
                    (n.code || "?") +
                    " ×" +
                    (n.minCount || 1) +
                    (n.window ? " in " + n.window : "")
                );
            }
            if (n.type === "elapsedInStage") {
                return "in stage ≥ " + (n.period || "?");
            }
            if (n.type === "currentSignal") {
                return "this signal = " + (n.code || "?");
            }
            if (n.type === "anySignal") {
                const list = Array.isArray(n.codes) ? n.codes.join(" | ") : "";
                return "any event (" + (list || "?") + ")";
            }
            if (n.type === "entityFilter") {
                const where = Array.isArray(n.where) ? n.where : [];
                if (!where.length) {
                    return "entity filter";
                }

                return (
                    "filter: " +
                    where
                        .map((w) => (w && w.attribute) || "?")
                        .join(", ")
                );
            }
            return n.type || JSON.stringify(n);
        },

        summarize: function (tree) {
            if (this.isEmptyTree(tree)) {
                return "";
            }
            const children = tree.and || tree.or || [];
            return (tree.or ? "OR" : "AND") + " (" + children.length + ")";
        },

        fetch: function () {
            const data = {};

            if (this._rawDirty && this.$raw) {
                if (this._parseError) {
                    data[this.name] = this.model.get(this.name);

                    return data;
                }
            }

            const tree = this.cleanTree(this._tree || {and: []});
            const empty = this.isEmptyTree(tree);

            data[this.name] = empty ? null : tree;

            const compiled = (this.rulesHelper || TransitionRules).compile(tree, {
                allowManual: !!this._allowManual,
                allowEntityChange: !!this._allowEntityChange,
                existingWait: this.model.get("waitPeriod"),
            });

            data.wakeSources = compiled.wakeSources;
            data.eventCodes = compiled.eventCodes;
            data.triggerType = compiled.triggerType;

            if (compiled.waitPeriod) {
                data.waitPeriod = compiled.waitPeriod;
            } else if (compiled.wakeSources.indexOf("timer") === -1) {
                data.waitPeriod = null;
            }

            this.model.set(
                {
                    wakeSources: data.wakeSources,
                    eventCodes: data.eventCodes,
                    triggerType: data.triggerType,
                },
                {silent: true}
            );

            if (Object.prototype.hasOwnProperty.call(data, "waitPeriod")) {
                this.model.set("waitPeriod", data.waitPeriod, {silent: true});
            }

            return data;
        },

        cleanTree: function (tree) {
            if (!tree || typeof tree !== "object") {
                return { and: [] };
            }

            const cleanLeaf = (node) => {
                if (!node || typeof node !== "object") {
                    return node;
                }

                if (node.type === "entityFilter" && Array.isArray(node.where)) {
                    const where = node.where.filter(
                        (item) => item && item.attribute
                    );
                    return Object.assign({}, node, { where: where });
                }

                return node;
            };

            if (tree.and && Array.isArray(tree.and)) {
                return {
                    and: tree.and.map(cleanLeaf).filter((n) => {
                        if (n && n.type === "entityFilter") {
                            return Array.isArray(n.where) && n.where.length > 0;
                        }
                        return !!n;
                    }),
                };
            }

            if (tree.or && Array.isArray(tree.or)) {
                return {
                    or: tree.or.map(cleanLeaf).filter((n) => {
                        if (n && n.type === "entityFilter") {
                            return Array.isArray(n.where) && n.where.length > 0;
                        }
                        return !!n;
                    }),
                };
            }

            return tree;
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
