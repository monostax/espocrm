/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import Controller from "controller";

/**
 * Controller for the ChatwootConversationBridge route.
 *
 * This controller is used when EspoCRM is embedded inside a Chatwoot
 * dashboard app iframe. It renders a bridge view that listens for
 * postMessage events from Chatwoot, looks up the ChatwootConversation
 * entity by external chatwootConversationId, and renders its detail view.
 */
class ChatwootConversationBridgeController extends Controller {
    actionIndex() {
        this.main("chatwoot:views/chatwoot-conversation/bridge", {});
    }
}

export default ChatwootConversationBridgeController;
