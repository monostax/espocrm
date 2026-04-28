/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import ListRecordView from "views/record/list";

/**
 * List record view for ChatwootConversation that ensures attributes
 * required by the custom name field (inbox channel icon, drawer) are
 * always fetched, even when they are not part of the visible list layout.
 */
class ChatwootConversationListRecordView extends ListRecordView {
    mandatorySelectAttributeList = [
        "inboxChannelType",
        "chatwootConversationId",
        "chatwootAccountIdExternal",
        "contactDisplayName",
        "inboxName",
    ];
}

export default ChatwootConversationListRecordView;
