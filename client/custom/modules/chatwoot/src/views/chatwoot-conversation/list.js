/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 ************************************************************************/

import ListView from 'views/list';

/**
 * Custom list view for ChatwootConversation that allows URL filters
 * while keeping the filter dropdown visible.
 */
class ChatwootConversationListView extends ListView {

    /**
     * Override createSearchView to NOT disable primaryFilters when
     * navigating with primaryFilter in URL.
     * 
     * @return {Promise<module:view>}
     * @protected
     */
    createSearchView() {
        return this.createView(
            'search',
            this.searchView,
            {
                collection: this.collection,
                fullSelector: '#main > .search-container',
                searchManager: this.searchManager,
                scope: this.scope,
                viewMode: this.viewMode,
                viewModeList: this.viewModeList,
                isWide: true,
                // Override: Don't disable save preset and primary filters
                // even when primaryFilter is set via URL
                disableSavePreset: false,
                primaryFiltersDisabled: false,
            },
            (view) => {
                this.listenTo(view, 'reset', () => this.resetSorting());

                if (this.viewModeList.length > 1) {
                    this.listenTo(view, 'change-view-mode', (mode) =>
                        this.switchViewMode(mode)
                    );
                }
            }
        );
    }
}

export default ChatwootConversationListView;


