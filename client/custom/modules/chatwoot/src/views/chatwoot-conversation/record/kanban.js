/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('chatwoot:views/chatwoot-conversation/record/kanban', ['views/record/kanban'], function (Dep) {
    return Dep.extend({
        // Use custom item view for conversation cards
        itemViewName: 'chatwoot:views/chatwoot-conversation/record/kanban-item',
        
        // Status field for kanban grouping
        statusField: 'status',

        setup: function () {
            Dep.prototype.setup.call(this);
            
            // Listen for collection sync to update cards
            this.listenTo(this.collection, 'sync', () => {
                this.scheduleRefresh();
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
    });
});

