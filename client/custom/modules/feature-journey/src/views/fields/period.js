define("feature-journey:views/fields/period", ["views/fields/base"], function (Dep) {
    /**
     * Friendly period picker → stores "3 days", "12 hours", etc.
     */
    return Dep.extend({
        type: "base",

        editTemplateContent:
            '<div class="input-group journey-period-field">' +
                '<input type="number" class="form-control period-amount" min="0" step="1" ' +
                    'value="{{amount}}" placeholder="0" style="max-width:110px">' +
                '<span class="input-group-addon" style="width:auto;padding:0;border:0;">' +
                    '<select class="form-control period-unit" style="border-radius:0;min-width:110px">' +
                        '{{#each unitList}}' +
                        '<option value="{{this.value}}" {{#if this.selected}}selected{{/if}}>{{this.label}}</option>' +
                        '{{/each}}' +
                    '</select>' +
                '</span>' +
            '</div>' +
            '{{#if rawFallback}}' +
            '<div class="margin-top-sm">' +
                '<input type="text" class="form-control period-raw" value="{{rawValue}}" ' +
                    'placeholder="or free-form e.g. 3 days">' +
            '</div>' +
            '{{/if}}',

        detailTemplateContent:
            '{{#if isNotEmpty}}' +
            '<span>{{value}}</span>' +
            '{{else}}' +
            '<span class="none-value">{{translate "None"}}</span>' +
            '{{/if}}',

        listTemplateContent:
            '{{#if isNotEmpty}}{{value}}{{else}}' +
            '<span class="none-value">{{translate "None"}}</span>{{/if}}',

        unitList: ["minutes", "hours", "days", "weeks"],

        data: function () {
            const parsed = this.parseValue(this.model.get(this.name));
            const unitList = this.unitList.map((u) => ({
                value: u,
                label: this.translate(u, "labels", "Journey") || u,
                selected: u === parsed.unit,
            }));

            return {
                ...Dep.prototype.data.call(this),
                amount: parsed.amount !== null ? parsed.amount : "",
                unitList: unitList,
                value: this.model.get(this.name) || "",
                rawValue: this.model.get(this.name) || "",
                rawFallback: true,
                isNotEmpty: !!this.model.get(this.name),
            };
        },

        afterRender: function () {
            if (!this.isEditMode()) {
                return;
            }

            this.$amount = this.$el.find(".period-amount");
            this.$unit = this.$el.find(".period-unit");
            this.$raw = this.$el.find(".period-raw");

            const sync = () => this.trigger("change");

            this.$amount.on("change input", sync);
            this.$unit.on("change", sync);
            this.$raw.on("change input", () => {
                // Free-form overrides composed value when user types into it.
                this._useRaw = true;
                this.trigger("change");
            });
            this.$amount.on("input", () => {
                this._useRaw = false;
            });
            this.$unit.on("change", () => {
                this._useRaw = false;
            });
        },

        parseValue: function (value) {
            if (!value || typeof value !== "string") {
                return { amount: null, unit: "days" };
            }

            const m = value
                .trim()
                .match(
                    /^(\d+)\s*(seconds?|minutes?|hours?|days?|weeks?)$/i
                );

            if (!m) {
                return { amount: null, unit: "days", raw: value };
            }

            let unit = m[2].toLowerCase();
            if (!unit.endsWith("s")) {
                unit = unit + "s";
            }
            // normalize second(s) → not in unit list; fall to minutes raw
            if (unit === "seconds") {
                return { amount: null, unit: "minutes", raw: value };
            }

            return { amount: parseInt(m[1], 10), unit: unit };
        },

        fetch: function () {
            const data = {};
            if (!this.$el || !this.$el.length) {
                return data;
            }

            if (this._useRaw && this.$raw && this.$raw.val()) {
                data[this.name] = String(this.$raw.val()).trim() || null;
                return data;
            }

            const amount = this.$amount ? this.$amount.val() : "";
            const unit = this.$unit ? this.$unit.val() : "days";

            if (amount === "" || amount === null || isNaN(Number(amount))) {
                const raw = this.$raw ? String(this.$raw.val() || "").trim() : "";
                data[this.name] = raw || null;
                return data;
            }

            const n = parseInt(amount, 10);
            if (n <= 0) {
                data[this.name] = null;
                return data;
            }

            data[this.name] = n + " " + unit;
            return data;
        },
    });
});
