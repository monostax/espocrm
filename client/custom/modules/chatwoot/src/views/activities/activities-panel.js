/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import ActivitiesPanelView from "crm:views/record/panels/activities";
import withChatwootConversation from "chatwoot:helpers/activities/with-chatwoot-conversation";

/**
 * "Atividades (Planejadas)" panel for a ChatwootConversation record.
 *
 * Used by the Chatwoot "Atividades" dashboard app tab.
 */
class ChatwootConversationActivitiesPanelView extends withChatwootConversation(
    ActivitiesPanelView,
) {}

export default ChatwootConversationActivitiesPanelView;
