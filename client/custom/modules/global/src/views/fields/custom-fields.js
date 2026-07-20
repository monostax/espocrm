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
 * Schema-driven custom fields view.
 *
 * Loads CustomFieldDef meta for (entityType, tenantId) and renders typed
 * inputs grouped into panels. Values are read/written on the host model's
 * jsonObject attribute (default: customFields). Access control is fully
 * inherited from the host record — no separate CustomFieldDef permission
 * is required to edit values.
 *
 * Template variables use the immutable valueKey:
 *   {{customFields.address.city}}  /  {{customFields.plan}}
 */
define("global:views/fields/custom-fields", ["views/fields/base"], (Dep) =>
	Dep.extend({
		// language=Handlebars
		detailTemplateContent:
			"{{#if isLoading}}" +
			'<span class="text-muted">…</span>' +
			"{{else if isEmpty}}" +
			'<span class="none-value">{{translate "None"}}</span>' +
			"{{else}}" +
			'<div class="custom-fields-detail">' +
			"{{#each groups}}" +
			'<div class="custom-fields-group" data-group="{{name}}" style="margin-bottom: 14px;">' +
			"{{#unless isGeneral}}" +
			'<div class="custom-fields-group-title" style="font-weight: 600; margin-bottom: 6px;">' +
			'{{#if iconClass}}<span class="{{iconClass}}" style="margin-right: 6px;"></span>{{/if}}' +
			"{{label}}" +
			"</div>" +
			"{{/unless}}" +
			'<div class="custom-fields-group-body">' +
			"{{#each fields}}" +
			'<div class="row" style="margin-bottom: 4px;">' +
			'<div class="cell col-sm-4 col-xs-12">' +
			'<label class="control-label text-muted">{{label}}</label>' +
			"</div>" +
			'<div class="cell col-sm-8 col-xs-12">' +
			"{{{displayValue}}}" +
			"</div>" +
			"</div>" +
			"{{/each}}" +
			"</div>" +
			"</div>" +
			"{{/each}}" +
			"</div>" +
			"{{/if}}",

		// language=Handlebars
		editTemplateContent:
			"{{#if isLoading}}" +
			'<span class="text-muted">…</span>' +
			"{{else if isEmpty}}" +
			'<span class="text-muted">{{emptyMessage}}</span>' +
			"{{else}}" +
			'<div class="custom-fields-edit">' +
			"{{#each groups}}" +
			'<div class="custom-fields-group panel panel-default" data-group="{{name}}" style="margin-bottom: 10px;">' +
			"{{#unless isGeneral}}" +
			'<div class="panel-heading">' +
			'{{#if iconClass}}<span class="{{iconClass}}" style="margin-right: 6px;"></span>{{/if}}' +
			'<span class="panel-title">{{label}}</span>' +
			"</div>" +
			"{{/unless}}" +
			'<div class="panel-body" style="padding-bottom: 4px;">' +
			"{{#each fields}}" +
			'<div class="form-group" data-value-key="{{valueKey}}">' +
			'<label class="control-label">' +
			"{{label}}" +
			'{{#if isRequired}} <span class="required-sign">*</span>{{/if}}' +
			"</label>" +
			'<div class="field" data-name="{{inputName}}"></div>' +
			"</div>" +
			"{{/each}}" +
			"</div>" +
			"</div>" +
			"{{/each}}" +
			"</div>" +
			"{{/if}}",

		// language=Handlebars
		listTemplateContent:
			"{{#if isNotEmpty}}" +
			'<span class="text-muted" title="{{summaryTitle}}">{{summary}}</span>' +
			"{{else}}" +
			'<span class="none-value">{{translate "None"}}</span>' +
			"{{/if}}",

		meta: null,
		isLoading: false,
		_shadowModel: null,
		_fieldViewKeys: null,

		setup: function () {
			Dep.prototype.setup.call(this);

			this.meta = null;
			this.isLoading = false;
			this._shadowModel = null;
			this._fieldViewKeys = [];

			this.validations = Espo.Utils.clone(this.validations || []);
			if (this.validations.indexOf("customFieldsRequired") === -1) {
				this.validations.push("customFieldsRequired");
			}

			this.listenTo(
				this.model,
				"change:tenantId change:teamsIds",
				function () {
					this.loadMeta().then(
						function () {
							if (this.isRendered()) {
								this.reRender();
							}
						}.bind(this),
					);
				}.bind(this),
			);

			this.wait(this.loadMeta());
		},

		/**
		 * @returns {Promise}
		 */
		loadMeta: function () {
			var entityType = this.model.entityType || this.model.name;
			var tenantId = this.model.get("tenantId") || null;
			var teamIds = this.model.get("teamsIds") || [];
			var recordId = this.model.id || null;

			this.isLoading = true;

			var url =
				"CustomField/action/meta?entityType=" + encodeURIComponent(entityType);

			// Prefer explicit tenantId (Contact/Account). Otherwise pass teams /
			// recordId so the server can resolve Tenant via baseUserTeam / otherUserTeams.
			if (tenantId) {
				url += "&tenantId=" + encodeURIComponent(tenantId);
			} else {
				if (Array.isArray(teamIds) && teamIds.length) {
					url +=
						"&teamIds=" +
						encodeURIComponent(
							teamIds
								.filter(function (id) {
									return !!id;
								})
								.join(","),
						);
				}

				if (recordId) {
					url += "&recordId=" + encodeURIComponent(recordId);
				}
			}

			return Espo.Ajax.getRequest(url)
				.then(
					function (response) {
						this.meta = response || { groups: [] };
						this.isLoading = false;
					}.bind(this),
				)
				.catch(
					function (err) {
						console.warn("custom-fields: failed to load meta", err);
						this.meta = { groups: [] };
						this.isLoading = false;
					}.bind(this),
				);
		},

		/**
		 * Flatten bag → plain object keyed by valueKey.
		 * @returns {Object.<string, *>}
		 */
		getValues: function () {
			var raw = this.model.get(this.name);

			if (!raw) {
				return {};
			}

			if (typeof raw === "string") {
				try {
					raw = JSON.parse(raw);
				} catch (e) {
					return {};
				}
			}

			if (typeof raw === "object" && raw !== null && !Array.isArray(raw)) {
				return Espo.Utils.clone(raw);
			}

			return {};
		},

		/**
		 * @returns {Array}
		 */
		getFlatFields: function () {
			if (!this.meta || !Array.isArray(this.meta.groups)) {
				return [];
			}

			var fields = [];

			this.meta.groups.forEach((group) => {
				(group.fields || []).forEach((field) => {
					fields.push(field);
				});
			});

			return fields;
		},

		data: function () {
			var data = Dep.prototype.data.call(this);
			var values = this.getValues();
			var groups = this.meta && this.meta.groups ? this.meta.groups : [];

			data.isLoading = this.isLoading;
			data.isEmpty = !this.isLoading && groups.length === 0;
			data.emptyMessage =
				this.translate("noCustomFieldsDefined", "messages", "Global") ||
				"No custom fields defined for this tenant.";
			data.isNotEmpty = Object.keys(values).length > 0;

			var keys = Object.keys(values);
			data.summary = keys.length + " field" + (keys.length !== 1 ? "s" : "");
			data.summaryTitle = keys.join(", ");

			data.groups = groups.map(
				function (group) {
					return {
						name: group.name,
						label: group.label,
						iconClass: group.iconClass,
						isGeneral: group.name === "_general",
						fields: (group.fields || []).map(
							function (field) {
							var value = Object.prototype.hasOwnProperty.call(
								values,
								field.valueKey,
							)
								? values[field.valueKey]
								: field.defaultValue;

								return {
									valueKey: field.valueKey,
									label: field.label,
									type: field.type,
									isRequired: !!field.isRequired,
									inputName: this.toInputName(field.valueKey),
									displayValue: this.formatDisplayValue(field, value),
								};
							}.bind(this),
						),
					};
				}.bind(this),
			);

			return data;
		},

		toInputName: (valueKey) => String(valueKey).replace(/\./g, "__"),

		formatDisplayValue: function (field, value) {
			if (value === undefined || value === null || value === "") {
				return '<span class="text-muted">&mdash;</span>';
			}

			var escape = this.getHelper().escapeString.bind(this.getHelper());

			switch (field.type) {
				case "bool":
					return value === true ||
						value === "true" ||
						value === 1 ||
						value === "1"
						? this.translate("Yes")
						: this.translate("No");

				case "multiEnum":
					if (Array.isArray(value)) {
						return escape(value.join(", "));
					}
					return escape(String(value));

				case "text":
					return (
						'<span style="white-space: pre-wrap;">' +
						escape(String(value)) +
						"</span>"
					);

				default:
					return escape(String(value));
			}
		},

		afterRender: function () {
			if (
				this.isEditMode() &&
				!this.isLoading &&
				this.meta &&
				this.meta.groups &&
				this.meta.groups.length
			) {
				this.renderEditFields();
			}
		},

		/**
		 * Spawn real Espo field views on a shadow model, one per def.
		 */
		renderEditFields: function () {
			this.clearFieldViews();

			var values = this.getValues();
			var fields = this.getFlatFields();
			var fieldDefs = {};
			var attrs = {};

			fields.forEach(
				function (field) {
					var inputName = this.toInputName(field.valueKey);
					fieldDefs[inputName] = this.toEspoFieldDefs(field);

					if (Object.prototype.hasOwnProperty.call(values, field.valueKey)) {
						attrs[inputName] = values[field.valueKey];
					} else if (
						field.defaultValue !== undefined &&
						field.defaultValue !== null &&
						field.defaultValue !== ""
					) {
						attrs[inputName] = this.coerceDefault(field);
					}
				}.bind(this),
			);

			var entityType = this.model.entityType || this.model.name;

			this.getModelFactory().create(
				entityType,
				function (model) {
					// Override defs with our dynamic custom-field defs only.
					model.defs = { fields: fieldDefs };
					model.set(attrs, { silent: true });

					this._shadowModel = model;

					this.listenTo(
						this._shadowModel,
						"change",
						function () {
							this.trigger("change", { ui: true });
						}.bind(this),
					);

					fields.forEach(
						function (field) {
							var inputName = this.toInputName(field.valueKey);
							var viewName = this.viewNameForType(field.type);
							var key = "cf-" + inputName;

							this._fieldViewKeys.push(key);

							this.createView(
								key,
								viewName,
								{
									name: inputName,
									model: this._shadowModel,
									mode: "edit",
									readOnly: false,
									defs: {
										name: inputName,
										params: fieldDefs[inputName],
									},
									el:
										this.getSelector() +
										' .field[data-name="' +
										inputName +
										'"]',
								},
								(view) => {
									view.render();
								},
							);
						}.bind(this),
					);
				}.bind(this),
			);
		},

		clearFieldViews: function () {
			(this._fieldViewKeys || []).forEach(
				function (key) {
					this.clearView(key);
				}.bind(this),
			);

			this._fieldViewKeys = [];
			this._shadowModel = null;
		},

		viewNameForType: (type) => {
			var map = {
				varchar: "views/fields/varchar",
				text: "views/fields/text",
				int: "views/fields/int",
				float: "views/fields/float",
				bool: "views/fields/bool",
				date: "views/fields/date",
				datetime: "views/fields/datetime",
				enum: "views/fields/enum",
				multiEnum: "views/fields/multi-enum",
			};

			return map[type] || "views/fields/varchar";
		},

		/**
		 * @returns {Object}
		 */
		toEspoFieldDefs: (field) => {
			var defs = {
				type: field.type,
				required: !!field.isRequired,
			};

			if (field.type === "enum" || field.type === "multiEnum") {
				var options = Array.isArray(field.options) ? field.options.slice() : [];

				// Allow clearing optional enums (Espo convention: leading empty option).
				if (field.type === "enum" && options.indexOf("") === -1) {
					options.unshift("");
				}

				defs.options = options;
			}

			if (field.type === "varchar" || field.type === "text") {
				if (field.maxLength) {
					defs.maxLength = field.maxLength;
				}
			}

			if (field.type === "int" || field.type === "float") {
				if (field.min !== null && field.min !== undefined) {
					defs.min = field.min;
				}
				if (field.max !== null && field.max !== undefined) {
					defs.max = field.max;
				}
			}

			return defs;
		},

		coerceDefault: (field) => {
			var v = field.defaultValue;

			if (field.type === "bool") {
				return v === true || v === "true" || v === "1" || v === 1;
			}

			if (field.type === "int") {
				var i = parseInt(v, 10);
				return isNaN(i) ? null : i;
			}

			if (field.type === "float") {
				var f = parseFloat(v);
				return isNaN(f) ? null : f;
			}

			if (field.type === "multiEnum") {
				if (typeof v === "string" && v !== "") {
					return v.split(",").map((s) => s.trim());
				}
				return Array.isArray(v) ? v : [];
			}

			return v;
		},

		/**
		 * Collect values from nested field views + shadow model.
		 * @returns {Object.<string, *>}
		 */
		fetchValues: function () {
			var values = {};
			var fields = this.getFlatFields();

			// Prefer each nested view's fetch (handles multiEnum etc.).
			fields.forEach(
				function (field) {
					var inputName = this.toInputName(field.valueKey);
					var key = "cf-" + inputName;
					var view = this.getView(key);
					var value;

					if (view && typeof view.fetch === "function") {
						var fetched = view.fetch();
						value = fetched ? fetched[inputName] : undefined;
					} else if (this._shadowModel) {
						value = this._shadowModel.get(inputName);
					}

					if (value === undefined) {
						return;
					}

					// Skip empty strings so bag stays lean (except bool false).
					if (value === "" || value === null) {
						return;
					}

					if (Array.isArray(value) && value.length === 0) {
						return;
					}

					values[field.valueKey] = value;
				}.bind(this),
			);

			return values;
		},

		fetch: function () {
			var data = {};
			var values = this.fetchValues();
			var hasAny = Object.keys(values).length > 0;

			// Preserve unknown/orphan keys already on the model (integrations
			// may write extras; admin can prune later).
			var existing = this.getValues();
			var known = {};

			this.getFlatFields().forEach((f) => {
				known[f.valueKey] = true;
			});

			Object.keys(existing).forEach((k) => {
				if (
					!known[k] &&
					!Object.prototype.hasOwnProperty.call(values, k)
				) {
					values[k] = existing[k];
					hasAny = true;
				}
			});

			data[this.name] = hasAny ? values : null;

			return data;
		},

		validateCustomFieldsRequired: function () {
			if (!this.meta) {
				return false;
			}

			var values = this.fetchValues();
			var failed = false;

			this.getFlatFields().forEach(
				function (field) {
					if (!field.isRequired) {
						return;
					}

					var value = values[field.valueKey];
					var empty =
						value === undefined ||
						value === null ||
						value === "" ||
						(Array.isArray(value) && value.length === 0);

					if (empty) {
						failed = true;

						var inputName = this.toInputName(field.valueKey);
						var view = this.getView("cf-" + inputName);

						if (view && typeof view.showValidationMessage === "function") {
							view.showValidationMessage(
								this.translate("required", "messages") || "Required",
							);
						}
					}
				}.bind(this),
			);

			return failed;
		},

		onRemove: function () {
			this.clearFieldViews();
			Dep.prototype.onRemove.call(this);
		},
	}));
