<style>
/* Conversation Kanban Card Styles */
.conversation-card {
    background: #fff;
    border-radius: 12px;
    padding: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 1px solid #e5e7eb;
    position: relative;
}

.conversation-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    transform: translateY(-1px);
    border-color: #d1d5db;
}

.conversation-card:active {
    transform: translateY(0);
}

/* Card Header */
.conversation-card-header {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 8px;
}

/* Avatar - using chatwoot-avatar pattern */
.conversation-avatar-wrapper {
    position: relative;
    flex-shrink: 0;
}

.conversation-avatar-wrapper .chatwoot-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.conversation-avatar-wrapper .chatwoot-avatar-img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
}

.conversation-avatar-wrapper .chatwoot-avatar-initials {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-transform: uppercase;
}

/* Channel Badge */
.conversation-channel-badge {
    position: absolute;
    bottom: -2px;
    right: -2px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #fff;
    border: 2px solid #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.conversation-channel-badge.channel-whatsapp {
    color: #25D366;
    background: #dcfce7;
}

.conversation-channel-badge.channel-telegram {
    color: #0088cc;
    background: #e0f2fe;
}

.conversation-channel-badge.channel-instagram {
    color: #E4405F;
    background: #fce7f3;
}

.conversation-channel-badge.channel-facebook {
    color: #1877F2;
    background: #dbeafe;
}

.conversation-channel-badge.channel-email {
    color: #6b7280;
    background: #f3f4f6;
}

.conversation-channel-badge.channel-web {
    color: #8b5cf6;
    background: #f3e8ff;
}

.conversation-channel-badge.channel-default {
    color: #6b7280;
    background: #f3f4f6;
}

/* Contact Info */
.conversation-contact-info {
    flex: 1;
    min-width: 0;
    overflow: hidden;
}

.conversation-contact-name {
    font-weight: 600;
    color: #111827;
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 2px;
}

.conversation-inbox-name {
    font-size: 11px;
    color: #9ca3af;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Time */
.conversation-time {
    font-size: 11px;
    color: #9ca3af;
    flex-shrink: 0;
    margin-left: auto;
}

/* Message Preview */
.conversation-message {
    color: #6b7280;
    font-size: 13px;
    line-height: 1.4;
    margin-bottom: 10px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    word-break: break-word;
}

.conversation-message-empty {
    color: #9ca3af;
    font-style: italic;
}

/* Card Footer */
.conversation-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

/* Status Badge */
.conversation-status {
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 3px 8px;
    border-radius: 9999px;
}

.conversation-status.status-open {
    background: #fef3c7;
    color: #92400e;
}

.conversation-status.status-resolved {
    background: #d1fae5;
    color: #065f46;
}

.conversation-status.status-pending {
    background: #fce7f3;
    color: #9d174d;
}

.conversation-status.status-snoozed {
    background: #e0e7ff;
    color: #3730a3;
}

/* Meta Info */
.conversation-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 11px;
    color: #9ca3af;
}

.conversation-meta-item {
    display: flex;
    align-items: center;
    gap: 4px;
}

.conversation-meta-item i {
    font-size: 10px;
}

/* Assignee */
.conversation-assignee {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    color: #6b7280;
    background: #f3f4f6;
    padding: 2px 6px;
    border-radius: 4px;
}

.conversation-assignee i {
    font-size: 10px;
}

/* Opportunities Section */
.conversation-opportunities {
    margin-bottom: 10px;
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.conversation-opportunity {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 6px;
    background: #fef3c7;
    color: #92400e;
    cursor: pointer;
    transition: all 0.15s ease;
    max-width: 100%;
    overflow: hidden;
}

.conversation-opportunity:hover {
    filter: brightness(0.95);
    transform: translateY(-1px);
}

/* Create Opportunity Button */
.btn-create-opportunity {
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

.btn-create-opportunity:hover {
    border-color: #f59e0b;
    background: #fef3c7;
    color: #f59e0b;
}

.btn-create-opportunity i {
    font-size: 10px;
}

.conversation-opportunity i {
    font-size: 10px;
    flex-shrink: 0;
}

.conversation-opportunity-name {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Opportunity Stage Styles */
.conversation-opportunity.opp-stage-prospecting {
    background: #e0f2fe;
    color: #0369a1;
}

.conversation-opportunity.opp-stage-qualification {
    background: #f3e8ff;
    color: #7c3aed;
}

.conversation-opportunity.opp-stage-proposal {
    background: #fef3c7;
    color: #b45309;
}

.conversation-opportunity.opp-stage-negotiation {
    background: #ffedd5;
    color: #c2410c;
}

.conversation-opportunity.opp-stage-won {
    background: #d1fae5;
    color: #047857;
}

.conversation-opportunity.opp-stage-lost {
    background: #fee2e2;
    color: #b91c1c;
}

.conversation-opportunity.opp-stage-default {
    background: #f3f4f6;
    color: #4b5563;
}

/* Menu Container Override */
.conversation-card .item-menu-container {
    position: absolute;
    top: 8px;
    right: 8px;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.conversation-card:hover .item-menu-container {
    opacity: 1;
}

/* Unread indicator */
.conversation-unread-badge {
    background: #3b82f6;
    color: white;
    font-size: 10px;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 9999px;
    min-width: 18px;
    text-align: center;
}
</style>

<div class="conversation-card" data-id="{{id}}">
    {{#unless rowActionsDisabled}}
    <div class="item-menu-container">{{{itemMenu}}}</div>
    {{/unless}}
    
    <div class="conversation-card-header">
        <div class="conversation-avatar-wrapper">
            <div class="chatwoot-avatar">
                {{#if hasAvatar}}
                <img 
                    class="chatwoot-avatar-img" 
                    src="{{avatarUrl}}" 
                    alt="{{contactName}}"
                >
                {{/if}}
                <div 
                    class="chatwoot-avatar-initials" 
                    style="background-color: {{initialsColor}};{{#if hasAvatar}} display: none;{{/if}}"
                    title="{{contactName}}"
                >{{initials}}</div>
            </div>
            
            <div class="conversation-channel-badge channel-badge" data-channel="{{channelType}}">
                <i class="{{channelIcon}}"></i>
            </div>
        </div>
        
        <div class="conversation-contact-info">
            <div class="conversation-contact-name">{{contactName}}</div>
            {{#if inboxName}}
            <div class="conversation-inbox-name">{{inboxName}}</div>
            {{/if}}
        </div>
        
        <div class="conversation-time">{{timeAgo}}</div>
    </div>
    
    <div class="conversation-message {{#unless hasMessage}}conversation-message-empty{{/unless}}">
        {{#if hasMessage}}
        {{messagePreview}}
        {{else}}
        No messages yet
        {{/if}}
    </div>
    
    <div class="conversation-opportunities">
        {{#each opportunities}}
        <a href="#Opportunity/view/{{id}}" class="conversation-opportunity {{stageStyle}}" title="{{name}}{{#if stageLabel}} - {{stageLabel}}{{/if}}" data-id="{{id}}" onclick="event.stopPropagation();">
            <i class="ti ti-coin-filled"></i>
            <span class="conversation-opportunity-name">{{name}}</span>
        </a>
        {{/each}}
        <button type="button" class="btn-create-opportunity" title="Create Opportunity">
            <i class="fas fa-plus"></i>
        </button>
    </div>
    
    <div class="conversation-card-footer">
        <span class="conversation-status {{statusStyle}}">{{statusLabel}}</span>
        
        <div class="conversation-meta">
            {{#if messagesCount}}
            <span class="conversation-meta-item">
                <i class="fas fa-comment"></i>
                {{messagesCount}}
            </span>
            {{/if}}
            
            {{#if hasAssignee}}
            <span class="conversation-assignee">
                <i class="fas fa-user"></i>
                {{assigneeName}}
            </span>
            {{/if}}
        </div>
    </div>
</div>

