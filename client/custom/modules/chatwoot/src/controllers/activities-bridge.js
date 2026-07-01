/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import Controller from "controller";

/**
 * Controller for the ChatwootActivitiesBridge route.
 *
 * Renders a bridge view that listens for Chatwoot postMessage,
 * resolves the ChatwootConversation entity, and renders its
 * Appointment / Meeting / Task relationship panels.
 */
class ActivitiesBridgeController extends Controller {
    actionIndex() {
        this.main("chatwoot:views/activities/bridge", {});
    }
}

export default ActivitiesBridgeController;
