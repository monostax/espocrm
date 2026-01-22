/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('chatwoot:views/chatwoot-conversation/record/kanban', ['views/record/kanban', 'web-socket-manager'], function (Dep, WebSocketManager) {
    return Dep.extend({
        // Use custom item view for conversation cards
        itemViewName: 'chatwoot:views/chatwoot-conversation/record/kanban-item',
        
        // Status field for kanban grouping
        statusField: 'status',

        // WebSocket debounce interval (ms)
        webSocketDebounceInterval: 500,

        /**
         * Override to include linkMultiple attributes for opportunities and cAgendamentos
         */
        getSelectAttributeList: async function (callback) {
            const attributeList = await Dep.prototype.getSelectAttributeList.call(this);
            
            if (!attributeList) {
                if (callback) callback(null);
                return null;
            }
            
            // Add opportunities linkMultiple attributes
            if (!attributeList.includes('opportunitiesIds')) {
                attributeList.push('opportunitiesIds');
            }
            if (!attributeList.includes('opportunitiesNames')) {
                attributeList.push('opportunitiesNames');
            }
            if (!attributeList.includes('opportunitiesColumns')) {
                attributeList.push('opportunitiesColumns');
            }
            
            // Add cAgendamentos linkMultiple attributes
            if (!attributeList.includes('cAgendamentosIds')) {
                attributeList.push('cAgendamentosIds');
            }
            if (!attributeList.includes('cAgendamentosNames')) {
                attributeList.push('cAgendamentosNames');
            }
            if (!attributeList.includes('cAgendamentosColumns')) {
                attributeList.push('cAgendamentosColumns');
            }
            
            // Add tasks attributes (loaded via backend TasksLoader)
            if (!attributeList.includes('tasksIds')) {
                attributeList.push('tasksIds');
            }
            if (!attributeList.includes('tasksNames')) {
                attributeList.push('tasksNames');
            }
            if (!attributeList.includes('tasksColumns')) {
                attributeList.push('tasksColumns');
            }
            
            if (callback) callback(attributeList);
            
            return attributeList;
        },

        setup: function () {
            Dep.prototype.setup.call(this);
            
            // Get WebSocket manager
            this.webSocketManager = this.getHelper().webSocketManager;
            
            // Listen for collection sync to update cards
            this.listenTo(this.collection, 'sync', () => {
                this.scheduleRefresh();
            });

            // Setup WebSocket subscription
            this.setupWebSocket();
        },

        /**
         * Setup WebSocket subscription for real-time updates
         */
        setupWebSocket: function () {
            if (!this.webSocketManager || !this.webSocketManager.isEnabled()) {
                return;
            }

            this._webSocketDebounceTimeout = null;

            // Subscribe to chatwootConversationUpdate topic (used by navbar badges too)
            this.webSocketManager.subscribe('chatwootConversationUpdate', (topic, data) => {
                this.handleWebSocketUpdate(data);
            });

            // Subscribe to generic recordUpdate for ChatwootConversation
            this.webSocketManager.subscribe('recordUpdate.ChatwootConversation', (topic, data) => {
                this.handleWebSocketUpdate(data);
            });

            this.isWebSocketSubscribed = true;
        },

        /**
         * Handle WebSocket update with debouncing
         */
        handleWebSocketUpdate: function (data) {
            // Debounce to prevent multiple rapid refreshes
            if (this._webSocketDebounceTimeout) {
                clearTimeout(this._webSocketDebounceTimeout);
            }

            this._webSocketDebounceTimeout = setTimeout(() => {
                this.refreshKanban();
            }, this.webSocketDebounceInterval);
        },

        /**
         * Refresh the kanban board
         */
        refreshKanban: function () {
            // Fetch new data for all groups
            this.collection.fetch({
                reset: true,
            }).then(() => {
                // Re-render will happen automatically via collection events
            });
        },

        /**
         * Schedule a refresh after a short delay to prevent multiple refreshes
         */
        scheduleRefresh: function () {
            if (this._refreshTimeout) {
                clearTimeout(this._refreshTimeout);
            }
            
            this._refreshTimeout = setTimeout(() => {
                // Cards will be automatically refreshed by the parent class
            }, 300);
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);
            
            // Add custom styling for conversation kanban
            this.$el.addClass('conversation-kanban');
        },

        /**
         * Cleanup on view removal
         */
        onRemove: function () {
            // Unsubscribe from WebSocket topics
            if (this.isWebSocketSubscribed && this.webSocketManager) {
                this.webSocketManager.unsubscribe('chatwootConversationUpdate');
                this.webSocketManager.unsubscribe('recordUpdate.ChatwootConversation');
            }

            // Clear any pending timeouts
            if (this._webSocketDebounceTimeout) {
                clearTimeout(this._webSocketDebounceTimeout);
            }
            if (this._refreshTimeout) {
                clearTimeout(this._refreshTimeout);
            }

            Dep.prototype.onRemove.call(this);
        },
    });
});

