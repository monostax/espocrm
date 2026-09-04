/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import HistoryPanelView from "crm:views/record/panels/history";
import withChatwootConversation from "chatwoot:helpers/activities/with-chatwoot-conversation";

/**
 * "Atividades (Realizadas)" panel for a ChatwootConversation record.
 *
 * Used by the Chatwoot "Atividades" dashboard app tab.
 */
class ChatwootConversationHistoryPanelView extends withChatwootConversation(
    HistoryPanelView,
) {}

export default ChatwootConversationHistoryPanelView;
