<style>
.channel-picker-modal .channel-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.channel-picker-modal .channel-item {
    display: flex;
    align-items: center;
    padding: 12px 15px;
    border-bottom: 1px solid #e8e8e8;
    transition: background-color 0.15s ease;
}

.channel-picker-modal .channel-item:last-child {
    border-bottom: none;
}

.channel-picker-modal .channel-item:hover {
    background-color: #f8f9fa;
}

.channel-picker-modal .channel-icon {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background-color: #f0f2f5;
    margin-right: 12px;
    flex-shrink: 0;
    font-size: 16px;
    color: #555;
}

.channel-picker-modal .channel-info {
    flex: 1;
    min-width: 0;
}

.channel-picker-modal .channel-name {
    font-weight: 500;
    font-size: 14px;
    color: #333;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.channel-picker-modal .channel-type {
    font-size: 12px;
    color: #999;
    text-transform: capitalize;
}

.channel-picker-modal .channel-actions {
    display: flex;
    gap: 6px;
    flex-shrink: 0;
    margin-left: 12px;
}

.channel-picker-modal .channel-actions .btn {
    padding: 4px 10px;
    font-size: 12px;
    white-space: nowrap;
}

.channel-picker-modal .channel-item-loading {
    display: none;
    align-items: center;
    justify-content: center;
    padding: 6px 0;
}

.channel-picker-modal .channel-item-loading.active {
    display: flex;
}

.channel-picker-modal .channel-item.is-creating .channel-actions {
    display: none;
}

.channel-picker-modal .channel-item.is-creating .channel-item-loading {
    display: flex;
}

.channel-picker-modal .empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    text-align: center;
    color: #6b7280;
}

.channel-picker-modal .empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    color: #d1d5db;
}

.channel-picker-modal .empty-state-text {
    font-size: 14px;
}

.channel-picker-modal .loading-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    text-align: center;
    color: #6b7280;
}

.channel-picker-modal .loading-state .spinner {
    margin-bottom: 12px;
}

.channel-picker-modal .error-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    text-align: center;
    color: #dc3545;
}

.channel-picker-modal .error-state i {
    font-size: 48px;
    margin-bottom: 16px;
    color: #dc3545;
}
</style>

<div class="channel-picker-modal">
    {{#if isLoading}}
    <div class="loading-state">
        <div class="spinner">
            <span class="fas fa-spinner fa-spin fa-2x"></span>
        </div>
        <div>{{translate 'Loading...' scope='Global'}}</div>
    </div>
    {{else}}
    {{#if hasError}}
    <div class="error-state">
        <i class="fas fa-exclamation-triangle"></i>
        <div>{{errorMessage}}</div>
    </div>
    {{else}}
    {{#if hasChannels}}
    <ul class="channel-list">
        {{#each channels}}
        <li class="channel-item" data-index="{{@index}}">
            <div class="channel-icon">
                <i class="{{iconClass}}"></i>
            </div>
            <div class="channel-info">
                <div class="channel-name">{{inboxName}}</div>
                <div class="channel-type">{{channelTypeLabel}}</div>
            </div>
            <div class="channel-actions">
                {{#if hasConversation}}
                <button class="btn btn-default btn-sm action" data-action="openTab" data-index="{{@index}}" title="{{translate 'Open in New Tab' category='labels' scope='Contact'}}">
                    <i class="fas fa-external-link-alt"></i> {{translate 'Open in New Tab' category='labels' scope='Contact'}}
                </button>
                <button class="btn btn-primary btn-sm action" data-action="openDrawer" data-index="{{@index}}" title="{{translate 'Open in Drawer' category='labels' scope='Contact'}}">
                    <i class="fas fa-comments"></i> {{translate 'Open in Drawer' category='labels' scope='Contact'}}
                </button>
                {{else}}
                <button class="btn btn-default btn-sm action" data-action="openTab" data-index="{{@index}}" title="{{translate 'Start in New Tab' category='labels' scope='Contact'}}">
                    <i class="fas fa-external-link-alt"></i> {{translate 'Start in New Tab' category='labels' scope='Contact'}}
                </button>
                <button class="btn btn-primary btn-sm action" data-action="openDrawer" data-index="{{@index}}" title="{{translate 'Start Chat' category='labels' scope='Contact'}}">
                    <i class="fas fa-plus-circle"></i> {{translate 'Start Chat' category='labels' scope='Contact'}}
                </button>
                {{/if}}
            </div>
            <div class="channel-item-loading">
                <span class="fas fa-spinner fa-spin"></span>
                &nbsp;{{translate 'Creating conversation...' category='labels' scope='Contact'}}
            </div>
        </li>
        {{/each}}
    </ul>
    {{else}}
    <div class="empty-state">
        <i class="fas fa-comment-slash"></i>
        <div class="empty-state-text">
            {{translate 'No channels available' category='labels' scope='Contact'}}
        </div>
    </div>
    {{/if}}
    {{/if}}
    {{/if}}
</div>
