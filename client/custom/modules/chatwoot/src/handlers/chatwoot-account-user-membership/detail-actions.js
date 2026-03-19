/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * Detail action handler for ChatwootAccountUserMembership.
 *
 * Provides "Enable AI Profile" and "Disable AI Profile" toggle buttons.
 * The isAI state is fetched from the linked ChatwootAgent entity.
 *
 * Enable: creates a new ChatwootAgent (or re-enables isAI on existing) via API action.
 * Disable: sets isAI=false on the linked agent without unlinking (Decision #10).
 */
define('chatwoot:handlers/chatwoot-account-user-membership/detail-actions', [], function () {

    return class {
        constructor(view) {
            this.view = view;
            /** @type {boolean|null} */
            this._isAgentAI = null;
            this._agentAIFetched = false;

            // Fetch agent isAI state on init and on model changes
            this._fetchAgentAIState();

            this.view.listenTo(this.view.model, 'change:chatwootAgentId', () => {
                this._agentAIFetched = false;
                this._isAgentAI = null;
                this._fetchAgentAIState();
            });

            this.view.listenTo(this.view.model, 'sync', () => {
                this._agentAIFetched = false;
                this._isAgentAI = null;
                this._fetchAgentAIState();
            });
        }

        /**
         * Fetch the isAI state from the linked ChatwootAgent.
         * @private
         */
        _fetchAgentAIState() {
            const agentId = this.view.model.get('chatwootAgentId');

            if (!agentId) {
                this._isAgentAI = null;
                this._agentAIFetched = true;
                return;
            }

            Espo.Ajax.getRequest(`ChatwootAgent/${agentId}`, {select: 'isAI'})
                .then(response => {
                    this._isAgentAI = response.isAI || false;
                    this._agentAIFetched = true;
                    // Re-render to update button visibility
                    if (this.view && this.view.isRendered()) {
                        this.view.reRender();
                    }
                })
                .catch(() => {
                    this._isAgentAI = null;
                    this._agentAIFetched = true;
                });
        }

        /**
         * Enable AI Profile is available when:
         * - No agent linked (chatwootAgentId is empty) — will create a new agent
         * - Agent linked but isAI is false — will re-enable AI
         */
        isEnableAiProfileAvailable() {
            const agentId = this.view.model.get('chatwootAgentId');

            // No agent linked — enable creates one
            if (!agentId) {
                return true;
            }

            // Agent linked — show enable only if isAI is false
            if (this._agentAIFetched && this._isAgentAI === false) {
                return true;
            }

            return false;
        }

        /**
         * Disable AI Profile is available when:
         * - Agent linked AND isAI is true
         */
        isDisableAiProfileAvailable() {
            const agentId = this.view.model.get('chatwootAgentId');

            if (!agentId) {
                return false;
            }

            if (this._agentAIFetched && this._isAgentAI === true) {
                return true;
            }

            return false;
        }

        enableAiProfile() {
            const model = this.view.model;

            Espo.Ui.confirm(
                this.view.translate('confirmEnableAiProfile', 'messages', 'ChatwootAccountUserMembership'),
                {
                    confirmText: this.view.translate('Enable AI Profile', 'labels', 'ChatwootAccountUserMembership'),
                    cancelText: this.view.translate('Cancel'),
                },
                () => {
                    Espo.Ui.notify(this.view.translate('Please wait...'));

                    Espo.Ajax.postRequest(`ChatwootAccountUserMembership/${model.id}/enableAiProfile`)
                        .then(response => {
                            Espo.Ui.success(
                                this.view.translate('aiProfileEnabled', 'messages', 'ChatwootAccountUserMembership')
                            );
                            model.set(response);
                            this._agentAIFetched = false;
                            this._isAgentAI = null;
                            this._fetchAgentAIState();
                            this.view.reRender();
                        })
                        .catch(xhr => {
                            let errorMsg = 'Failed to enable AI profile';
                            if (xhr?.responseJSON?.message) {
                                errorMsg = xhr.responseJSON.message;
                            }
                            Espo.Ui.error(errorMsg);
                        });
                }
            );
        }

        disableAiProfile() {
            const model = this.view.model;

            Espo.Ui.confirm(
                this.view.translate('confirmDisableAiProfile', 'messages', 'ChatwootAccountUserMembership'),
                {
                    confirmText: this.view.translate('Disable AI Profile', 'labels', 'ChatwootAccountUserMembership'),
                    cancelText: this.view.translate('Cancel'),
                },
                () => {
                    Espo.Ui.notify(this.view.translate('Please wait...'));

                    Espo.Ajax.postRequest(`ChatwootAccountUserMembership/${model.id}/disableAiProfile`)
                        .then(response => {
                            Espo.Ui.success(
                                this.view.translate('aiProfileDisabled', 'messages', 'ChatwootAccountUserMembership')
                            );
                            model.set(response);
                            this._agentAIFetched = false;
                            this._isAgentAI = null;
                            this._fetchAgentAIState();
                            this.view.reRender();
                        })
                        .catch(xhr => {
                            let errorMsg = 'Failed to disable AI profile';
                            if (xhr?.responseJSON?.message) {
                                errorMsg = xhr.responseJSON.message;
                            }
                            Espo.Ui.error(errorMsg);
                        });
                }
            );
        }
    };
});
