<div class="chatwoot-avatar chatwoot-avatar-detail">
    {{#if avatarUrl}}
    <img 
        class="chatwoot-avatar-img" 
        src="{{avatarUrl}}" 
        alt="{{contactName}}"
        width="64"
        height="64"
    >
    {{/if}}
    <div 
        class="chatwoot-avatar-initials chatwoot-avatar-initials-large" 
        style="background-color: {{initialsColor}};{{#if avatarUrl}} display: none;{{/if}}"
        title="{{contactName}}"
    >{{initials}}</div>
    {{#if avatarUrl}}
    <div class="chatwoot-avatar-url">
        <a href="{{avatarUrl}}" target="_blank" class="text-muted small">{{avatarUrl}}</a>
    </div>
    {{/if}}
</div>

