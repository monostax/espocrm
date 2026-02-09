<style>
/* Opportunity Kanban Card Styles - matches ChatwootConversation styling */
.opportunity-card {
    background: #fff;
    border-radius: 12px;
    padding: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 1px solid #e5e7eb;
    position: relative;
}

.opportunity-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
    border-color: #d1d5db;
}

.opportunity-card:active {
    transform: translateY(0);
}

/* Card Header */
.opportunity-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
}

/* Opportunity Info */
.opportunity-info {
    flex: 1;
    min-width: 0;
    overflow: hidden;
}

.opportunity-name {
    font-weight: 600;
    color: #111827;
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 2px;
}

.opportunity-account-name {
    font-size: 11px;
    color: #9ca3af;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Amount Badge */
.opportunity-amount {
    font-size: 13px;
    font-weight: 700;
    color: #059669;
    flex-shrink: 0;
    margin-left: auto;
    background: #d1fae5;
    padding: 4px 10px;
    border-radius: 8px;
}

/* Contact Info */
.opportunity-contact {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #6b7280;
    margin-bottom: 10px;
    padding: 6px 8px;
    background: #f9fafb;
    border-radius: 6px;
}

.opportunity-contact i {
    font-size: 11px;
    color: #9ca3af;
}

.opportunity-contact-name {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Card Footer */
.opportunity-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #f3f4f6;
}

/* Probability Badge */
.opportunity-probability {
    font-size: 10px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 9999px;
    background: #e0f2fe;
    color: #0369a1;
}

/* Meta Info */
.opportunity-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 11px;
    color: #9ca3af;
}

.opportunity-meta-item {
    display: flex;
    align-items: center;
    gap: 4px;
}

.opportunity-meta-item i {
    font-size: 10px;
}

/* Assignee */
.opportunity-assignee {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: #6b7280;
    background: #f3f4f6;
    padding: 2px 6px;
    border-radius: 4px;
}

.opportunity-assignee i {
    font-size: 10px;
}

/* Close Date */
.opportunity-close-date {
    font-size: 11px;
    color: #6b7280;
}

.opportunity-close-date.is-overdue {
    color: #dc2626;
    font-weight: 500;
}

.opportunity-close-date.is-soon {
    color: #f59e0b;
    font-weight: 500;
}

/* Related Entities Section */
.opportunity-related-section {
    margin-top: 10px;
}

.opportunity-related-header {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 6px;
    padding-bottom: 4px;
    border-bottom: 1px solid #e5e7eb;
}

.opportunity-related-header i {
    font-size: 11px;
    color: #6b7280;
}

.opportunity-related-header span {
    font-size: 10px;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.opportunity-related-items {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

/* Conversation Item Styles */
.opportunity-conversation {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
    background: #e0f2fe;
    color: #0369a1;
    cursor: pointer;
    transition: all 0.15s ease;
    max-width: 100%;
    overflow: hidden;
    text-decoration: none;
}

.opportunity-conversation:hover {
    filter: brightness(0.95);
    transform: translateY(-1px);
    text-decoration: none;
    color: #0369a1;
}

.opportunity-conversation i {
    font-size: 10px;
    flex-shrink: 0;
}

.opportunity-conversation-name {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Conversation Status Styles */
.opportunity-conversation.conv-status-open {
    background: #fef3c7;
    color: #92400e;
}

.opportunity-conversation.conv-status-open:hover {
    color: #92400e;
}

.opportunity-conversation.conv-status-resolved {
    background: #d1fae5;
    color: #047857;
}

.opportunity-conversation.conv-status-resolved:hover {
    color: #047857;
}

.opportunity-conversation.conv-status-pending {
    background: #fce7f3;
    color: #9d174d;
}

.opportunity-conversation.conv-status-pending:hover {
    color: #9d174d;
}

.opportunity-conversation.conv-status-snoozed {
    background: #e0e7ff;
    color: #3730a3;
}

.opportunity-conversation.conv-status-snoozed:hover {
    color: #3730a3;
}

.opportunity-conversation.conv-status-default {
    background: #f3f4f6;
    color: #4b5563;
}

/* Task Item Styles */
.opportunity-task {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
    background: #f3f4f6;
    color: #4b5563;
    cursor: pointer;
    transition: all 0.15s ease;
    max-width: 100%;
    overflow: hidden;
    text-decoration: none;
}

.opportunity-task:hover {
    filter: brightness(0.95);
    transform: translateY(-1px);
    text-decoration: none;
    color: #4b5563;
}

.opportunity-task i {
    font-size: 10px;
    flex-shrink: 0;
}

.opportunity-task-name {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Task Status Styles */
.opportunity-task.task-status-not-started {
    background: #f3f4f6;
    color: #4b5563;
}

.opportunity-task.task-status-started {
    background: #dbeafe;
    color: #1d4ed8;
}

.opportunity-task.task-status-completed {
    background: #d1fae5;
    color: #047857;
}

.opportunity-task.task-status-canceled {
    background: #e5e7eb;
    color: #6b7280;
    text-decoration: line-through;
}

.opportunity-task.task-status-deferred {
    background: #fef3c7;
    color: #92400e;
}

/* Task Priority Indicator */
.opportunity-task.task-priority-high {
    border-left: 3px solid #f59e0b;
}

.opportunity-task.task-priority-urgent {
    border-left: 3px solid #dc2626;
}

/* Task Count Badge */
.opportunity-task-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 10px;
    background: #6b7280;
    color: #fff;
    font-size: 10px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
}

.opportunity-task-count:hover {
    background: #4b5563;
    transform: scale(1.1);
}

/* Create Button */
.btn-create-related {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 6px;
    border: 1px dashed #d1d5db;
    background: transparent;
    color: #9ca3af;
    cursor: pointer;
    transition: all 0.15s ease;
    padding: 0;
}

.btn-create-related:hover {
    border-color: #6b7280;
    background: #f3f4f6;
    color: #6b7280;
}

.btn-create-related i {
    font-size: 10px;
}

/* Menu Container Override */
.opportunity-card .item-menu-container {
    position: absolute;
    top: 8px;
    right: 8px;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.opportunity-card:hover .item-menu-container {
    opacity: 1;
}
</style>

<div class="opportunity-card" data-id="{{id}}">
    {{#unless rowActionsDisabled}}
    <div class="item-menu-container">{{{itemMenu}}}</div>
    {{/unless}}
    
    <div class="opportunity-card-header">
        <div class="opportunity-info">
            <div class="opportunity-name">{{name}}</div>
            {{#if accountName}}
            <div class="opportunity-account-name">
                <i class="fas fa-building"></i> {{accountName}}
            </div>
            {{/if}}
        </div>
        
        {{#if amountFormatted}}
        <div class="opportunity-amount">{{amountFormatted}}</div>
        {{/if}}
    </div>
    
    {{#if contactName}}
    <div class="opportunity-contact">
        <i class="ti ti-user"></i>
        <span class="opportunity-contact-name">{{contactName}}</span>
    </div>
    {{/if}}
    
    {{#if hasTasks}}
    <div class="opportunity-related-section">
        <div class="opportunity-related-header">
            <i class="fas fa-tasks"></i>
            <span>{{tasksLabel}}</span>
        </div>
        <div class="opportunity-related-items">
            {{#each tasks}}
            <a href="#Task/view/{{id}}" class="opportunity-task {{statusStyle}} {{priorityStyle}}" title="{{name}}{{#if statusLabel}} - {{statusLabel}}{{/if}}" data-id="{{id}}" onclick="event.stopPropagation();">
                <span class="opportunity-task-name">{{name}}</span>
            </a>
            {{/each}}
            {{#if hasMoreTasks}}
            <span class="opportunity-task-count" title="{{viewAllTasksLabel}}" data-action="viewAllTasks">+{{remainingTasksCount}}</span>
            {{/if}}
            <button type="button" class="btn-create-related btn-create-task" title="{{../createTaskLabel}}">
                <i class="fas fa-plus"></i>
            </button>
        </div>
    </div>
    {{else}}
    <div class="opportunity-related-section">
        <div class="opportunity-related-items">
            <button type="button" class="btn-create-related btn-create-task" title="{{createTaskLabel}}">
                <i class="fas fa-tasks"></i>
                <i class="fas fa-plus" style="font-size: 8px; margin-left: 2px;"></i>
            </button>
        </div>
    </div>
    {{/if}}

    {{#if hasConversations}}
    <div class="opportunity-related-section">
        <div class="opportunity-related-header">
            <i class="fas fa-comments"></i>
            <span>{{conversationsLabel}}</span>
        </div>
        <div class="opportunity-related-items">
            {{#each conversations}}
            <a href="#ChatwootConversation/view/{{id}}" class="opportunity-conversation {{statusStyle}}" title="{{name}}{{#if statusLabel}} - {{statusLabel}}{{/if}}" data-id="{{id}}" onclick="event.stopPropagation();">
                <span class="opportunity-conversation-name">{{name}}</span>
            </a>
            {{/each}}
        </div>
    </div>
    {{/if}}
    
    <div class="opportunity-card-footer">
        {{#if probability}}
        <span class="opportunity-probability">{{probability}}%</span>
        {{/if}}
        
        <div class="opportunity-meta">
            {{#if closeDateFormatted}}
            <span class="opportunity-close-date {{closeDateClass}}">
                <i class="fas fa-calendar"></i>
                {{closeDateFormatted}}
            </span>
            {{/if}}
            
            {{#if assignedUserName}}
            <span class="opportunity-assignee">
                <i class="ti ti-user"></i>
                {{assignedUserName}}
            </span>
            {{/if}}
        </div>
    </div>
</div>
