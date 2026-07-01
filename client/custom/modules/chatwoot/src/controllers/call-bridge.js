/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import Controller from "controller";

/**
 * Controller for the CallBridge route.
 *
 * Renders a bridge view that listens for Chatwoot postMessage,
 * resolves the ChatwootConversation entity, and navigates to
 * its related Call list.
 */
class CallBridgeController extends Controller {
    actionIndex() {
        this.main("chatwoot:views/call/bridge", {});
    }
}

export default CallBridgeController;
