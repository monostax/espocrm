/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

/**
 * Mixin applied to the core Activities / History panels so they work for a
 * ChatwootConversation record (used by the Chatwoot "Atividades" tab).
 *
 * Adds two behaviours on top of the core panel:
 *
 * 1. Link-based create for activity scopes that have no `activityDefs.link`
 *    and cannot be a `parent` of the conversation (Appointment). The record
 *    is related through the many-to-many `chatwootConversations` link.
 *
 * 2. Pre-fills the created record with the conversation's Contact and Teams,
 *    mirroring `chatwoot:handlers/create-related/set-contact`:
 *      - Task              -> `contact`   (link)
 *      - Call, Meeting     -> `contacts`  (linkMultiple)
 *      - Appointment       -> `customer`  (link)
 *
 * @template {typeof import('crm:views/record/panels/activities').default} T
 * @param {T} Base
 * @return {T}
 */
export default function withChatwootConversation(Base) {
    return class extends Base {
        /**
         * Activity scope -> ChatwootConversation link, for scopes the core
         * panel cannot offer a create action for.
         *
         * @type {Object.<string, string>}
         */
        extraLinkMap = {
            Appointment: "appointments",
        };

        setupActionList() {
            super.setupActionList();

            Object.entries(this.extraLinkMap).forEach(([scope, link]) => {
                this.addLinkCreateAction(scope, link);
            });
        }

        /**
         * @param {string} scope
         * @param {string} link
         */
        addLinkCreateAction(scope, link) {
            if (
                !this.scopeList.includes(scope) ||
                this.createAvailabilityHash[scope] ||
                !this.model.hasLink(link) ||
                !this.getMetadata().get([
                    "clientDefs",
                    scope,
                    "activityDefs",
                    this.name + "Create",
                ]) ||
                !this.getAcl().checkScope(scope, "create")
            ) {
                return;
            }

            const label =
                (this.name === "history" ? "Log" : "Schedule") + " " + scope;

            const statusList =
                this.getMetadata().get([
                    "scopes",
                    scope,
                    this.name + "StatusList",
                ]) || [];

            const o = {
                action: "createActivity",
                text: this.translate(label, "labels", scope),
                data: {
                    link,
                    status: statusList[0],
                },
                acl: "create",
                aclScope: scope,
                iconClass: "fas fa-plus",
            };

            this.entityTypeLinkMap[scope] = link;
            this.createAvailabilityHash[scope] = true;
            this.createEntityTypeStatusMap[scope] = o.data.status;

            this.actionList.push(o);

            if (
                this.name === "activities" &&
                this.buttonList.length < this.buttonMaxCount
            ) {
                const iconClass = this.getMetadata().get([
                    "clientDefs",
                    scope,
                    "iconClass",
                ]);

                if (iconClass) {
                    this.buttonList.push({
                        ...Espo.Utils.cloneDeep(o),
                        title: label,
                        html: $("<span>").addClass(iconClass).get(0).outerHTML,
                    });
                }
            }
        }

        getCreateActivityAttributes(scope, data, callback) {
            super.getCreateActivityAttributes(scope, data, (attributes) => {
                callback.call(this, {
                    ...attributes,
                    ...this.getConversationAttributes(scope),
                });
            });
        }

        /**
         * @param {string} scope
         * @return {Object.<string, *>}
         */
        getConversationAttributes(scope) {
            const attributes = {};

            const teamsIds = this.model.get("teamsIds");

            if (teamsIds && teamsIds.length) {
                attributes.teamsIds = teamsIds;
                attributes.teamsNames = this.model.get("teamsNames") || {};
            }

            const contactId = this.model.get("contactId");

            if (!contactId) {
                return attributes;
            }

            const contactName = this.model.get("contactName") || null;

            switch (scope) {
                case "Task":
                    attributes.contactId = contactId;
                    attributes.contactName = contactName;
                    break;
                case "Call":
                case "Meeting":
                    attributes.contactsIds = [contactId];
                    attributes.contactsNames = { [contactId]: contactName };
                    break;
                case "Appointment":
                    attributes.customerId = contactId;
                    attributes.customerName = contactName;
                    break;
            }

            return attributes;
        }
    };
}
