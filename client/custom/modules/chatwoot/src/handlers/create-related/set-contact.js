/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import CreateRelatedHandler from "handlers/create-related";

/**
 * Pre-populates the contact field of a record created from a
 * ChatwootConversation relationship panel using the conversation's
 * related Contact.
 *
 * The ChatwootConversation itself is linked automatically by the
 * relationship-panel create flow (via the foreign link), so this
 * handler only handles the contact.
 *
 * Target contact field differs per entity:
 *   - Opportunity, Task  -> `contact`   (belongsTo / link)
 *   - Call, Meeting      -> `contacts`  (hasMany / linkMultiple)
 *   - Appointment        -> `customer`  (belongsTo / link)
 */
export default class SetContactHandler extends CreateRelatedHandler {
    async getAttributes(model, link) {
        const contactId = model.get("contactId");

        if (!contactId) {
            return {};
        }

        const contactName = model.get("contactName") || null;
        const entityType = model.getLinkParam(link, "entity");

        switch (entityType) {
            case "Opportunity":
            case "Task":
                return {
                    contactId,
                    contactName,
                };
            case "Call":
            case "Meeting":
                return {
                    contactsIds: [contactId],
                    contactsNames: { [contactId]: contactName },
                };
            case "Appointment":
                return {
                    customerId: contactId,
                    customerName: contactName,
                };
            default:
                return {};
        }
    }
}
