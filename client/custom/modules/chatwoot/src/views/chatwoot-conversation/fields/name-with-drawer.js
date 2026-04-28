/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import VarcharFieldView from "views/fields/varchar";

const CHANNEL_ICON_MAP = {
    whatsappQrcode: "whatsapp.svg",
    whatsappCloudApi: "whatsapp.svg",
    instagram: "instagram.svg",
    telegram: "telegram.svg",
    messenger: "messenger.svg",
};

class NameWithDrawerFieldView extends VarcharFieldView {
    listTemplate = "chatwoot:chatwoot-conversation/fields/name-with-drawer/list";
    listLinkTemplate = "chatwoot:chatwoot-conversation/fields/name-with-drawer/list";
    detailTemplate = "chatwoot:chatwoot-conversation/fields/name-with-drawer/detail";

    events = {
        "click [data-action=\"openDrawer\"]": function (e) {
            this.onOpenDrawer(e);
        },
    };

    data() {
        const data = super.data();

        data.channelIconUrl = this.getChannelIconUrl();
        data.channelType = this.model.get("inboxChannelType") || "";

        return data;
    }

    getChannelIconUrl() {
        const channelType = this.model.get("inboxChannelType") || "";
        const iconFile = this.resolveIconFile(channelType);

        if (!iconFile) {
            return null;
        }

        return `${this.getBasePath()}client/custom/modules/global/res/icons/${iconFile}`;
    }

    resolveIconFile(value) {
        if (!value) {
            return null;
        }

        if (CHANNEL_ICON_MAP[value]) {
            return CHANNEL_ICON_MAP[value];
        }

        const normalized = String(value).toLowerCase();

        for (const [key, file] of Object.entries(CHANNEL_ICON_MAP)) {
            if (normalized.includes(key.toLowerCase())) {
                return file;
            }
        }

        if (normalized.includes("whatsapp") || normalized.includes("waha")) {
            return "whatsapp.svg";
        }

        return null;
    }

    async onOpenDrawer(e) {
        e.preventDefault();
        e.stopPropagation();

        // Fetch the full record if conversation ID isn't loaded (list views
        // only load attributes declared in the list layout).
        if (!this.model.has("chatwootConversationId")) {
            try {
                await this.fetchFullModel();
            } catch (err) {
                console.error("Failed to fetch ChatwootConversation record:", err);

                return;
            }
        }

        this.createView(
            "conversationDrawer",
            "chatwoot:views/chatwoot-conversation/modals/conversation-drawer",
            {
                chatwootConversationId: this.model.get("chatwootConversationId"),
                chatwootAccountIdExternal: this.model.get(
                    "chatwootAccountIdExternal",
                ),
                contactName:
                    this.model.get("contactDisplayName") ||
                    this.model.get("name"),
                inboxName: this.model.get("inboxName"),
                recordId: this.model.id,
            },
            (view) => {
                view.render();

                this.listenToOnce(view, "close", () => {
                    this.model.fetch();
                });
            },
        );
    }

    async fetchFullModel() {
        const Model = this.model.constructor;
        const fullModel = new Model();

        fullModel.urlRoot = this.model.urlRoot || this.model.entityType;
        fullModel.entityType = this.model.entityType;
        fullModel.id = this.model.id;

        await fullModel.fetch();

        this.model.set(fullModel.attributes, { silent: true });
    }
}

export default NameWithDrawerFieldView;
