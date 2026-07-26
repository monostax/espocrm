define("feature-journey:views/journey-transition/fields/event-codes", [
    "views/fields/multi-enum",
], function (Dep) {
    /**
     * Multi-select signal event codes (OR wake gate).
     * Options: platform catalog + active TrackingEventType (tenant).
     * Stateful multi-event AND → conditions eventHistory leaves.
     */
    return Dep.extend({
        setup: function () {
            this.params = this.params || {};

            if (!this.params.translation) {
                this.params.translation = "JourneyTransition.options.eventCodes";
            }

            this.setupOptions();
            Dep.prototype.setup.call(this);
            this.loadTrackingEventTypes();
        },

        setupOptions: function () {
            const platform =
                this.getMetadata().get([
                    "app",
                    "journeySignalEventCodes",
                    "codeList",
                ]) || [];

            const options = [];
            const labels = {};

            platform.forEach((code) => {
                if (!code || options.includes(code)) {
                    return;
                }

                options.push(code);
                labels[code] = this.translateOptionLabel(code);
            });

            this.params.options = options;
            this.translatedOptions = labels;
        },

        translateOptionLabel: function (code) {
            const fromLang = this.getLanguage().translateOption(
                code,
                "eventCodes",
                "JourneyTransition"
            );

            if (fromLang && fromLang !== code) {
                return fromLang;
            }

            return code;
        },

        loadTrackingEventTypes: function () {
            if (!this.getAcl().checkScope("TrackingEventType", "read")) {
                return;
            }

            const where = [
                {
                    type: "isTrue",
                    attribute: "isActive",
                },
            ];

            const tenantId = this.model.get("tenantId");

            if (tenantId) {
                where.push({
                    type: "equals",
                    attribute: "tenantId",
                    value: tenantId,
                });
            }

            this.fetchTrackingEventTypes(where, 0, []).then((result) => {
                const options = (this.params.options || []).slice();
                const labels = Object.assign({}, this.translatedOptions || {});

                result.list.forEach((row) => {
                    const code = row && row.code;

                    if (!code || typeof code !== "string") {
                        return;
                    }

                    if (!options.includes(code)) {
                        options.push(code);
                    }

                    if (row.name) {
                        labels[code] = row.name;
                    } else if (!labels[code]) {
                        labels[code] = this.translateOptionLabel(code);
                    }
                });

                options.sort((a, b) => {
                    const la = labels[a] || a;
                    const lb = labels[b] || b;

                    return String(la).localeCompare(String(lb), undefined, {
                        sensitivity: "base",
                    });
                });

                this.params.options = options;
                this.translatedOptions = labels;

                if (this.isEditMode() && this.isRendered()) {
                    this.reRender();
                }
            });
        },

        /**
         * @param {Array} where
         * @param {number} offset
         * @param {Array} acc
         * @return {Promise<{list: Array}>}
         */
        fetchTrackingEventTypes: function (where, offset, acc) {
            const pageSize = 200;

            return Espo.Ajax.getRequest("TrackingEventType", {
                maxSize: pageSize,
                offset: offset,
                orderBy: "name",
                order: "asc",
                select: "id,name,code",
                where: where,
            })
                .then((response) => {
                    const list = (response && response.list) || [];
                    const next = acc.concat(list);
                    const total =
                        typeof response.total === "number"
                            ? response.total
                            : next.length;

                    if (list.length < pageSize || next.length >= total) {
                        return {list: next};
                    }

                    if (next.length >= 1000) {
                        return {list: next};
                    }

                    return this.fetchTrackingEventTypes(
                        where,
                        offset + pageSize,
                        next
                    );
                })
                .catch(() => {
                    return {list: acc};
                });
        },
    });
});
