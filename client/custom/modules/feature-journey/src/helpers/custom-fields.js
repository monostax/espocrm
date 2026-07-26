define("feature-journey:helpers/custom-fields", [], function () {
    /**
     * Load Monostax CustomField meta and build attribute pick lists
     * for Journey entityFilter / updateTarget UIs.
     */
    const Helper = function (view) {
        this.view = view;
        this._cache = {};
    };

    _.extend(Helper.prototype, {
        attributeName: function () {
            return (
                this.view.getMetadata().get(["app", "customFields", "attributeName"]) ||
                "customFields"
            );
        },

        enabledEntityTypes: function () {
            return (
                this.view.getMetadata().get(["app", "customFields", "entityTypeList"]) ||
                []
            );
        },

        isEntityEnabled: function (entityType) {
            return this.enabledEntityTypes().indexOf(entityType) !== -1;
        },

        getJourneyContext: function () {
            const model = this.view.model;
            let tenantId =
                model.get("tenantId") ||
                (model.get("tenant") && model.get("tenant").id) ||
                null;
            let entityType = null;

            // Transition/record: take journey link if attributes already on model
            if (model.get("targetEntityType")) {
                entityType = model.get("targetEntityType");
            }

            return {
                tenantId: tenantId,
                entityType: entityType,
                journeyId: model.get("journeyId") || null,
            };
        },

        /**
         * Resolve journey targetEntityType + tenantId when missing on the transition model.
         * @return {Promise<{tenantId: ?string, entityType: ?string}>}
         */
        resolveContext: function () {
            const base = this.getJourneyContext();

            if (base.entityType && base.tenantId) {
                return Promise.resolve(base);
            }

            // Journey detail form
            if (this.view.model.entityType === "Journey") {
                return Promise.resolve({
                    tenantId: base.tenantId || this.view.model.get("tenantId"),
                    entityType:
                        base.entityType || this.view.model.get("targetEntityType"),
                    journeyId: this.view.model.id,
                });
            }

            const loadJourney = (journeyId) => {
                if (!journeyId) {
                    return Promise.resolve(base);
                }

                return Espo.Ajax.getRequest("Journey/" + journeyId, {
                    select: "targetEntityType,tenantId",
                })
                    .then((journey) => {
                        return {
                            tenantId: base.tenantId || journey.tenantId || null,
                            entityType:
                                base.entityType || journey.targetEntityType || null,
                            journeyId: journeyId,
                        };
                    })
                    .catch(() => base);
            };

            const journeyId = base.journeyId || this.view.model.get("journeyId");

            if (journeyId) {
                return loadJourney(journeyId);
            }

            const stageId = this.view.model.get("stageId");

            if (!stageId) {
                return Promise.resolve(base);
            }

            return Espo.Ajax.getRequest("JourneyStage/" + stageId, {
                select: "journeyId",
            })
                .then((stage) => loadJourney(stage && stage.journeyId))
                .catch(() => base);
        },

        /**
         * @return {Promise<list<{value:string,label:string,group?:string,isCustomField?:boolean,cfType?:string}>>}
         */
        loadAttributeOptions: function (entityType, tenantId) {
            const native = this.nativeAttributeOptions(entityType);

            if (!entityType || !this.isEntityEnabled(entityType)) {
                return Promise.resolve(native);
            }

            return this.loadCustomFieldOptions(entityType, tenantId).then((cf) => {
                return native.concat(cf);
            });
        },

        nativeAttributeOptions: function (entityType) {
            if (!entityType) {
                return [];
            }

            const fields =
                this.view.getMetadata().get(["entityDefs", entityType, "fields"]) ||
                {};
            const skipTypes = {
                linkMultiple: true,
                attachmentMultiple: true,
                map: true,
                wysiwyg: true,
                file: true,
                image: true,
                jsonArray: true,
            };
            const skipNames = {
                customFields: true,
                teamsIds: true,
                teams: true,
                deleted: true,
                password: true,
            };
            const out = [];

            Object.keys(fields)
                .sort()
                .forEach((name) => {
                    const def = fields[name] || {};

                    if (def.disabled || def.utility || def.notStorable) {
                        return;
                    }

                    if (skipNames[name] || skipTypes[def.type]) {
                        return;
                    }

                    // skip link Ids as primary picks except assignedUserId etc common
                    if (def.type === "link" || def.type === "linkParent") {
                        return;
                    }

                    const label =
                        this.view.translate(name, "fields", entityType) || name;

                    out.push({
                        value: name,
                        label: label + " (" + name + ")",
                        group: "native",
                        isCustomField: false,
                    });
                });

            return out;
        },

        loadCustomFieldOptions: function (entityType, tenantId) {
            const cacheKey = entityType + "|" + (tenantId || "");

            if (this._cache[cacheKey]) {
                return Promise.resolve(this._cache[cacheKey]);
            }

            const data = { entityType: entityType };

            if (tenantId) {
                data.tenantId = tenantId;
            }

            return Espo.Ajax.getRequest("CustomField/action/meta", data)
                .then((meta) => {
                    const attr = (meta && meta.attributeName) || this.attributeName();
                    const out = [];
                    const groups = (meta && meta.groups) || [];

                    groups.forEach((g) => {
                        (g.fields || []).forEach((f) => {
                            const valueKey = f.valueKey || f.name;

                            if (!valueKey) {
                                return;
                            }

                            const value = attr + "." + valueKey;
                            const gLabel =
                                g.name === "_general" ? null : g.label || g.name;
                            const labelBase = f.label || valueKey;
                            const label = gLabel
                                ? "Custom: " + gLabel + " / " + labelBase
                                : "Custom: " + labelBase;

                            out.push({
                                value: value,
                                label: label,
                                group: "customField",
                                isCustomField: true,
                                cfType: f.type || "varchar",
                                valueKey: valueKey,
                            });
                        });
                    });

                    this._cache[cacheKey] = out;

                    return out;
                })
                .catch(() => []);
        },

        /**
         * Field names for updateTarget fieldMap (native allow-list + CF dotted keys).
         */
        loadUpdateTargetOptions: function (entityType, tenantId) {
            const byType =
                this.view.getMetadata().get([
                    "app",
                    "journeyUpdateTarget",
                    "fieldsByEntityType",
                ]) || {};
            const allowBag =
                this.view.getMetadata().get([
                    "app",
                    "journeyUpdateTarget",
                    "allowCustomFieldsBag",
                ]) !== false;
            const set = {};

            if (entityType && byType[entityType]) {
                (byType[entityType] || []).forEach((f) => {
                    set[f] = true;
                });
            } else {
                Object.keys(byType).forEach((et) => {
                    (byType[et] || []).forEach((f) => {
                        set[f] = true;
                    });
                });
            }

            const native = Object.keys(set)
                .sort()
                .map((f) => ({
                    value: f,
                    label: f,
                    isCustomField: false,
                }));

            if (!allowBag || !entityType || !this.isEntityEnabled(entityType)) {
                return Promise.resolve(native);
            }

            return this.loadCustomFieldOptions(entityType, tenantId).then((cf) => {
                return native.concat(
                    cf.map((o) => ({
                        value: o.value,
                        label: o.label,
                        isCustomField: true,
                    }))
                );
            });
        },

        whereOperators: function () {
            return [
                "equals",
                "notEquals",
                "contains",
                "notContains",
                "startsWith",
                "endsWith",
                "in",
                "greaterThan",
                "greaterThanOrEquals",
                "lessThan",
                "lessThanOrEquals",
                "isTrue",
                "isFalse",
                "isNull",
                "isNotNull",
            ];
        },

        operatorNeedsValue: function (op) {
            return (
                [
                    "isTrue",
                    "isFalse",
                    "isNull",
                    "isNotNull",
                    "exists",
                    "notExists",
                ].indexOf(op) === -1
            );
        },

        parseInValue: function (raw) {
            const s = String(raw || "").trim();

            if (!s) {
                return [];
            }

            if (s.charAt(0) === "[") {
                try {
                    const parsed = JSON.parse(s);

                    if (Array.isArray(parsed)) {
                        return parsed;
                    }
                } catch (e) {
                    // fall through
                }
            }

            return s.split(",").map((p) => p.trim()).filter((p) => p !== "");
        },

        coerceValue: function (raw, op) {
            if (!this.operatorNeedsValue(op)) {
                return undefined;
            }

            if (op === "in" || op === "notIn") {
                return this.parseInValue(raw);
            }

            const s = String(raw ?? "");

            if (s === "true") {
                return true;
            }

            if (s === "false") {
                return false;
            }

            if (s !== "" && !isNaN(Number(s)) && /^-?\d+(\.\d+)?$/.test(s)) {
                return Number(s);
            }

            return s;
        },
    });

    return Helper;
});
