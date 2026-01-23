<style>
.inbox-container {
    display: flex;
    height: 600px;
    background: #f9fafb;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid #e5e7eb;
}

.inbox-list-panel {
    width: 380px;
    min-width: 320px;
    background: #fff;
    border-right: 1px solid #e5e7eb;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.inbox-list-header {
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
    background: #fff;
    flex-shrink: 0;
}

.inbox-list-title {
    font-size: 18px;
    font-weight: 600;
    color: #111827;
    margin: 0;
}

.inbox-list-count {
    font-size: 13px;
    color: #6b7280;
    margin-top: 4px;
}

.inbox-conversation-list {
    flex: 1;
    overflow-y: auto;
}

.inbox-conversation-item {
    display: flex;
    padding: 14px 20px;
    gap: 14px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    transition: all 0.15s ease;
    border-left: 3px solid transparent;
}

.inbox-conversation-item:hover {
    background: #f9fafb;
}

.inbox-conversation-item.selected {
    background: #fafafa;
}

/* Status-based border colors - matching EspoCRM brand colors */
.inbox-conversation-item.status-open {
    border-left-color: #e4a133; /* brand-warning */
}

.inbox-conversation-item.status-resolved {
    border-left-color: #6fc374; /* brand-success */
}

.inbox-conversation-item.status-pending {
    border-left-color: #a595c9; /* brand-info */
}

.inbox-conversation-item.status-snoozed {
    border-left-color: #9ca3af; /* default gray */
}

/* Avatar */
.inbox-conv-avatar {
    position: relative;
    flex-shrink: 0;
    width: 48px;
    height: 48px;
}

.inbox-conv-avatar-img {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #f3f4f6;
}

.inbox-conv-avatar-initials {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    border: 2px solid rgba(255,255,255,0.3);
}

/* Channel Badge */
.inbox-conv-channel-badge {
    position: absolute;
    bottom: -2px;
    right: -2px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #fff;
    border: 2px solid #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
}

.inbox-conv-channel-badge.channel-whatsapp { color: #25D366; background: #dcfce7; }
.inbox-conv-channel-badge.channel-telegram { color: #0088cc; background: #e0f2fe; }
.inbox-conv-channel-badge.channel-instagram { color: #E4405F; background: #fce7f3; }
.inbox-conv-channel-badge.channel-facebook { color: #1877F2; background: #dbeafe; }
.inbox-conv-channel-badge.channel-email { color: #6b7280; background: #f3f4f6; }
.inbox-conv-channel-badge.channel-web { color: #8b5cf6; background: #f3e8ff; }
.inbox-conv-channel-badge.channel-sms { color: #059669; background: #d1fae5; }
.inbox-conv-channel-badge.channel-api { color: #f59e0b; background: #fef3c7; }
.inbox-conv-channel-badge.channel-default { color: #6b7280; background: #f3f4f6; }

/* Content */
.inbox-conv-content {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.inbox-conv-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 4px;
}

.inbox-conv-name {
    font-weight: 600;
    color: #111827;
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
}

.inbox-conv-time {
    font-size: 11px;
    color: #9ca3af;
    flex-shrink: 0;
    margin-left: 12px;
}

/* Message Preview */
.inbox-conv-message {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #6b7280;
    font-size: 13px;
    line-height: 1.4;
    margin-bottom: 8px;
}

.inbox-conv-message.empty {
    color: #9ca3af;
    font-style: italic;
}

.inbox-conv-message-text {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
}

/* Direction Arrow */
.inbox-conv-direction {
    flex-shrink: 0;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
}

.inbox-conv-direction.incoming { 
    background: #dbeafe; 
    color: #2563eb; 
}

.inbox-conv-direction.outgoing { 
    background: #d1fae5; 
    color: #059669; 
}

/* Footer */
.inbox-conv-footer {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.inbox-conv-status {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    padding: 3px 8px;
    border-radius: 4px;
}

/* Status badge colors - matching EspoCRM brand colors */
.inbox-conv-status.status-open { background: rgba(228, 161, 51, 0.15); color: #e4a133; } /* brand-warning */
.inbox-conv-status.status-resolved { background: rgba(111, 195, 116, 0.15); color: #5aa95e; } /* brand-success */
.inbox-conv-status.status-pending { background: rgba(165, 149, 201, 0.15); color: #8a7ab8; } /* brand-info */
.inbox-conv-status.status-snoozed { background: rgba(156, 163, 175, 0.15); color: #6b7280; } /* default gray */

.inbox-conv-inbox-row {
    font-size: 11px;
    color: #9ca3af;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 4px;
}

/* Empty State */
.inbox-empty-list {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    padding: 40px 20px;
    text-align: center;
    color: #6b7280;
}

/* Right Panel */
.inbox-iframe-panel {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: #fff;
    min-width: 0;
}

/* Tabs */
.inbox-tabs {
    display: flex;
    border-bottom: 1px solid #e5e7eb;
    background: #fff;
    flex-shrink: 0;
}

.inbox-tab {
    padding: 12px 20px;
    font-size: 13px;
    font-weight: 500;
    color: #6b7280;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: all 0.15s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.inbox-tab:hover {
    color: #374151;
    background: #f9fafb;
}

.inbox-tab.active {
    color: #3b82f6;
    border-bottom-color: #3b82f6;
}

.inbox-tab i {
    font-size: 14px;
}

.inbox-tab-count {
    background: #e5e7eb;
    color: #374151;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 10px;
    min-width: 20px;
    text-align: center;
    display: none;
}

.inbox-tab-count.has-count {
    display: inline-block;
}

.inbox-tab.active .inbox-tab-count {
    background: #dbeafe;
    color: #3b82f6;
}

/* Tab Content */
.inbox-tab-content {
    flex: 1;
    display: none;
    flex-direction: column;
    overflow: hidden;
}

.inbox-tab-content.active {
    display: flex;
}

.inbox-iframe-wrapper {
    flex: 1;
    position: relative;
}

.inbox-iframe {
    width: 100%;
    height: 100%;
    border: none;
    display: none;
    position: absolute;
    top: 0;
    left: 0;
}

.inbox-iframe-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    background: #f9fafb;
    text-align: center;
    padding: 40px;
}

/* Chat Toolbar */
.inbox-chat-toolbar {
    display: none;
    padding: 10px 16px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
    gap: 10px;
    justify-content: flex-end;
}

.inbox-chat-toolbar.visible {
    display: flex;
}

.inbox-status-dropdown .btn,
.inbox-agent-dropdown .btn {
    font-size: 13px;
}

.inbox-status-dropdown .dropdown-menu a,
.inbox-agent-dropdown .dropdown-menu a {
    cursor: pointer;
}

/* Agent dropdown styles */
.inbox-agent-dropdown .btn i {
    margin-right: 6px;
}

.inbox-agent-menu {
    max-height: 300px;
    overflow-y: auto;
}

.inbox-agent-menu li a {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
}

.inbox-agent-menu li a.active {
    background: #f3f4f6;
    font-weight: 600;
}

.inbox-agent-menu li a:hover {
    background: #f9fafb;
}

.inbox-agent-menu .agent-availability {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.inbox-agent-menu .agent-availability.available { background-color: #6fc374; }
.inbox-agent-menu .agent-availability.busy { background-color: #e4a133; }
.inbox-agent-menu .agent-availability.offline { background-color: #9ca3af; }

.inbox-agent-menu .agent-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.inbox-agent-menu .agent-role {
    font-size: 10px;
    color: #9ca3af;
    text-transform: uppercase;
}

.inbox-agent-menu .divider {
    margin: 4px 0;
    border-top: 1px solid #e5e7eb;
}

.inbox-status-indicator {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 6px;
}

.inbox-status-indicator.status-open { background-color: #e4a133; }
.inbox-status-indicator.status-pending { background-color: #a595c9; }
.inbox-status-indicator.status-resolved { background-color: #6fc374; }
.inbox-status-indicator.status-snoozed { background-color: #9ca3af; }

/* Entity Search Wrapper */
.inbox-entity-search-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
}

.inbox-entity-search-container {
    flex: 1;
    min-width: 0;
}

.inbox-entity-search-container .search-row {
    margin: 0;
}

.inbox-entity-search-container .form-group {
    margin-bottom: 0;
}

.inbox-entity-search-container .text-filter {
    margin-bottom: 0;
}

.inbox-entity-create-btn {
    flex-shrink: 0;
}

.inbox-entity-create-btn .btn {
    padding: 7px 12px;
}

/* Entity List Container for embedded list view */
.inbox-entity-list-container {
    flex: 1;
    overflow-y: auto;
    padding: 0;
}

.inbox-entity-list-container .list-container {
    padding: 0;
}

.inbox-entity-list-container .list > table {
    margin-bottom: 0;
}

/* Adjust list view within tabs */
.inbox-entity-list-container .list-buttons-container {
    padding: 10px 16px;
    border-bottom: 1px solid #e5e7eb;
}

.inbox-entity-list-container .show-more {
    padding: 12px;
    text-align: center;
}

/* No records message */
.inbox-entity-list-container .no-data {
    padding: 40px 20px;
    text-align: center;
    color: #6b7280;
}

/* Scrollbar */
.inbox-conversation-list::-webkit-scrollbar {
    width: 6px;
}

.inbox-conversation-list::-webkit-scrollbar-track {
    background: #f3f4f6;
}

.inbox-conversation-list::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 3px;
}

.inbox-conversation-list::-webkit-scrollbar-thumb:hover {
    background: #9ca3af;
}
</style>

<div class="inbox-container">
    <div class="inbox-list-panel">
        <div class="inbox-list-header">
            <h3 class="inbox-list-title">{{conversationsLabel}}</h3>
            {{#if hasConversations}}
            <div class="inbox-list-count">{{conversationCount}} {{translate 'conversations' scope='ChatwootConversation' category='messages'}}</div>
            {{/if}}
        </div>
        
        <div class="inbox-conversation-list">
            {{#if hasConversations}}
                {{#each conversations}}
                <div class="inbox-conversation-item{{#if isSelected}} selected{{/if}} status-{{status}}" data-id="{{id}}">
                    <div class="inbox-conv-avatar">
                        {{#if hasAvatar}}
                        <img src="{{avatarUrl}}" alt="{{contactName}}" class="inbox-conv-avatar-img">
                        {{else}}
                        <div class="inbox-conv-avatar-initials" style="background-color: {{initialsColor}}">{{initials}}</div>
                        {{/if}}
                        <div class="inbox-conv-channel-badge channel-{{channelType}}">
                            <i class="{{channelIcon}}"></i>
                        </div>
                    </div>
                    <div class="inbox-conv-content">
                        <div class="inbox-conv-header">
                            <span class="inbox-conv-name">{{contactName}}</span>
                            <span class="inbox-conv-time">{{timeAgo}}</span>
                        </div>
                        {{#if inboxName}}
                        <div class="inbox-conv-inbox-row">{{inboxName}}</div>
                        {{/if}}
                        <div class="inbox-conv-message{{#unless hasMessage}} empty{{/unless}}">
                            {{#if hasMessage}}
                            <span class="inbox-conv-direction {{lastMessageType}}">
                                {{#if isIncoming}}
                                <i class="fas fa-arrow-down"></i>
                                {{else}}
                                <i class="fas fa-arrow-up"></i>
                                {{/if}}
                            </span>
                            <span class="inbox-conv-message-text">{{messagePreview}}</span>
                            {{else}}
                            {{translate 'No messages yet' scope='ChatwootConversation' category='messages'}}
                            {{/if}}
                        </div>
                        <div class="inbox-conv-footer">
                            <span class="inbox-conv-status status-{{status}}">{{statusLabel}}</span>
                        </div>
                    </div>
                </div>
                {{/each}}
            {{else}}
            <div class="inbox-empty-list">
                <i class="ti ti-messages" style="font-size: 48px; color: #d1d5db; margin-bottom: 16px;"></i>
                <p>{{noConversationsMessage}}</p>
            </div>
            {{/if}}
        </div>
    </div>
    
    <div class="inbox-iframe-panel">
        <div class="inbox-tabs">
            <div class="inbox-tab active" data-tab="chat">
                <i class="far fa-comment-dots"></i>
                <span>{{translate 'Chat' scope='ChatwootConversation' category='labels'}}</span>
            </div>
            <div class="inbox-tab" data-tab="opportunities">
                <i class="fas fa-dollar-sign"></i>
                <span>{{translate 'Opportunity' category='scopeNamesPlural'}}</span>
                <span class="inbox-tab-count" data-scope="opportunities"></span>
            </div>
            <div class="inbox-tab" data-tab="agendamentos">
                <i class="far fa-calendar-check"></i>
                <span>{{translate 'CAgendamento' category='scopeNamesPlural'}}</span>
                <span class="inbox-tab-count" data-scope="agendamentos"></span>
            </div>
            <div class="inbox-tab" data-tab="tasks">
                <i class="far fa-check-square"></i>
                <span>{{translate 'Task' category='scopeNamesPlural'}}</span>
                <span class="inbox-tab-count" data-scope="tasks"></span>
            </div>
        </div>
        
        <div class="inbox-tab-content active" data-tab="chat">
            <div class="inbox-chat-toolbar">
                <div class="inbox-agent-dropdown btn-group">
                    <button class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown">
                        <i class="fas fa-user"></i>
                        <span class="inbox-agent-label">{{translate 'Unassigned' scope='ChatwootConversation' category='labels'}}</span>
                        <span class="caret"></span>
                    </button>
                    <ul class="dropdown-menu inbox-agent-menu">
                        <li class="inbox-agent-loading">
                            <a role="button"><i class="fas fa-spinner fa-spin"></i> {{translate 'Loading...' scope='Global'}}</a>
                        </li>
                    </ul>
                </div>
                <div class="inbox-status-dropdown btn-group">
                    <button class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown">
                        <span class="inbox-status-label">{{translate 'Status'}}</span>
                        <span class="caret"></span>
                    </button>
                    <ul class="dropdown-menu">
                        <li><a role="button" class="action" data-action="changeStatus" data-status="open">{{translateOption 'open' field='status' scope='ChatwootConversation'}}</a></li>
                        <li><a role="button" class="action" data-action="changeStatus" data-status="pending">{{translateOption 'pending' field='status' scope='ChatwootConversation'}}</a></li>
                        <li><a role="button" class="action" data-action="changeStatus" data-status="resolved">{{translateOption 'resolved' field='status' scope='ChatwootConversation'}}</a></li>
                        <li><a role="button" class="action" data-action="changeStatus" data-status="snoozed">{{translateOption 'snoozed' field='status' scope='ChatwootConversation'}}</a></li>
                    </ul>
                </div>
                <div class="inbox-more-dropdown btn-group">
                    <button class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" title="{{translate 'More'}}">
                        <span class="fas fa-ellipsis-h"></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-right">
                        <li><a role="button" class="action" data-action="viewConversation"><i class="fas fa-eye"></i> {{translate 'View'}}</a></li>
                        <li><a role="button" class="action" data-action="removeConversation"><i class="fas fa-trash"></i> {{translate 'Remove'}}</a></li>
                    </ul>
                </div>
            </div>
            <div class="inbox-iframe-wrapper">
                <iframe class="inbox-iframe" src="" frameborder="0" allowfullscreen></iframe>
                <div class="inbox-iframe-placeholder">
                    <i class="ti ti-message-circle" style="font-size: 64px; color: #d1d5db; margin-bottom: 16px;"></i>
                    <p style="color: #6b7280; font-size: 16px;">{{noSelectionMessage}}</p>
                </div>
            </div>
        </div>
        
        <div class="inbox-tab-content" data-tab="opportunities">
            <div class="inbox-entity-search-wrapper">
                <div class="inbox-entity-search-container"></div>
                <div class="inbox-entity-create-btn">
                    <button class="btn btn-default btn-create-opportunity" title="{{translate 'Create'}}">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
            <div class="inbox-entity-list-container"></div>
        </div>
        
        <div class="inbox-tab-content" data-tab="agendamentos">
            <div class="inbox-entity-search-wrapper">
                <div class="inbox-entity-search-container"></div>
                <div class="inbox-entity-create-btn">
                    <button class="btn btn-default btn-create-agendamento" title="{{translate 'Create'}}">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
            <div class="inbox-entity-list-container"></div>
        </div>
        
        <div class="inbox-tab-content" data-tab="tasks">
            <div class="inbox-entity-search-wrapper">
                <div class="inbox-entity-search-container"></div>
                <div class="inbox-entity-create-btn">
                    <button class="btn btn-default btn-create-task" title="{{translate 'Create'}}">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
            <div class="inbox-entity-list-container"></div>
        </div>
    </div>
</div>
