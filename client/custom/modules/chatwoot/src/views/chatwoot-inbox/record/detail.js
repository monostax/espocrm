define(
    "chatwoot:views/chatwoot-inbox/record/detail",
    ["global:views/record/detail", "global:helpers/type-confirmation-dialog"],
    function (Dep, TypeConfirmationDialog) {
        return Dep.extend({
            createMiddleView: function (callback) {
                const el = this.getSelector() || "#" + this.id;

                this.waitForView("middle");

                this.getGridLayout((layout) => {
                    const forcedTabIndex = this.getForcedChannelConnectionTabIndex(layout);

                    if (
                        this.hasTabs() &&
                        this.options.isReturn &&
                        forcedTabIndex === null &&
                        this.isStoredTabForThisRecord()
                    ) {
                        this.selectStoredTab();
                    }

                    if (forcedTabIndex !== null) {
                        this.currentTab = forcedTabIndex;
                    }

                    this.createView(
                        "middle",
                        this.middleView,
                        {
                            model: this.model,
                            scope: this.scope,
                            type: this.type,
                            layoutDefs: layout,
                            fullSelector: el + " .middle",
                            layoutData: {
                                model: this.model,
                                hiddenPanels: this.recordHelper.getHiddenPanels(),
                                collapsedPanels: {},
                            },
                            recordHelper: this.recordHelper,
                            recordViewObject: this,
                            panelFieldListMap: this.panelFieldListMap,
                        },
                        callback
                    );
                });
            },

            afterRender: function () {
                Dep.prototype.afterRender.call(this);

                const currentChannelType = this.model.get("channelType");

                if (this.shouldOpenChannelConnectionTab(currentChannelType)) {
                    this.redirectToChannelConnectionTab();

                    return;
                }

                if (currentChannelType) {
                    return;
                }

                const integrationId = this.model.get("chatwootInboxIntegrationId");

                if (!integrationId) {
                    return;
                }

                Espo.Ajax.getRequest(`ChatwootInboxIntegration/${integrationId}`, {
                    select: "channelType,status",
                })
                    .then((data) => {
                        const integrationChannelType = data && data.channelType;

                        if (this.shouldOpenChannelConnectionTab(integrationChannelType, data && data.status)) {
                            this.redirectToChannelConnectionTab();
                        }
                    })
                    .catch(() => {});
            },

            getForcedChannelConnectionTabIndex: function (layout) {
                if (!this.shouldOpenChannelConnectionTab(this.model.get("channelType"))) {
                    return null;
                }

                return this.getTabIndexByPanelName(layout, "channelConnectionTab");
            },

            isQrCodeIntegration: function (channelTypeValue) {
                const channelType = (channelTypeValue || "").toLowerCase();

                return channelType.includes("whatsapp") && channelType.includes("qrcode");
            },

            isCoexistenceIntegration: function (channelTypeValue) {
                const channelType = (channelTypeValue || "").toLowerCase();

                return channelType === "whatsappcoexistence";
            },

            shouldOpenChannelConnectionTab: function (channelTypeValue, statusValue) {
                if (this.isQrCodeIntegration(channelTypeValue)) {
                    return true;
                }

                if (!this.isCoexistenceIntegration(channelTypeValue)) {
                    return false;
                }

                // Only jump while Meta handshake or companion QR is in flight.
                // ACTIVE coexistence keeps Overview as default; button still links.
                const status = statusValue || this.model.get("status");

                return [
                    "PENDING_COEXISTENCE_CONFIRMATION",
                    "PENDING_WAHA_LINK",
                ].includes(status);
            },

            redirectToChannelConnectionTabForQrCode: function () {
                this.redirectToChannelConnectionTab();
            },

            getTabIndexByPanelName: function (layout, panelName) {
                if (!Array.isArray(layout)) {
                    return null;
                }

                let tabIndex = 0;

                for (let i = 0; i < layout.length; i++) {
                    const panel = layout[i] || {};

                    if (i > 0 && panel.tabBreak) {
                        tabIndex++;
                    }

                    if (panel.name === panelName) {
                        return tabIndex;
                    }
                }

                return null;
            },

            redirectToChannelConnectionTab: function () {
                if (!this.hasTabs()) {
                    return;
                }

                const middleView = this.getMiddleView();

                if (!middleView) {
                    return;
                }

                const applyTabRedirect = () => {
                    if (!Array.isArray(middleView.panelList)) {
                        return;
                    }

                    const channelConnectionPanel = middleView.panelList.find(
                        (panel) => panel.name === "channelConnectionTab"
                    );

                    if (
                        !channelConnectionPanel ||
                        typeof channelConnectionPanel.tabNumber !== "number"
                    ) {
                        return;
                    }

                    if (this.currentTab !== channelConnectionPanel.tabNumber) {
                        this.selectTab(channelConnectionPanel.tabNumber);
                    }
                };

                if (typeof middleView.onPanelsReady === "function") {
                    middleView.onPanelsReady(applyTabRedirect);

                    return;
                }

                applyTabRedirect();
            },

            delete: async function () {
                const config = this.getTypeDeleteConfirmationConfig();
                const confirmationValue = (this.model.get("name") || "").trim() || config.expectedValue;

                if (!config.enabled || !config.actions.detail) {
                    return Dep.prototype.delete.call(this);
                }

                try {
                    await TypeConfirmationDialog.confirm(this, {
                        message: this.translate(
                            "removeRecordConfirmation",
                            "messages",
                            this.scope
                        ),
                        instruction: this.translate(
                            "typeToConfirmValueInstruction",
                            "messages",
                            this.scope
                        ).replace("{value}", confirmationValue),
                        expectedValue: confirmationValue,
                        confirmText: this.translate("Remove"),
                    });
                } catch (e) {
                    return;
                }

                const originalConfirm = this.confirm;

                this.confirm = function (o, callback, context) {
                    if (callback) {
                        if (context) {
                            callback.call(context);
                        } else {
                            callback();
                        }
                    }

                    return Promise.resolve();
                };

                try {
                    return Dep.prototype.delete.call(this);
                } finally {
                    this.confirm = originalConfirm;
                }
            },

            getTypeDeleteConfirmationConfig: function () {
                const defs = this.getMetadata().get([
                    "clientDefs",
                    this.scope,
                    "typeDeleteConfirmation",
                ]) || {};

                const expectedValue = (defs.expectedValue || "DELETE").trim() || "DELETE";

                return {
                    enabled: defs.enabled !== false,
                    expectedValue: expectedValue,
                    actions: {
                        detail:
                            !defs.actions ||
                            defs.actions.detail === undefined ||
                            defs.actions.detail === true,
                    },
                };
            },
        });
    }
);
