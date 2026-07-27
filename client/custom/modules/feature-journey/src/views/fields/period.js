define("feature-journey:views/fields/period", ["views/fields/base"], function (Dep) {
    /**
     * Friendly period picker.
     *
     * Storage is always canonical English ("3 days", "12 hours") so the backend and the
     * rule compiler see one format. The dropdown labels are localised, and free-form
     * input accepts pt-BR words ("3 dias") which are normalised to English on save.
     *
     * The unit alias table mirrors PeriodParser.php. Keep the two in sync:
     *   custom/Espo/Modules/FeatureJourney/Services/PeriodParser.php
     */
    const UNIT_ALIASES = {
        // English
        second: "second", seconds: "second",
        minute: "minute", minutes: "minute",
        hour: "hour", hours: "hour",
        day: "day", days: "day",
        week: "week", weeks: "week",
        // pt-BR
        segundo: "second", segundos: "second",
        minuto: "minute", minutos: "minute",
        hora: "hour", horas: "hour",
        dia: "day", dias: "day",
        semana: "week", semanas: "week",
    };

    const stripAccents = (s) =>
        s.replace(/[áàãâÁÀÃÂ]/g, "a")
            .replace(/[éêÉÊ]/g, "e")
            .replace(/[íÍ]/g, "i")
            .replace(/[óôõÓÔÕ]/g, "o")
            .replace(/[úÚ]/g, "u")
            .replace(/[çÇ]/g, "c");

    /** "3 dias" | "3 days" | "PT30M" -> {amount, unit} in canonical singular, or null. */
    const splitPeriod = function (value) {
        if (!value || typeof value !== "string") {
            return null;
        }

        const m = value.trim().match(/^(\d+)\s*([A-Za-zÀ-ÿ]+)$/);

        if (!m) {
            return null;
        }

        const unit = UNIT_ALIASES[stripAccents(m[2]).toLowerCase()];

        if (!unit) {
            return null;
        }

        return { amount: parseInt(m[1], 10), unit: unit };
    };

    /** Canonical English storage form, or null when unparseable. */
    const canonicalise = function (value) {
        const parsed = splitPeriod(value);

        if (!parsed) {
            return null;
        }

        return parsed.amount + " " + parsed.unit + (parsed.amount === 1 ? "" : "s");
    };

    return Dep.extend({
        type: "base",

        validations: ["required", "period"],

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
                    'placeholder="{{rawPlaceholder}}">' +
            '</div>' +
            '{{/if}}',

        detailTemplateContent:
            '{{#if isNotEmpty}}' +
            '<span>{{displayValue}}</span>' +
            '{{else}}' +
            '<span class="none-value">{{translate "None"}}</span>' +
            '{{/if}}',

        listTemplateContent:
            '{{#if isNotEmpty}}{{displayValue}}{{else}}' +
            '<span class="none-value">{{translate "None"}}</span>{{/if}}',

        unitList: ["minutes", "hours", "days", "weeks"],

        data: function () {
            const raw = this.model.get(this.name);
            const parsed = this.parseValue(raw);
            const unitList = this.unitList.map((u) => ({
                value: u,
                label: this.translate(u, "labels", "Journey") || u,
                selected: u === parsed.unit,
            }));

            return {
                ...Dep.prototype.data.call(this),
                amount: parsed.amount !== null ? parsed.amount : "",
                unitList: unitList,
                value: raw || "",
                displayValue: this.getDisplayValue(raw),
                rawValue: raw || "",
                rawPlaceholder: this.translateMessage("periodRawPlaceholder"),
                rawFallback: true,
                isNotEmpty: !!raw,
            };
        },

        translateMessage: function (key) {
            const text = this.translate(key, "messages", "Journey");

            return text === key ? "" : text;
        },

        /**
         * Localised read-only rendering. The stored value stays English; only what the
         * user sees is translated, so "3 days" shows as "3 dias" in pt-BR.
         */
        getDisplayValue: function (value) {
            const parsed = splitPeriod(value);

            if (!parsed) {
                return value || "";
            }

            const key = parsed.amount === 1 ? parsed.unit : parsed.unit + "s";
            const label = this.translate(key, "labels", "Journey");

            return parsed.amount + " " + (label === key ? key : label);
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
            const parsed = splitPeriod(value);

            if (!parsed) {
                return { amount: null, unit: "days", raw: value || undefined };
            }

            // "seconds" is not offered in the dropdown; keep it in the raw box.
            if (parsed.unit === "second") {
                return { amount: null, unit: "minutes", raw: value };
            }

            return { amount: parsed.amount, unit: parsed.unit + "s" };
        },

        /**
         * Blocks values the backend cannot parse. Without this an invalid string was
         * saved happily and the timer / SLA then silently never fired.
         */
        validatePeriod: function () {
            const value = this.model.get(this.name);

            if (!value) {
                return false;
            }

            if (canonicalise(value) || /^P(?=[\dT])[\dTWDHMSY.,]*$/i.test(String(value).trim())) {
                return false;
            }

            const msg = this.translateMessage("periodInvalid") ||
                'Use a format like "3 days" or "12 hours".';

            this.showValidationMessage(msg);

            return true;
        },

        fetch: function () {
            const data = {};
            if (!this.$el || !this.$el.length) {
                return data;
            }

            if (this._useRaw && this.$raw && this.$raw.val()) {
                const raw = String(this.$raw.val()).trim();
                // Normalise pt-BR / singular input to canonical English storage. Values
                // we cannot parse are kept verbatim so validatePeriod() can flag them.
                data[this.name] = canonicalise(raw) || raw || null;

                return data;
            }

            const amount = this.$amount ? this.$amount.val() : "";
            const unit = this.$unit ? this.$unit.val() : "days";

            if (amount === "" || amount === null || isNaN(Number(amount))) {
                const raw = this.$raw ? String(this.$raw.val() || "").trim() : "";
                data[this.name] = raw ? canonicalise(raw) || raw : null;

                return data;
            }

            const n = parseInt(amount, 10);
            if (n <= 0) {
                data[this.name] = null;

                return data;
            }

            data[this.name] = canonicalise(n + " " + unit) || n + " " + unit;

            return data;
        },
    });
});
