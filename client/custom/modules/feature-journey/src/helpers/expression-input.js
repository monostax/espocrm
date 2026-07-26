define("feature-journey:helpers/expression-input", [], function () {
    /**
     * Expression input inspired by n8n / Airtable / Make:
     * - fixed vs fx mode
     * - mixed plain text + green field chips → Espo formula (paramFormulas)
     * - dual-pane catalog (fields / vars / functions + docs)
     * - runtime preview line + expand editor
     */
    const ATTR_RE = /^entity\\attribute\('([^']+)'\)$/;
    const VAR_RE = /^\$([a-zA-Z_][a-zA-Z0-9_]*)$/;
    const CF_RE =
        /^object\\get\(entity\\attribute\('customFields'\),\s*'([^']+)'\)$/;

    const ExpressionInput = function (view, options) {
        this.view = view;
        this.options = options || {};
        this.paramKey = this.options.paramKey || "";
        this.multiline = !!this.options.multiline;
        this.placeholder = this.options.placeholder || "";
        this.expressionPlaceholder =
            this.options.expressionPlaceholder ||
            this.view.translate(
                "expressionPlaceholder",
                "messages",
                "JourneyStageAction"
            );
        this.snippets = this.options.snippets || [];
        this.onChange = this.options.onChange || function () {};
        this._mode =
            this.options.mode === "expression" ? "expression" : "fixed";
        this._fixedValue =
            this.options.fixedValue !== undefined &&
            this.options.fixedValue !== null
                ? String(this.options.fixedValue)
                : "";
        this._expressionValue =
            this.options.expressionValue !== undefined &&
            this.options.expressionValue !== null
                ? String(this.options.expressionValue)
                : "";
        this._rawFormula = false;
        this._uid = "jx" + Math.floor(Math.random() * 1e9);
        this._activeItem = null;
        this.$root = null;
    };

    _.extend(ExpressionInput.prototype, {
        mount: function ($host) {
            if (!$host || !$host.length) {
                return this;
            }

            const t = (k) =>
                this.view.translate(k, "labels", "JourneyStageAction");

            const $root = $("<div>")
                .addClass("journey-expression-input")
                .attr("data-param-key", this.paramKey);

            // Fixed mode input
            const $fixedRow = $("<div>").addClass(
                "journey-expression-row journey-expression-fixed-row"
            );
            const $fx = $("<button>")
                .attr({
                    type: "button",
                    title: t("expressionToggle"),
                    "aria-label": t("expressionToggle"),
                    "aria-pressed": "false",
                })
                .addClass("journey-expression-fx")
                .html("<span>fx</span>");
            const Tag = this.multiline ? "<textarea>" : "<input>";
            const $fixed = $(Tag)
                .addClass("form-control journey-expression-value")
                .attr("id", this._uid + "-fixed");

            if (!this.multiline) {
                $fixed.attr("type", "text");
            } else {
                $fixed.attr("rows", this.options.rows || 3);
            }

            $fixedRow.append($fx).append($fixed);

            // Expression mode shell
            const $exShell = $("<div>").addClass(
                "journey-expression-shell hidden"
            );
            const $exHeader = $("<div>").addClass("journey-expression-header");
            $exHeader.append(
                $("<span>")
                    .addClass("journey-expression-header-label")
                    .text(t("expressionModeLabel"))
            );
            const $fxOn = $("<button>")
                .attr({
                    type: "button",
                    title: t("expressionToggle"),
                    "aria-label": t("expressionToggle"),
                    "aria-pressed": "true",
                })
                .addClass("journey-expression-fx-pill active")
                .html("<span>fx</span>");
            const $expand = $("<button>")
                .attr({
                    type: "button",
                    title: t("expandExpression"),
                    "aria-label": t("expandExpression"),
                    "aria-pressed": "false",
                })
                .addClass("journey-expression-icon-btn")
                .html('<span class="fas fa-expand-alt"></span>');
            const $help = $("<button>")
                .attr({
                    type: "button",
                    title: t("expressionHelp"),
                    "aria-label": t("expressionHelp"),
                })
                .addClass("journey-expression-icon-btn")
                .html('<span class="fas fa-question-circle"></span>');
            $exHeader.append(
                $("<div>")
                    .addClass("journey-expression-header-actions")
                    .append($help)
                    .append($expand)
                    .append($fxOn)
            );

            const $exBody = $("<div>").addClass("journey-expression-body");
            const $editor = $("<div>")
                .addClass("journey-expression-editor")
                .attr({
                    contenteditable: "true",
                    role: "textbox",
                    "aria-label": t("expressionModeLabel"),
                    spellcheck: "false",
                    "data-placeholder": this.expressionPlaceholder,
                });
            const $raw = $("<textarea>")
                .addClass(
                    "form-control journey-expression-raw hidden"
                )
                .attr({
                    rows: this.multiline ? 5 : 3,
                    "aria-label": t("rawFormulaLabel"),
                });
            const $picker = $("<button>")
                .attr({
                    type: "button",
                    title: t("insertDynamicValue"),
                    "aria-label": t("insertDynamicValue"),
                })
                .addClass("journey-expression-picker")
                .html('<span class="fas fa-plus"></span>');

            $exBody
                .append(
                    $("<div>")
                        .addClass("journey-expression-editor-wrap")
                        .append($editor)
                        .append($raw)
                )
                .append($picker);

            const $preview = $("<div>").addClass(
                "journey-expression-preview hidden"
            );
            const $menu = $("<div>")
                .addClass("journey-expression-menu hidden")
                .attr({
                    role: "dialog",
                    "aria-label": t("expressionHelp"),
                });

            $exShell
                .append($exHeader)
                .append($exBody)
                .append($preview);
            $root
                .append($fixedRow)
                .append($exShell)
                .append($menu);

            $host.empty().append($root);

            this.$root = $root;
            this.$fixedRow = $fixedRow;
            this.$fixed = $fixed;
            this.$fx = $fx;
            this.$fxOn = $fxOn;
            this.$exShell = $exShell;
            this.$editor = $editor;
            this.$raw = $raw;
            this.$picker = $picker;
            this.$menu = $menu;
            this.$preview = $preview;
            this.$expand = $expand;
            this.$help = $help;
            this.$tools = $picker; // link layout compat

            this._catalog = this._buildCatalog();
            this._paint();
            this._bind();

            return this;
        },

        _t: function (key, category) {
            return this.view.translate(
                key,
                category || "labels",
                "JourneyStageAction"
            );
        },

        _bind: function () {
            const ns = ".journeyEx" + this._uid;

            const toggle = (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.setMode(
                    this._mode === "expression" ? "fixed" : "expression"
                );
                this.onChange();
            };

            this.$fx.on("click", toggle);
            this.$fxOn.on("click", toggle);

            this.$fixed.on("change input", () => {
                this._fixedValue = this.$fixed.val();
                this.onChange();
            });

            this.$editor.on("input", () => {
                this._syncExpressionFromEditor();
                this._updatePreview();
                this._maybeOpenTypeahead();
                this.onChange();
            });

            this.$editor.on("keydown", (e) => this._onEditorKeydown(e));

            this.$editor.on("focus", () => {
                this.$preview.removeClass("hidden");
            });

            this.$raw.on("input change", () => {
                this._expressionValue = this.$raw.val();
                this._rawFormula = true;
                this._updatePreview();
                this.onChange();
            });

            this.$picker.on("click", (e) => {
                e.preventDefault();
                e.stopPropagation();

                if (this._mode !== "expression") {
                    this.setMode("expression");
                }

                this._openMenu("");
            });

            this.$expand.on("click", (e) => {
                e.preventDefault();
                e.stopPropagation();

                if (this._rawFormula) {
                    this._expressionValue = this.$raw.val();
                    this._loadExpressionIntoUi(this._expressionValue);
                    this.onChange();
                } else {
                    this._toggleRaw(true);
                }
            });

            this.$help.on("click", (e) => {
                e.preventDefault();
                e.stopPropagation();
                this._openMenu("");
            });

            this.$menu.on("keydown", (e) => {
                if (e.key !== "Escape") {
                    return;
                }

                e.preventDefault();
                this.$menu.addClass("hidden");
                (this._rawFormula ? this.$raw : this.$editor).focus();
            });

            $(document).on("mousedown" + ns, (e) => {
                if (
                    this.$root &&
                    this.$root.length &&
                    !$.contains(this.$root.get(0), e.target)
                ) {
                    this.$menu.addClass("hidden");
                }
            });
        },

        destroy: function () {
            $(document).off(".journeyEx" + this._uid);

            if (this.$root) {
                this.$root.remove();
            }

            this.$root = null;
        },

        setMode: function (mode) {
            this._mode = mode === "expression" ? "expression" : "fixed";
            this._paint();
        },

        getMode: function () {
            return this._mode;
        },

        getFixedValue: function () {
            if (this.$fixed && this._mode === "fixed") {
                this._fixedValue = this.$fixed.val();
            }

            return this._fixedValue;
        },

        getExpressionValue: function () {
            if (this._mode === "expression") {
                if (this._rawFormula && this.$raw && !this.$raw.hasClass("hidden")) {
                    this._expressionValue = this.$raw.val();
                } else if (this.$editor) {
                    this._syncExpressionFromEditor();
                }
            }

            return this._expressionValue;
        },

        getState: function () {
            return {
                mode: this.getMode(),
                fixed: String(this.getFixedValue() || ""),
                expression: String(this.getExpressionValue() || "").trim(),
            };
        },

        _paint: function () {
            if (!this.$root) {
                return;
            }

            const isEx = this._mode === "expression";
            this.$root.toggleClass("is-expression", isEx);
            this.$fx.attr("aria-pressed", isEx ? "true" : "false");
            this.$fx.toggleClass("active", isEx);
            this.$fixedRow.toggleClass("hidden", isEx);
            this.$exShell.toggleClass("hidden", !isEx);

            if (isEx) {
                this.$fixed.val(this._fixedValue);
                this._loadExpressionIntoUi(this._expressionValue);
                this._updatePreview();
                this.$preview.removeClass("hidden");
            } else {
                this.$fixed
                    .val(this._fixedValue)
                    .attr("placeholder", this.placeholder);
                this.$menu.addClass("hidden");
                this.$preview.addClass("hidden");
                this._toggleRaw(false);
            }
        },

        _toggleRaw: function (forceRaw) {
            const wasRaw = this._rawFormula;

            if (forceRaw === true) {
                this._rawFormula = true;
            } else if (forceRaw === false) {
                this._rawFormula = false;
            }

            if (!this.$raw || !this.$editor) {
                return;
            }

            if (this._rawFormula) {
                if (!wasRaw) {
                    this._syncExpressionFromEditor();
                }

                this.$raw.val(this._expressionValue).removeClass("hidden");
                this.$editor.addClass("hidden");
                this.$root.addClass("is-raw");
                this.$expand
                    .attr("title", this._t("collapseExpression"))
                    .attr("aria-label", this._t("collapseExpression"))
                    .attr("aria-pressed", "true")
                    .html('<span class="fas fa-compress-alt"></span>');
            } else {
                this.$raw.addClass("hidden");
                this.$editor.removeClass("hidden");
                this.$root.removeClass("is-raw");
                this.$expand
                    .attr("title", this._t("expandExpression"))
                    .attr("aria-label", this._t("expandExpression"))
                    .attr("aria-pressed", "false")
                    .html('<span class="fas fa-expand-alt"></span>');
            }
        },

        /* ---------- catalog / menu ---------- */

        _buildCatalog: function () {
            const groups =
                this.snippets.length > 0
                    ? this.snippets
                    : ExpressionInput.defaultSnippets(this.view);

            const fields = [];
            const variables = [];
            const helpers = [];

            groups.forEach((g) => {
                const label = (g.label || "").toLowerCase();
                (g.items || []).forEach((item) => {
                    const entry = {
                        kind: "field",
                        label: item.label || item.insert,
                        insert: item.insert,
                        chip: item.chip || item.label || item.insert,
                        tone: item.tone || "green",
                        description: item.description || "",
                        examples: item.examples || [],
                    };

                    if (g.kind === "variable" || label.indexOf("journey") !== -1) {
                        entry.kind = "variable";
                        entry.tone = "purple";
                        variables.push(entry);
                    } else if (
                        g.kind === "helper" ||
                        label.indexOf("helper") !== -1 ||
                        label.indexOf("atalho") !== -1
                    ) {
                        entry.kind = "helper";
                        entry.tone = "blue";
                        helpers.push(entry);
                    } else {
                        fields.push(entry);
                    }
                });
            });

            const functions = ExpressionInput.functionCatalog(this.view);

            return { fields, variables, helpers, functions };
        },

        _openMenu: function (filter) {
            this._renderMenu(filter || "");
            this.$menu.removeClass("hidden");
        },

        _renderMenu: function (filter) {
            const q = String(filter || "").toLowerCase().trim();
            this.$menu.empty();

            const $layout = $("<div>").addClass("journey-expression-menu-layout");
            const $left = $("<div>").addClass("journey-expression-menu-list");
            const $right = $("<div>").addClass("journey-expression-menu-docs");
            $layout.append($left).append($right);
            this.$menu.append($layout);

            const $search = $("<input>")
                .attr({
                    type: "search",
                    placeholder: this._t("searchExpressions"),
                })
                .addClass("form-control journey-expression-menu-search")
                .val(filter || "");
            $left.append($search);

            $search.on("input", () => {
                this._renderMenu($search.val());
                this.$menu
                    .find(".journey-expression-menu-search")
                    .focus()
                    .get(0)
                    .setSelectionRange(
                        String($search.val()).length,
                        String($search.val()).length
                    );
            });

            // stop mousedown from blurring editor / closing early
            this.$menu.on("mousedown", (e) => e.stopPropagation());

            const sections = [
                {
                    key: "fields",
                    title: this._t("snippetGroupTarget"),
                    items: this._catalog.fields,
                    icon: "fa-user",
                },
                {
                    key: "variables",
                    title: this._t("snippetGroupJourney"),
                    items: this._catalog.variables,
                    icon: "fa-route",
                },
                {
                    key: "helpers",
                    title: this._t("snippetGroupHelpers"),
                    items: this._catalog.helpers,
                    icon: "fa-magic",
                },
                {
                    key: "functions",
                    title: this._t("snippetGroupFunctions"),
                    items: this._catalog.functions,
                    icon: "fa-function",
                },
            ];

            let first = null;

            sections.forEach((sec) => {
                const items = (sec.items || []).filter((it) => {
                    if (!q) {
                        return true;
                    }

                    const hay = (
                        (it.label || "") +
                        " " +
                        (it.insert || "") +
                        " " +
                        (it.description || "")
                    ).toLowerCase();

                    return hay.indexOf(q) !== -1;
                });

                if (!items.length) {
                    return;
                }

                const $sec = $("<div>").addClass("journey-expression-group");
                $sec.append(
                    $("<div>")
                        .addClass("journey-expression-group-label")
                        .text(sec.title)
                );

                items.forEach((item) => {
                    if (!first) {
                        first = item;
                    }

                    const $item = $("<button>")
                        .attr("type", "button")
                        .addClass("journey-expression-item")
                        .data("item", item);
                    const iconClass =
                        item.kind === "function"
                            ? "journey-item-icon is-fn"
                            : "journey-item-icon is-" + (item.tone || "green");
                    $item.html(
                        '<span class="' +
                            iconClass +
                            '">' +
                            (item.kind === "function" ? "f" : "·") +
                            "</span>" +
                            '<span class="journey-item-body">' +
                            '<span class="label-text"></span>' +
                            '<code class="insert-text"></code>' +
                            "</span>"
                    );
                    $item.find(".label-text").text(item.label || item.insert);
                    $item.find(".insert-text").text(item.insert || "");

                    $item.on("mouseenter", () => this._showDocs(item, $right));
                    $item.on("click", (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        this._applyCatalogItem(item);
                        this.$menu.addClass("hidden");
                        this.onChange();
                    });

                    $sec.append($item);
                });

                $left.append($sec);
            });

            if (first) {
                this._showDocs(first, $right);
            } else {
                $right.html(
                    '<div class="journey-docs-empty">' +
                        this.view.getHelper().escapeString(
                            this.view.translate(
                                "expressionNoMatches",
                                "messages",
                                "JourneyStageAction"
                            )
                        ) +
                        "</div>"
                );
            }

            setTimeout(() => $search.focus(), 0);
        },

        _showDocs: function (item, $right) {
            this._activeItem = item;
            $right = $right || this.$menu.find(".journey-expression-menu-docs");
            $right.empty();

            if (!item) {
                return;
            }

            const esc = (s) => this.view.getHelper().escapeString(String(s || ""));

            $right.append(
                $("<div>")
                    .addClass("journey-docs-title")
                    .html(
                        (item.kind === "function"
                            ? '<span class="journey-item-icon is-fn">f</span> '
                            : "") +
                            "<strong></strong>"
                    )
            );
            $right.find("strong").text(item.label || item.insert);

            if (item.insert) {
                $right.append(
                    $("<code>")
                        .addClass("journey-docs-sig")
                        .text(item.insert)
                );
            }

            if (item.description) {
                $right.append(
                    $("<p>")
                        .addClass("journey-docs-desc")
                        .text(item.description)
                );
            }

            if (item.examples && item.examples.length) {
                $right.append(
                    $("<div>")
                        .addClass("journey-docs-examples-label")
                        .text(this._t("expressionExamples"))
                );
                item.examples.forEach((ex) => {
                    const $box = $("<div>").addClass("journey-docs-example");
                    $box.append(
                        $("<div>")
                            .addClass("ex-code")
                            .text(ex.code || ex)
                    );

                    if (ex.result !== undefined) {
                        $box.append(
                            $("<div>")
                                .addClass("ex-result")
                                .text("= " + ex.result)
                        );
                    }

                    $right.append($box);
                });
            }
        },

        _applyCatalogItem: function (item) {
            if (!item || !item.insert) {
                return;
            }

            if (this._mode !== "expression") {
                this.setMode("expression");
            }

            if (this._rawFormula) {
                this._insertRaw(item.insert);
                return;
            }

            // Executable snippets must stay formula code, not quoted visual text.
            if (
                item.kind === "function" ||
                item.kind === "helper" ||
                item.asText
            ) {
                this._toggleRaw(true);
                const current = String(this.$raw.val() || "").trim();
                const formula = current
                    ? "string\\concatenate(" + current + ", " + item.insert + ")"
                    : item.insert;

                this.$raw.val(formula);
                this._expressionValue = formula;
                this.$raw.focus();

                const el = this.$raw.get(0);

                if (el) {
                    el.setSelectionRange(formula.length, formula.length);
                }
            } else {
                this._insertChip(item);
            }

            this._syncExpressionFromEditor();
            this._updatePreview();
        },

        _onEditorKeydown: function (e) {
            if (e.key === "Escape") {
                this.$menu.addClass("hidden");

                return;
            }

            // Trigger catalog: Ctrl/Cmd+Space or typing '/'
            if (
                (e.key === " " && (e.ctrlKey || e.metaKey)) ||
                (e.key === "/" && !e.altKey && !e.ctrlKey && !e.metaKey)
            ) {
                e.preventDefault();
                this._openMenu("");
            }

            // Backspace on empty chip-edge removes chip
            if (e.key === "Backspace") {
                // default browser behavior is fine for chips as contenteditable elements
            }
        },

        _maybeOpenTypeahead: function () {
            // If editor ends with word chars after whitespace, lightly filter menu if open
            if (this.$menu.hasClass("hidden")) {
                return;
            }

            const text = this.$editor.text() || "";
            const m = text.match(/([a-zA-Z0-9_\\]+)$/);

            if (m) {
                this._renderMenu(m[1]);
            }
        },

        /* ---------- chips / editor ---------- */

        _chipHtml: function (item) {
            const tone = item.tone || "green";
            const label = item.chip || item.label || item.insert;
            const $chip = $("<span>")
                .addClass("journey-ex-chip tone-" + tone)
                .attr({
                    contenteditable: "false",
                    "data-insert": item.insert,
                    "data-label": label,
                    "data-tone": tone,
                    title: item.insert,
                });
            $chip.append(
                $("<span>").addClass("chip-label").text(label)
            );
            $chip.append(
                $("<button>")
                    .attr({
                        type: "button",
                        title: this._t("removeDynamicValue"),
                        "aria-label": this._t("removeDynamicValue"),
                    })
                    .addClass("chip-remove")
                    .html("&times;")
            );

            return $chip;
        },

        _insertChip: function (item) {
            this.$editor.focus();
            const sel = window.getSelection();

            if (!sel || !sel.rangeCount) {
                this.$editor.append(this._chipHtml(item));
                this.$editor.append(document.createTextNode("\u00a0"));

                return;
            }

            const range = sel.getRangeAt(0);

            if (!this.$editor.get(0).contains(range.commonAncestorContainer)) {
                this.$editor.append(this._chipHtml(item));

                return;
            }

            range.deleteContents();
            const chip = this._chipHtml(item).get(0);
            range.insertNode(chip);

            // caret after chip
            const space = document.createTextNode("\u00a0");
            chip.after(space);
            range.setStartAfter(space);
            range.collapse(true);
            sel.removeAllRanges();
            sel.addRange(range);

            this.$editor
                .find(".chip-remove")
                .off("click")
                .on("click", (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    $(e.currentTarget).closest(".journey-ex-chip").remove();
                    this._syncExpressionFromEditor();
                    this._updatePreview();
                    this.onChange();
                });
        },

        _insertText: function (text) {
            this.$editor.focus();
            const sel = window.getSelection();

            if (!sel || !sel.rangeCount) {
                this.$editor.append(document.createTextNode(text));

                return;
            }

            const range = sel.getRangeAt(0);

            if (!this.$editor.get(0).contains(range.commonAncestorContainer)) {
                this.$editor.append(document.createTextNode(text));

                return;
            }

            range.deleteContents();
            const node = document.createTextNode(text);
            range.insertNode(node);
            range.setStartAfter(node);
            range.collapse(true);
            sel.removeAllRanges();
            sel.addRange(range);
        },

        _insertRaw: function (text) {
            const el = this.$raw.get(0);
            const cur = String(this.$raw.val() || "");

            if (
                el &&
                typeof el.selectionStart === "number"
            ) {
                const start = el.selectionStart;
                const end = el.selectionEnd;
                const next = cur.slice(0, start) + text + cur.slice(end);
                this.$raw.val(next);
                this._expressionValue = next;
                el.focus();
                el.setSelectionRange(start + text.length, start + text.length);
            } else {
                this.$raw.val(cur + text);
                this._expressionValue = cur + text;
            }
        },

        _bindChipRemoves: function () {
            this.$editor
                .find(".chip-remove")
                .off("click")
                .on("click", (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    $(e.currentTarget).closest(".journey-ex-chip").remove();
                    this._syncExpressionFromEditor();
                    this._updatePreview();
                    this.onChange();
                });
        },

        _syncExpressionFromEditor: function () {
            if (!this.$editor || this.$editor.hasClass("hidden")) {
                return;
            }

            const parts = [];
            const root = this.$editor.get(0);

            const walk = (node) => {
                if (node.nodeType === Node.TEXT_NODE) {
                    const t = String(node.textContent || "")
                        .replace(/\u00a0/g, " ");

                    if (t.length) {
                        parts.push({ type: "text", value: t });
                    }

                    return;
                }

                if (node.nodeType !== Node.ELEMENT_NODE) {
                    return;
                }

                const el = node;

                if (el.classList && el.classList.contains("journey-ex-chip")) {
                    parts.push({
                        type: "chip",
                        insert: el.getAttribute("data-insert") || "",
                        label: el.getAttribute("data-label") || "",
                    });

                    return;
                }

                if (el.tagName === "BR") {
                    parts.push({ type: "text", value: "\n" });

                    return;
                }

                if (
                    (el.tagName === "DIV" || el.tagName === "P") &&
                    parts.length
                ) {
                    const previous = parts[parts.length - 1];

                    if (
                        previous.type !== "text" ||
                        !previous.value.endsWith("\n")
                    ) {
                        parts.push({ type: "text", value: "\n" });
                    }
                }

                Array.prototype.forEach.call(el.childNodes || [], walk);
            };

            Array.prototype.forEach.call(root.childNodes || [], walk);
            this._expressionValue = this._partsToFormula(parts);
        },

        _partsToFormula: function (parts) {
            // collapse adjacent text
            const collapsed = [];
            parts.forEach((p) => {
                if (
                    p.type === "text" &&
                    collapsed.length &&
                    collapsed[collapsed.length - 1].type === "text"
                ) {
                    collapsed[collapsed.length - 1].value += p.value;
                } else {
                    collapsed.push(p);
                }
            });

            // trim pure-whitespace edges while keeping internal spaces
            while (
                collapsed.length &&
                collapsed[0].type === "text" &&
                !collapsed[0].value.trim()
            ) {
                collapsed.shift();
            }

            while (
                collapsed.length &&
                collapsed[collapsed.length - 1].type === "text" &&
                !collapsed[collapsed.length - 1].value.trim()
            ) {
                collapsed.pop();
            }

            if (!collapsed.length) {
                return "";
            }

            const formulaParts = collapsed.map((p) => {
                if (p.type === "chip") {
                    return p.insert;
                }

                return this._quoteString(p.value);
            });

            if (formulaParts.length === 1) {
                return formulaParts[0];
            }

            return "string\\concatenate(" + formulaParts.join(", ") + ")";
        },

        _quoteString: function (s) {
            return (
                "'" +
                String(s)
                    .replace(/\\/g, "\\\\")
                    .replace(/'/g, "\\'") +
                "'"
            );
        },

        _loadExpressionIntoUi: function (formula) {
            const f = String(formula || "").trim();
            this.$editor.empty();
            this._rawFormula = false;

            if (!f) {
                this._toggleRaw(false);
                this._bindChipRemoves();

                return;
            }

            const parts = this._formulaToParts(f);

            if (!parts) {
                // unparsable → raw mode
                this._rawFormula = true;
                this.$raw.val(f);
                this._expressionValue = f;
                this._toggleRaw(true);

                return;
            }

            parts.forEach((p) => {
                if (p.type === "chip") {
                    this.$editor.append(
                        this._chipHtml({
                            insert: p.insert,
                            chip: p.label,
                            label: p.label,
                            tone: p.tone || "green",
                        })
                    );
                } else if (p.value) {
                    this.$editor.append(document.createTextNode(p.value));
                }
            });

            this._bindChipRemoves();
            this._toggleRaw(false);
            this._expressionValue = f;
        },

        /**
         * Best-effort parse of simple formulas into visual parts.
         * @return {Array|null}
         */
        _formulaToParts: function (formula) {
            const f = String(formula || "").trim();

            if (!f) {
                return [];
            }

            let m = f.match(ATTR_RE);

            if (m) {
                return [
                    {
                        type: "chip",
                        insert: f,
                        label: this._attrLabel(m[1]),
                        tone: "green",
                    },
                ];
            }

            m = f.match(VAR_RE);

            if (m) {
                return [
                    {
                        type: "chip",
                        insert: f,
                        label: m[1],
                        tone: "purple",
                    },
                ];
            }

            m = f.match(CF_RE);

            if (m) {
                return [
                    {
                        type: "chip",
                        insert: f,
                        label: "CF:" + m[1],
                        tone: "teal",
                    },
                ];
            }

            // string\concatenate(a, b, ...)
            if (f.indexOf("string\\concatenate(") === 0 && f.endsWith(")")) {
                const inner = f.slice("string\\concatenate(".length, -1);
                const args = this._splitArgs(inner);

                if (!args || !args.length) {
                    return null;
                }

                const parts = [];

                for (let i = 0; i < args.length; i++) {
                    const arg = args[i].trim();
                    const sub = this._formulaToParts(arg);

                    if (!sub || sub.length !== 1) {
                        // plain quoted string?
                        const sm = arg.match(/^'([\s\S]*)'$/);

                        if (sm) {
                            const value = sm[1]
                                .replace(/\\'/g, "'")
                                .replace(/\\\\/g, "\\");

                            if (!value.trim()) {
                                return null;
                            }

                            parts.push({
                                type: "text",
                                value: value,
                            });
                        } else {
                            return null;
                        }
                    } else {
                        parts.push(sub[0]);
                    }
                }

                return parts;
            }

            // plain quoted string as sole expression
            const onlyStr = f.match(/^'([\s\S]*)'$/);

            if (onlyStr) {
                const value = onlyStr[1]
                    .replace(/\\'/g, "'")
                    .replace(/\\\\/g, "\\");

                if (!value.trim()) {
                    return null;
                }

                return [
                    {
                        type: "text",
                        value: value,
                    },
                ];
            }

            return null;
        },

        _splitArgs: function (inner) {
            const args = [];
            let cur = "";
            let depth = 0;
            let inStr = false;
            let esc = false;

            for (let i = 0; i < inner.length; i++) {
                const ch = inner.charAt(i);

                if (inStr) {
                    cur += ch;

                    if (esc) {
                        esc = false;
                    } else if (ch === "\\") {
                        esc = true;
                    } else if (ch === "'") {
                        inStr = false;
                    }

                    continue;
                }

                if (ch === "'") {
                    inStr = true;
                    cur += ch;
                    continue;
                }

                if (ch === "(") {
                    depth++;
                    cur += ch;
                    continue;
                }

                if (ch === ")") {
                    depth--;
                    cur += ch;
                    continue;
                }

                if (ch === "," && depth === 0) {
                    args.push(cur);
                    cur = "";
                    continue;
                }

                cur += ch;
            }

            if (inStr || depth !== 0) {
                return null;
            }

            if (cur.trim() !== "" || args.length) {
                args.push(cur);
            }

            return args;
        },

        _attrLabel: function (attr) {
            const map = {
                name: this._t("snippetName"),
                firstName: this._t("snippetFirstName"),
                lastName: this._t("snippetLastName"),
                emailAddress: this._t("snippetEmail"),
                phoneNumber: this._t("snippetPhone"),
                assignedUserId: this._t("snippetAssignedUser"),
                id: this._t("snippetId"),
            };

            return map[attr] || attr;
        },

        _updatePreview: function () {
            if (!this.$preview) {
                return;
            }

            const formula = String(this.getExpressionValue() || "").trim();

            if (!formula) {
                this.$preview
                    .html(
                        '<span class="preview-eq">=</span> ' +
                            '<span class="preview-empty">' +
                            this.view.getHelper().escapeString(
                                this.view.translate(
                                    "expressionPreviewEmpty",
                                    "messages",
                                    "JourneyStageAction"
                                )
                            ) +
                            "</span>"
                    )
                    .removeClass("hidden");

                return;
            }

            const human = this._humanize(formula);
            this.$preview
                .html(
                    '<span class="preview-eq">=</span> ' +
                        '<span class="preview-human"></span>' +
                        '<span class="preview-note"></span>'
                )
                .removeClass("hidden");
            this.$preview.find(".preview-human").text(human);
            this.$preview
                .find(".preview-note")
                .text(
                    " · " +
                        this.view.translate(
                            "expressionPreviewNote",
                            "messages",
                            "JourneyStageAction"
                        )
                );
        },

        _humanize: function (formula) {
            const parts = this._formulaToParts(formula);

            if (parts && parts.length) {
                return parts
                    .map((p) => {
                        if (p.type === "chip") {
                            return p.label || p.insert;
                        }

                        return p.value;
                    })
                    .join("");
            }

            if (formula.length > 80) {
                return formula.slice(0, 77) + "…";
            }

            return formula;
        },
    });

    ExpressionInput.defaultSnippets = function (view, entityType) {
        const t = (key) =>
            view.translate(key, "labels", "JourneyStageAction");
        const tm = (key) =>
            view.translate(key, "messages", "JourneyStageAction");

        const targetItems = [
            {
                label: t("snippetName"),
                chip: t("snippetName"),
                insert: "entity\\attribute('name')",
                tone: "green",
                description: tm("docAttrName"),
            },
            {
                label: t("snippetEmail"),
                chip: t("snippetEmail"),
                insert: "entity\\attribute('emailAddress')",
                tone: "green",
                description: tm("docAttrEmail"),
            },
            {
                label: t("snippetPhone"),
                chip: t("snippetPhone"),
                insert: "entity\\attribute('phoneNumber')",
                tone: "green",
                description: tm("docAttrPhone"),
            },
            {
                label: t("snippetAssignedUser"),
                chip: t("snippetAssignedUser"),
                insert: "entity\\attribute('assignedUserId')",
                tone: "green",
                description: tm("docAttrOwner"),
            },
            {
                label: t("snippetId"),
                chip: "id",
                insert: "entity\\attribute('id')",
                tone: "green",
                description: tm("docAttrId"),
            },
        ];

        if (entityType === "Contact" || entityType === "Lead") {
            targetItems.splice(1, 0, {
                label: t("snippetFirstName"),
                chip: t("snippetFirstName"),
                insert: "entity\\attribute('firstName')",
                tone: "green",
                description: tm("docAttrFirstName"),
            });
            targetItems.splice(2, 0, {
                label: t("snippetLastName"),
                chip: t("snippetLastName"),
                insert: "entity\\attribute('lastName')",
                tone: "green",
                description: tm("docAttrLastName"),
            });
        }

        return [
            { kind: "field", label: t("snippetGroupTarget"), items: targetItems },
            {
                kind: "variable",
                label: t("snippetGroupJourney"),
                items: [
                    {
                        label: t("snippetJourneyRecordId"),
                        chip: "enrollment",
                        insert: "$journeyRecordId",
                        tone: "purple",
                        description: tm("docVarRecord"),
                    },
                    {
                        label: t("snippetJourneyId"),
                        chip: "journey",
                        insert: "$journeyId",
                        tone: "purple",
                        description: tm("docVarJourney"),
                    },
                    {
                        label: t("snippetStageId"),
                        chip: "stage",
                        insert: "$stageId",
                        tone: "purple",
                        description: tm("docVarStage"),
                    },
                ],
            },
            {
                kind: "helper",
                label: t("snippetGroupHelpers"),
                items: [
                    {
                        label: t("snippetIfEmpty"),
                        insert:
                            "ifThenElse(entity\\attribute('emailAddress'), entity\\attribute('emailAddress'), '')",
                        asText: true,
                        description: tm("docHelperIfEmpty"),
                        examples: [
                            {
                                code: "ifThenElse(email, email, '')",
                                result: "email or empty string",
                            },
                        ],
                    },
                    {
                        label: t("snippetCustomField"),
                        insert:
                            "object\\get(entity\\attribute('customFields'), 'your.key')",
                        chip: "CF:your.key",
                        tone: "teal",
                        description: tm("docHelperCustomField"),
                    },
                    {
                        label: t("snippetConcat"),
                        insert:
                            "string\\concatenate(entity\\attribute('firstName'), ' ', entity\\attribute('lastName'))",
                        asText: true,
                        description: tm("docHelperConcat"),
                    },
                ],
            },
        ];
    };

    ExpressionInput.functionCatalog = function (view) {
        const t = (key) =>
            view.translate(key, "labels", "JourneyStageAction");
        const tm = (key) =>
            view.translate(key, "messages", "JourneyStageAction");

        return [
            {
                kind: "function",
                label: "string\\concatenate()",
                insert: "string\\concatenate()",
                description: tm("docFnConcatenate"),
                examples: [
                    {
                        code: "string\\concatenate('Hi ', entity\\attribute('firstName'))",
                        result: "Hi Ada",
                    },
                ],
            },
            {
                kind: "function",
                label: "ifThenElse()",
                insert: "ifThenElse(condition, whenTrue, whenFalse)",
                description: tm("docFnIfThenElse"),
                examples: [
                    {
                        code: "ifThenElse(entity\\attribute('emailAddress'), 'has email', 'no email')",
                        result: "has email",
                    },
                ],
            },
            {
                kind: "function",
                label: "string\\contains()",
                insert: "string\\contains(haystack, needle)",
                description: tm("docFnContains"),
                examples: [
                    {
                        code: "string\\contains(entity\\attribute('emailAddress'), '@gmail')",
                        result: "true / false",
                    },
                ],
            },
            {
                kind: "function",
                label: "string\\upperCase()",
                insert: "string\\upperCase()",
                description: tm("docFnUpper"),
            },
            {
                kind: "function",
                label: "string\\lowerCase()",
                insert: "string\\lowerCase()",
                description: tm("docFnLower"),
            },
            {
                kind: "function",
                label: "string\\trim()",
                insert: "string\\trim()",
                description: tm("docFnTrim"),
            },
            {
                kind: "function",
                label: "number\\format()",
                insert: "number\\format(value, decimals)",
                description: tm("docFnNumberFormat"),
            },
            {
                kind: "function",
                label: "datetime\\now()",
                insert: "datetime\\now()",
                description: tm("docFnNow"),
            },
            {
                kind: "function",
                label: "datetime\\today()",
                insert: "datetime\\today()",
                description: tm("docFnToday"),
            },
            {
                kind: "function",
                label: "object\\get()",
                insert: "object\\get(object, key)",
                description: tm("docFnObjectGet"),
                examples: [
                    {
                        code: "object\\get(entity\\attribute('customFields'), 'plan')",
                        result: "Premium",
                    },
                ],
            },
            {
                kind: "function",
                label: "entity\\attribute()",
                insert: "entity\\attribute('fieldName')",
                description: tm("docFnAttribute"),
            },
            {
                kind: "function",
                label: "util\\empty()",
                insert: "util\\empty(value)",
                description: tm("docFnEmpty"),
            },
        ].map((fn) => {
            fn.label = fn.label || fn.insert;

            return fn;
        });
    };

    ExpressionInput.supportsType = function (type) {
        return (
            type === "varchar" ||
            type === "text" ||
            type === "json" ||
            type === "link" ||
            !type
        );
    };

    return ExpressionInput;
});
