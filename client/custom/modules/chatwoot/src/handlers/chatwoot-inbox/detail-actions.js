/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('chatwoot:handlers/chatwoot-inbox/detail-actions', [], function () {

    return class {
        constructor(view) {
            this.view = view;
        }

        // Surfaced on ChatwootInbox detail so coexistence setup is doable from
        // the inbox config view (Channel Connection tab embeds the same flow).
        isLinkWahaCompanionAvailable() {
            const model = this.view.model;

            if (!model.get('chatwootInboxIntegrationId')) {
                return false;
            }

            if (model.get('channelType') !== 'whatsappCoexistence') {
                return false;
            }

            const status = model.get('status');

            return ['ACTIVE', 'PENDING_COEXISTENCE_CONFIRMATION', 'PENDING_WAHA_LINK']
                .includes(status);
        }

        linkWahaCompanion() {
            const model = this.view.model;
            const integrationId = model.get('chatwootInboxIntegrationId');

            if (!integrationId) {
                Espo.Ui.error(
                    this.view.translate(
                        'noChannelConnection',
                        'messages',
                        'ChatwootInbox'
                    ) || 'No channel connection linked to this inbox.'
                );

                return;
            }

            Espo.Ui.notify(
                this.view.translate(
                    'Loading QR Code',
                    'labels',
                    'ChatwootInboxIntegration'
                )
            );

            Espo.Ajax.postRequest(
                `ChatwootInboxIntegration/${integrationId}/linkWahaCompanion`
            )
                .then(response => {
                    Espo.Ui.notify(false);

                    // Mirror integration status onto the inbox foreign fields.
                    if (response && response.status) {
                        model.set('status', response.status);
                    }

                    this.openChannelConnectionTab();
                    this.refreshChannelConnectionDetail();

                    Espo.Ui.success(
                        this.view.translate(
                            'companionLinkStarted',
                            'messages',
                            'ChatwootInbox'
                        ) ||
                        this.view.translate(
                            'Link WhatsApp Companion',
                            'labels',
                            'ChatwootInboxIntegration'
                        )
                    );
                })
                .catch(xhr => {
                    let errorMsg = 'Failed to link WhatsApp companion';

                    if (xhr?.responseJSON?.message) {
                        errorMsg = xhr.responseJSON.message;
                    }

                    Espo.Ui.error(errorMsg);
                });
        }

        openChannelConnectionTab() {
            const detailView = this.view;

            if (typeof detailView.hasTabs !== 'function' || !detailView.hasTabs()) {
                return;
            }

            const middleView = typeof detailView.getMiddleView === 'function'
                ? detailView.getMiddleView()
                : null;

            if (!middleView || !Array.isArray(middleView.panelList)) {
                return;
            }

            const panel = middleView.panelList.find(
                (item) => item && item.name === 'channelConnectionTab'
            );

            if (!panel || typeof panel.tabNumber !== 'number') {
                return;
            }

            if (detailView.currentTab !== panel.tabNumber) {
                detailView.selectTab(panel.tabNumber);
            }
        }

        refreshChannelConnectionDetail() {
            const detailView = this.view;
            let fieldView = null;

            if (typeof detailView.getFieldView === 'function') {
                fieldView = detailView.getFieldView('channelConnectionDetail');
            }

            if (!fieldView) {
                const middleView = typeof detailView.getMiddleView === 'function'
                    ? detailView.getMiddleView()
                    : null;

                if (middleView && Array.isArray(middleView.panelList)) {
                    for (let i = 0; i < middleView.panelList.length; i++) {
                        const panelView = middleView.getView(middleView.panelList[i].name);

                        if (!panelView || typeof panelView.getView !== 'function') {
                            continue;
                        }

                        const candidate = panelView.getView('channelConnectionDetail');

                        if (candidate) {
                            fieldView = candidate;
                            break;
                        }
                    }
                }
            }

            if (fieldView && typeof fieldView.renderLinkedRecord === 'function') {
                fieldView.renderLinkedRecord();

                return;
            }

            if (typeof detailView.model.fetch === 'function') {
                detailView.model.fetch().then(() => detailView.reRender());

                return;
            }

            detailView.reRender();
        }
    };
});
