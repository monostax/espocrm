/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import ActivitiesPanelsView from "chatwoot:views/activities/panels";

/**
 * Renders only the "Opportunities" relationship panel of a record, using
 * the same panel defs as its detail view (`sidePanels.detail` → name
 * `opportunities`, e.g. `chatwoot:views/activities/relationship-panel`
 * with the `listSmallChatwoot` layout for ChatwootConversation).
 *
 * Used by the Chatwoot "Oportunidades" dashboard app tab.
 */
class OpportunityPanelsView extends ActivitiesPanelsView {
    panelNameList = ["opportunities"];
}

export default OpportunityPanelsView;
