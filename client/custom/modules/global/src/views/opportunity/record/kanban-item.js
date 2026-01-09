/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('global:views/opportunity/record/kanban-item', ['views/record/kanban-item'], function (Dep) {
    return Dep.extend({
        template: 'global:opportunity/record/kanban-item',

        data: function () {
            const data = Dep.prototype.data.call(this);
            
            // Get linked conversations
            const conversations = this.getConversationsData();
            
            return {
                ...data,
                conversations: conversations,
                hasConversations: conversations.length > 0,
            };
        },

        /**
         * Get linked ChatwootConversations data for display
         */
        getConversationsData: function () {
            const conversationsIds = this.model.get('chatwootConversationsIds') || [];
            const conversationsNames = this.model.get('chatwootConversationsNames') || {};
            const conversationsColumns = this.model.get('chatwootConversationsColumns') || {};
            
            return conversationsIds.map(id => {
                const status = conversationsColumns[id] ? conversationsColumns[id].status : null;
                const contactName = conversationsColumns[id] ? conversationsColumns[id].contactDisplayName : null;
                return {
                    id: id,
                    name: conversationsNames[id] || contactName || 'Conversation',
                    status: status,
                    statusLabel: status ? this.translate(status, 'options', 'ChatwootConversation', 'status') : null,
                    statusStyle: this.getConversationStatusStyle(status),
                };
            });
        },

        /**
         * Get CSS class for conversation status
         */
        getConversationStatusStyle: function (status) {
            const styleMap = {
                'open': 'conv-status-open',
                'resolved': 'conv-status-resolved',
                'pending': 'conv-status-pending',
                'snoozed': 'conv-status-snoozed',
            };
            return styleMap[status] || 'conv-status-default';
        },

        afterRender: function () {
            Dep.prototype.afterRender.call(this);
            
            // Prevent conversation badge clicks from triggering card actions
            this.$el.find('.opportunity-conversation').on('click', function (e) {
                e.stopPropagation();
            });
        },
    });
});

