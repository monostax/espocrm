/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import View from 'view';

/**
 * Navbar item that manages conversation status count badges.
 * Shows dual counts: left = assigned to current user, right = others.
 * Uses optimistic UI for instant updates on Kanban moves.
 */
class ConversationBadgesView extends View {

    template = 'chatwoot:site/navbar/conversation-badges'

    /**
     * Polling interval in seconds.
     * @type {number}
     */
    pollInterval = 30

    /**
     * @type {number|null}
     */
    intervalId = null

    /**
     * Current counts cache for optimistic updates.
     * @type {Object|null}
     */
    currentCounts = null

    /**
     * Pending drag state for optimistic updates.
     * @type {{ id: string, fromStatus: string }|null}
     */
    pendingDrag = null

    /**
     * Filter to navbar item ID mapping.
     * Note: 'resolved' excluded - no badge count needed for resolved conversations.
     */
    filterHrefs = {
        open: '#ChatwootConversation/list/primaryFilter=open',
        pending: '#ChatwootConversation/list/primaryFilter=pending',
        snoozed: '#ChatwootConversation/list/primaryFilter=snoozed',
    }

    /**
     * Valid statuses for validation.
     */
    validStatuses = ['open', 'pending', 'resolved', 'snoozed']

    setup() {
        // Check if user has access to ChatwootConversation
        if (!this.getAcl().check('ChatwootConversation', 'read')) {
            return;
        }

        // Get WebSocket manager
        this.webSocketManager = this.getHelper().webSocketManager;

        // Wait for navbar to render, then start badge updates
        this.listenToOnce(this, 'after:render', () => {
            setTimeout(() => {
                this.updateBadges();
                this.startPolling();
                this.setupOptimisticListener();
                this.setupWebSocket();
            }, 1000);
        });

        // Update on route changes
        this.listenTo(Espo.router, 'routed', () => {
            // Sync with server on navigation
            this.updateBadges();
        });
    }

    /**
     * Subscribe to WebSocket for real-time updates from other users.
     */
    setupWebSocket() {
        if (!this.webSocketManager || !this.webSocketManager.isEnabled()) {
            return;
        }

        // Subscribe to conversation status changes
        this.webSocketManager.subscribe('chatwootConversationUpdate', (topic, data) => {
            // Another user changed a conversation - refresh counts
            this.updateBadges();
        });

        this.isWebSocketSubscribed = true;
    }

    /**
     * Set up optimistic UI listener.
     * Captures status changes from Kanban drops.
     */
    setupOptimisticListener() {
        const self = this;

        // Track status changes from Kanban drags
        // The Kanban stores the source column in draggedGroupFrom
        $(document).on('sortstart', '.group-column-list', function(event, ui) {
            const id = $(ui.item).data('id');
            const fromStatus = $(this).data('name');
            
            if (id && fromStatus && self.validStatuses.includes(fromStatus)) {
                self.pendingDrag = { id, fromStatus };
            }
        });

        $(document).on('sortstop', '.group-column-list', function(event, ui) {
            if (!self.pendingDrag) return;

            const id = $(ui.item).data('id');
            const toStatus = $(ui.item).closest('.group-column-list').data('name');
            
            if (id === self.pendingDrag.id && 
                toStatus && 
                self.validStatuses.includes(toStatus) &&
                toStatus !== self.pendingDrag.fromStatus) {
                
                // Apply optimistic update immediately
                self.applyOptimisticChange(self.pendingDrag.fromStatus, toStatus);
            }
            
            self.pendingDrag = null;
        });

        // Sync after completion as backup
        $(document).ajaxComplete(function(event, xhr, settings) {
            if ((settings.type === 'PUT' || settings.type === 'PATCH' || settings.type === 'DELETE') && 
                settings.url && settings.url.includes('ChatwootConversation')) {
                setTimeout(() => self.updateBadges(), 1000);
            }
        });

        // Listen for optimistic removal events
        $(document).on('chatwoot:conversation:removed', (event, data) => {
            self.applyOptimisticRemoval(data.status);
        });

        // Listen for badge refresh events (e.g., on error to revert optimistic updates)
        $(document).on('chatwoot:conversation:badges:refresh', () => {
            self.updateBadges();
        });
    }

    /**
     * Apply optimistic count change.
     * @param {string} fromStatus
     * @param {string} toStatus
     */
    applyOptimisticChange(fromStatus, toStatus) {
        if (!this.currentCounts) return;

        // Decrement from old status
        if (this.currentCounts[fromStatus]) {
            if (this.currentCounts[fromStatus].others > 0) {
                this.currentCounts[fromStatus].others--;
            } else if (this.currentCounts[fromStatus].mine > 0) {
                this.currentCounts[fromStatus].mine--;
            }
        }

        // Increment to new status
        if (!this.currentCounts[toStatus]) {
            this.currentCounts[toStatus] = { mine: 0, others: 0 };
        }
        this.currentCounts[toStatus].others++;

        // Re-render badges immediately
        this.renderBadgesFromCache();
    }

    /**
     * Apply optimistic removal - decrement count for a status.
     * @param {string} status - The status of the removed conversation
     */
    applyOptimisticRemoval(status) {
        if (!this.currentCounts) return;
        if (!status || !this.validStatuses.includes(status)) return;

        // Decrement from the status
        if (this.currentCounts[status]) {
            if (this.currentCounts[status].others > 0) {
                this.currentCounts[status].others--;
            } else if (this.currentCounts[status].mine > 0) {
                this.currentCounts[status].mine--;
            }
        }

        // Re-render badges immediately
        this.renderBadgesFromCache();
    }

    /**
     * Render badges from cached counts.
     */
    renderBadgesFromCache() {
        if (!this.currentCounts) return;

        Object.keys(this.filterHrefs).forEach(filter => {
            const filterCounts = this.currentCounts[filter] || { mine: 0, others: 0 };
            this.updateBadgeForFilter(filter, filterCounts.mine, filterCounts.others);
        });
    }

    /**
     * Start polling for badge updates.
     */
    startPolling() {
        if (this.intervalId) {
            return;
        }

        this.intervalId = setInterval(() => {
            this.updateBadges();
        }, this.pollInterval * 1000);
    }

    /**
     * Stop polling.
     */
    stopPolling() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
    }

    /**
     * Fetch counts from API and update navbar badges.
     * Also caches counts for optimistic updates.
     */
    async updateBadges() {
        if (!this.getAcl().check('ChatwootConversation', 'read')) {
            return;
        }

        try {
            const counts = await Espo.Ajax.getRequest('ChatwootConversation/action/statusCounts');
            
            // Cache counts for optimistic updates
            this.currentCounts = counts;
            
            this.renderBadgesFromCache();
        } catch (e) {
            // Silently fail if API call fails
        }
    }

    /**
     * Format count for display.
     * @param {number} count
     * @returns {string}
     */
    formatCount(count) {
        if (count > 99) return '99+';
        return String(count);
    }

    /**
     * Update or create dual badge for a specific filter.
     * @param {string} filter - The filter name
     * @param {number} mine - Count assigned to current user
     * @param {number} others - Count assigned to others
     */
    updateBadgeForFilter(filter, mine, others) {
        const href = this.filterHrefs[filter];
        const $link = $(`.navbar a[href="${href}"]`);
        
        if (!$link.length) {
            return;
        }

        // Find or create badge container within the full-label
        let $badgeContainer = $link.find('.conversation-badge-container');
        
        if (!$badgeContainer.length) {
            $badgeContainer = $(`
                <span class="conversation-badge-container">
                    <span class="conversation-badge badge-mine"></span>
                    <span class="conversation-badge badge-others"></span>
                </span>
            `);
            $link.find('.full-label').append($badgeContainer);
        }

        const $mineBadge = $badgeContainer.find('.badge-mine');
        const $othersBadge = $badgeContainer.find('.badge-others');

        // Update mine badge
        if (mine > 0) {
            $mineBadge.text(this.formatCount(mine)).show();
        } else {
            $mineBadge.hide();
        }

        // Update others badge
        if (others > 0) {
            $othersBadge.text(this.formatCount(others)).show();
        } else {
            $othersBadge.hide();
        }

        // Hide container if both are zero
        if (mine === 0 && others === 0) {
            $badgeContainer.hide();
        } else {
            $badgeContainer.show();
        }
    }

    onRemove() {
        this.stopPolling();
        
        // Unsubscribe from WebSocket
        if (this.isWebSocketSubscribed && this.webSocketManager) {
            this.webSocketManager.unsubscribe('chatwootConversationUpdate');
        }

        // Clean up document event listeners
        $(document).off('chatwoot:conversation:removed');
        $(document).off('chatwoot:conversation:badges:refresh');
    }
}

export default ConversationBadgesView;

