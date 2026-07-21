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
 * Adds tenant custom-field placeholders to the Email Template Insert Field picker.
 *
 * For every entity in app.customFields.entityTypeList (Contact, Lead, Account,
 * Opportunity, …) that the user can read:
 *   - Host:    {Contact.customFields.campaign__fast_victory.nomeClinica}
 *   - Person:  {Person.customFields....}  (mirrored from Contact/Lead)
 *   - Related: {Contact.account.customFields....}  (belongsTo one-hop)
 *
 * Loads `CustomField/action/templateVariables` per enabled entity. The API
 * unions schema across the user's accessible tenants when no single tenant
 * is in context (Email Template has no host record).
 */
define("global:views/email-template/fields/insert-field", [
	"views/email-template/fields/insert-field",
], (Dep) =>
	class extends Dep {
		/**
		 * @type {Record<string, Array>}
		 */
		customFieldPlaceholders = {};

		setup() {
			super.setup();

			if (this.mode === this.MODE_LIST) {
				return;
			}

			// Core setup builds entityFields from metadata attributes only.
			// Wait until CF defs are injected before the field <select> is useful.
			this.wait(this.loadCustomFieldPlaceholders());
		}

		/**
		 * @return {Promise}
		 */
		loadCustomFieldPlaceholders() {
			const enabled =
				this.getMetadata().get(["app", "customFields", "entityTypeList"]) || [];
			const scopes = Array.isArray(enabled) ? enabled.slice() : [];

			if (!scopes.length) {
				return Promise.resolve();
			}

			const attrName =
				this.getMetadata().get(["app", "customFields", "attributeName"]) ||
				"customFields";

			this.customFieldPlaceholders = {};

			const promises = scopes.map((entityType) => {
				if (!this.getAcl().checkScope(entityType)) {
					return Promise.resolve();
				}

				// Ensure a bucket exists even if core filtered the scope out
				// (ACL race / exotic scope flags). Insertion still needs a home.
				this.ensureEntityBucket(entityType);

				const url =
					"CustomField/action/templateVariables?entityType=" +
					encodeURIComponent(entityType);

				return Espo.Ajax.getRequest(url)
					.then((response) => {
						const list = (response && response.list) || [];

						this.customFieldPlaceholders[entityType] = list;

						if (!list.length) {
							return;
						}

						this.injectHostCustomFields(entityType, list, attrName);

						// Person is Espo's catch-all for Contact/Lead person-name
						// targets. Mirror CF leaves so {Person.customFields.*} can
						// be inserted the same way native person fields are.
						if (entityType === "Contact" || entityType === "Lead") {
							this.injectHostCustomFields("Person", list, attrName);
						}
					})
					.catch((err) => {
						console.warn(
							"email-template insert-field: failed templateVariables for " +
								entityType,
							err,
						);
					});
			});

			return Promise.all(promises).then(() => {
				this.injectRelatedCustomFields(scopes, attrName);
				this.refreshFieldSelect();
			});
		}

		/**
		 * Guarantee entityFields / translatedOptions blobs for a scope.
		 *
		 * @param {string} entityType
		 */
		ensureEntityBucket(entityType) {
			if (!this.entityFields) {
				this.entityFields = {};
			}

			if (!this.translatedOptions) {
				this.translatedOptions = {};
			}

			if (!this.entityFields[entityType]) {
				this.entityFields[entityType] = [];
			}

			if (!this.translatedOptions[entityType]) {
				this.translatedOptions[entityType] = {};
			}

			// Keep the entity <select> in sync if core did not include the scope.
			if (
				entityType !== "Person" &&
				Array.isArray(this.entityList) &&
				!this.entityList.includes(entityType)
			) {
				this.entityList.push(entityType);
			}
		}

		/**
		 * @param {string} entityType
		 * @param {Array} list
		 * @param {string} attrName
		 */
		injectHostCustomFields(entityType, list, attrName) {
			this.ensureEntityBucket(entityType);

			list.forEach((item) => {
				if (!item || !item.valueKey) {
					return;
				}

				const field = attrName + "." + item.valueKey;
				const group = item.groupLabel ? item.groupLabel + " · " : "";
				const label =
					"Custom Fields · " + group + (item.label || item.valueKey);

				if (!this.entityFields[entityType].includes(field)) {
					this.entityFields[entityType].push(field);
				}

				this.translatedOptions[entityType][field] = label;
			});
		}

		/**
		 * One-hop belongsTo: Contact + account → Account CF defs.
		 *
		 * @param {string[]} enabledScopes
		 * @param {string} attrName
		 */
		injectRelatedCustomFields(enabledScopes, attrName) {
			if (!this.entityFields) {
				return;
			}

			// Walk every scope already in the picker (plus enabled CF hosts).
			const scopes = new Set([
				...Object.keys(this.entityFields),
				...enabledScopes,
			]);

			scopes.forEach((scope) => {
				if (scope === "Person") {
					return;
				}

				this.ensureEntityBucket(scope);

				/** @type {Record<string, Record>} */
				const links = this.getMetadata().get(`entityDefs.${scope}.links`) || {};

				Object.keys(links).forEach((link) => {
					const linkDefs = links[link] || {};

					if (linkDefs.type !== "belongsTo") {
						return;
					}

					const foreignScope = linkDefs.entity;

					if (!foreignScope || !enabledScopes.includes(foreignScope)) {
						return;
					}

					if (linkDefs.disabled || linkDefs.utility) {
						return;
					}

					if (
						this.getMetadata().get([
							"entityAcl",
							scope,
							"links",
							link,
							"onlyAdmin",
						]) ||
						this.getMetadata().get([
							"entityAcl",
							scope,
							"links",
							link,
							"forbidden",
						]) ||
						this.getMetadata().get([
							"entityAcl",
							scope,
							"links",
							link,
							"internal",
						])
					) {
						return;
					}

					if (!this.getAcl().checkScope(foreignScope)) {
						return;
					}

					const list = this.customFieldPlaceholders[foreignScope];

					if (!list || !list.length) {
						return;
					}

					const linkLabel = this.translate(link, "links", scope);

					list.forEach((item) => {
						if (!item || !item.valueKey) {
							return;
						}

						const field = link + "." + attrName + "." + item.valueKey;
						const group = item.groupLabel ? item.groupLabel + " · " : "";
						const label =
							linkLabel +
							" · Custom Fields · " +
							group +
							(item.label || item.valueKey);

						if (!this.entityFields[scope].includes(field)) {
							this.entityFields[scope].push(field);
						}

						this.translatedOptions[scope][field] = label;
					});
				});
			});
		}

		/**
		 * Re-render the field <select> after async CF injection so the user
		 * sees Custom Fields immediately on the currently selected entity.
		 */
		refreshFieldSelect() {
			if (!this.$field || !this.$entityType) {
				return;
			}

			if (typeof this.changeEntityType === "function") {
				this.changeEntityType();
			}
		}

		afterRender() {
			super.afterRender();

			// Core afterRender builds the entity/field selects. If CF loaded
			// before render, inject again is a no-op on duplicates; if CF is
			// already on entityFields, refresh so options include them.
			if (this.mode === this.MODE_EDIT) {
				this.refreshFieldSelect();
			}
		}
	});
