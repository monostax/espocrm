<style>
/* Modern Kanban Board Styles */
.kanban-board {
    display: flex;
    gap: 1.5rem;
    min-width: max-content;
    height: 100%;
    padding: 0.5rem;
}

.kanban-column {
    display: flex;
    flex-direction: column;
    min-width: 320px;
    max-width: 320px;
    height: 100%;
}

/* Column Header Card */
.kanban-header-card {
    margin-bottom: 0.75rem;
    padding: 0.75rem;
    border-radius: 0.5rem 0.5rem 0 0;
    border-top: 4px solid #9ca3af;
    background-color: #f3f4f6;
    box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
}

.kanban-header-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.25rem;
}

.kanban-header-title h2 {
    font-weight: 700;
    color: #1f2937;
    margin: 0;
    font-size: 1rem;
}

.kanban-header-actions {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.kanban-header-count {
    font-size: 12px;
    font-weight: 700;
    padding: 0.125rem 0.5rem;
    border-radius: 9999px;
    background-color: #f3f4f6;
    color: #374151;
    transition: transform 0.2s ease;
}

.kanban-header-card:hover .kanban-header-count {
    transform: translateX(-4px);
}

.kanban-header-card .create-button {
    opacity: 0;
    width: 0;
    padding: 0;
    overflow: hidden;
    transition: all 0.2s ease;
}

.kanban-header-card:hover .create-button {
    opacity: 1;
    width: auto;
    padding: 0.125rem 0.375rem;
}

.kanban-header-stats {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 12px;
    color: #6b7280;
}

.kanban-header-stats .amount {
    font-weight: 600;
    color: #111827;
}

/* Color variations for header based on stage style */
.group-header-default .kanban-header-card { border-top-color: #9ca3af; }
.group-header-default .kanban-header-count { background-color: #f3f4f6; color: #374151; }

.group-header-primary .kanban-header-card { border-top-color: #3b82f6; }
.group-header-primary .kanban-header-count { background-color: #eff6ff; color: #1d4ed8; }

.group-header-success .kanban-header-card { border-top-color: #10b981; }
.group-header-success .kanban-header-count { background-color: #ecfdf5; color: #047857; }

.group-header-danger .kanban-header-card { border-top-color: #ef4444; }
.group-header-danger .kanban-header-count { background-color: #fef2f2; color: #b91c1c; }

.group-header-warning .kanban-header-card { border-top-color: #f59e0b; }
.group-header-warning .kanban-header-count { background-color: #fffbeb; color: #b45309; }

.group-header-info .kanban-header-card { border-top-color: #8b5cf6; }
.group-header-info .kanban-header-count { background-color: #f5f3ff; color: #6d28d9; }

/* Column content area */
.kanban-column-content {
    flex: 1;
    background-color: rgba(243, 244, 246, 0.5);
    border-radius: 0.5rem;
    padding: 0.5rem;
    overflow-y: auto;
    min-height: 150px;
    border: 2px solid transparent;
    transition: border-color 0.2s ease;
}

.kanban-column-content:hover {
    border-color: rgba(229, 231, 235, 0.5);
}

/* Empty state placeholder */
.kanban-empty-placeholder {
    height: 100%;
    min-height: 100px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9ca3af;
    font-size: 0.875rem;
    font-style: italic;
    border: 2px dashed #e5e7eb;
    border-radius: 0.5rem;
    padding: 2rem 0;
}

/* Item cards */
.kanban-column-content .item {
    margin-bottom: 0.75rem;
}

.kanban-column-content .item:last-child {
    margin-bottom: 0;
}

/* Override EspoCRM panel styles for kanban items */
.kanban-column-content .panel {
    margin-bottom: 0;
    border-radius: 0.75rem;
    border: 1px solid #f3f4f6;
    box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    cursor: grab;
    transition: all 0.2s ease;
    background: white;
}

.kanban-column-content .panel:hover {
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

.kanban-column-content .panel:active {
    cursor: grabbing;
}

.kanban-column-content .panel-body {
    padding: 1rem;
}

/* Create button */
.create-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
    border-radius: 0.25rem;
    font-size: 12px;
}

.create-button:hover {
    color: #374151;
    background-color: #e5e7eb;
}

/* Show more button */
.show-more {
    text-align: center;
    padding: 0.5rem 0;
}

.show-more .btn-link {
    color: #6b7280;
    font-size: 0.875rem;
}

.show-more .btn-link:hover {
    color: #374151;
}

/* Funnel selector */
.funnel-selector-container {
    margin-bottom: 1rem;
}

.funnel-selector-container .btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    border-radius: 0.5rem;
    font-weight: 500;
    background-color: white;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
}

.funnel-selector-container .btn:hover {
    background-color: #f9fafb;
    border-color: #d1d5db;
}

/* Top bar */
.list-buttons-container {
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.total-count {
    font-size: 0.875rem;
    color: #6b7280;
}

/* No data state */
.no-data {
    text-align: center;
    padding: 3rem;
    color: #9ca3af;
    font-size: 0.875rem;
}

/* Override EspoCRM default kanban styles */
.list-kanban-container {
    overflow-x: auto;
}

.list-kanban {
    display: block !important;
}

.kanban-head-container,
.kanban-columns-container {
    display: none !important;
}

/* Drag and drop states */
.kanban-column-content.drag-active {
    border-color: rgba(59, 130, 246, 0.3);
}

.kanban-column-content .item.dragging {
    opacity: 0.5;
    transform: rotate(2deg);
}

.kanban-empty-placeholder.drag-over {
    background-color: rgba(59, 130, 246, 0.1);
    border-color: #3b82f6;
    color: #3b82f6;
}

/* Smooth scrollbar for column content */
.kanban-column-content::-webkit-scrollbar {
    width: 6px;
}

.kanban-column-content::-webkit-scrollbar-track {
    background: transparent;
}

.kanban-column-content::-webkit-scrollbar-thumb {
    background-color: #d1d5db;
    border-radius: 3px;
}

.kanban-column-content::-webkit-scrollbar-thumb:hover {
    background-color: #9ca3af;
}

/* Item hover effects */
.kanban-column-content .panel:hover {
    transform: translateY(-1px);
}

/* Starred items */
.kanban-column-content .panel.starred {
    border-left: 3px solid #f59e0b;
}

/* Item menu button visibility */
.kanban-column-content .panel .item-menu-container {
    opacity: 0;
    transition: opacity 0.2s ease;
}

.kanban-column-content .panel:hover .item-menu-container {
    opacity: 1;
}

/* Mobile responsiveness */
@media (max-width: 768px) {
    .kanban-column {
        min-width: 280px;
        max-width: 280px;
    }
    
    .kanban-board {
        gap: 1rem;
    }
}
</style>

{{#if hasFunnelSelector}}
<div class="funnel-selector-container clearfix">
    <button
        type="button"
        class="btn btn-default"
        data-action="selectFunnel"
        title="{{translate 'Funnel' scope='Opportunity'}}"
    >
        <span class="fas fa-filter"></span>
        <span class="funnel-selector-name">{{#if currentFunnelName}}{{currentFunnelName}}{{else}}{{translate 'Select Funnel' scope='Opportunity'}}{{/if}}</span>
        <span class="caret"></span>
    </button>
</div>
{{/if}}

{{#if topBar}}
<div class="list-buttons-container clearfix">
    {{#if displayTotalCount}}
        <div class="text-muted total-count">
            <span
                title="{{translate 'Total'}}"
                class="total-count-span"
            >{{totalCountFormatted}}</span>
        </div>
    {{/if}}

    {{#if settings}}
        <div class="settings-container pull-right">{{{settings}}}</div>
    {{/if}}

    {{#each buttonList}}
        {{button
            name
            scope=../scope
            label=label
            style=style
            class='list-action-item'
        }}
    {{/each}}
</div>
{{/if}}

<div class="list-kanban-container">
    <div class="list-kanban" data-scope="{{scope}}" style="min-width: {{minTableWidthPx}}px">
        {{!-- Hidden original structure for EspoCRM drag-and-drop functionality --}}
        <div class="kanban-head-container">
            <table class="kanban-head">
                <thead>
                    <tr class="kanban-row">
                        {{#each groupDataList}}
                        <th data-name="{{name}}" class="group-header{{#if style}} group-header-{{style}}{{else}} group-header-default{{/if}}">
                            <span class="kanban-group-label">{{label}}</span>
                        </th>
                        {{/each}}
                    </tr>
                </thead>
            </table>
        </div>
        <div class="kanban-columns-container">
            <table class="kanban-columns">
                {{#unless isEmptyList}}
                <tbody>
                    <tr class="kanban-row">
                        {{#each groupDataList}}
                        <td class="group-column" data-name="{{name}}">
                            <div>
                                <div class="group-column-list" data-name="{{name}}">
                                    {{#each dataList}}
                                    <div class="item" data-id="{{id}}">{{{var key ../../this}}}</div>
                                    {{/each}}
                                </div>
                            </div>
                        </td>
                        {{/each}}
                    </tr>
                </tbody>
                {{/unless}}
            </table>
        </div>

        {{!-- Modern Kanban Board --}}
        <div class="kanban-board">
            {{#each groupDataList}}
            <div class="kanban-column group-header{{#if style}} group-header-{{style}}{{else}} group-header-default{{/if}}" data-name="{{name}}">
                <div class="kanban-header-card">
                    <div class="kanban-header-title">
                        <h2>{{label}}</h2>
                        <div class="kanban-header-actions">
                            <span class="kanban-header-count kanban-group-count">{{count}}</span>
                            <a
                                role="button"
                                tabindex="0"
                                title="{{translate 'Create'}}"
                                class="create-button"
                                data-action="createInGroup"
                                data-group="{{name}}"
                            >
                                <span class="fas fa-plus fa-sm"></span>
                            </a>
                        </div>
                    </div>
                    <div class="kanban-header-stats">
                        <span>{{translate 'Total Value' scope='Opportunity'}}</span>
                        <span class="amount kanban-group-amount">{{amountSumFormatted}}</span>
                    </div>
                </div>
                <div class="kanban-column-content">
                    <div class="group-column-list-visual" data-name="{{name}}">
                        {{#if dataList.length}}
                            {{#each dataList}}
                            <div class="item" data-id="{{id}}">{{{var key ../../this}}}</div>
                            {{/each}}
                        {{else}}
                            <div class="kanban-empty-placeholder">
                                {{translate 'Drop here' scope='Opportunity'}}
                            </div>
                        {{/if}}
                    </div>
                    {{#if hasShowMore}}
                    <div class="show-more">
                        <a data-action="groupShowMore" data-name="{{name}}" title="{{translate 'Show more'}}" class="btn btn-link btn-sm">
                            <span class="fas fa-ellipsis-h fa-sm"></span>
                            <span class="text-muted" style="margin-left: 0.25rem; font-size: 11px;">{{translate 'Show more'}}</span>
                        </a>
                    </div>
                    {{/if}}
                </div>
            </div>
            {{/each}}
        </div>
    </div>
</div>

{{#if isEmptyList}}{{#unless noDataDisabled}}
<div class="no-data">
    <span class="fas fa-inbox fa-2x" style="display: block; margin-bottom: 0.5rem; opacity: 0.5;"></span>
    {{translate 'No Data'}}
</div>
{{/unless}}{{/if}}
