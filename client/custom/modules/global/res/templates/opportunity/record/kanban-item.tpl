<style>
/* Conversations Section for Opportunity Kanban Card */
.opportunity-conversations {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #f3f4f6;
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

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

.opportunity-conversation.conv-status-default:hover {
    color: #4b5563;
}
</style>

<div class="panel panel-default {{#if isStarred}} starred {{~/if}} ">
    <div class="panel-body">
        {{#each layoutDataList}}
        <div>
            <div class="form-group">
                <div
                    class="field{{#if isAlignRight}} field-right-align{{/if}}{{#if isLarge}} field-large{{/if}}{{#if isMuted}} text-muted{{/if}}"
                    data-name="{{name}}"
                >{{{var key ../this}}}</div>
            </div>
        </div>
        {{/each}}
        
        {{#if hasConversations}}
        <div class="opportunity-conversations">
            {{#each conversations}}
            <a href="#ChatwootConversation/view/{{id}}" class="opportunity-conversation {{statusStyle}}" title="{{name}}{{#if statusLabel}} - {{statusLabel}}{{/if}}" data-id="{{id}}">
                <i class="fas fa-comments"></i>
                <span class="opportunity-conversation-name">{{name}}</span>
            </a>
            {{/each}}
        </div>
        {{/if}}
    </div>
</div>

