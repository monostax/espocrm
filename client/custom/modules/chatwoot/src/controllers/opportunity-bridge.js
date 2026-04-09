/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import Controller from "controller";

/**
 * Controller for the OpportunityBridge route.
 *
 * Renders a bridge view that listens for Chatwoot postMessage,
 * resolves the ChatwootConversation entity, and navigates to
 * its related Opportunity list.
 */
class OpportunityBridgeController extends Controller {
    actionIndex() {
        this.main("chatwoot:views/opportunity/bridge", {});
    }
}

export default OpportunityBridgeController;
