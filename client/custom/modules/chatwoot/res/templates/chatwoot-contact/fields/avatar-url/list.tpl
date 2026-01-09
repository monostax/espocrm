<div class="chatwoot-avatar chatwoot-avatar-list">
    {{#if avatarUrl}}
    <img 
        class="chatwoot-avatar-img" 
        src="{{avatarUrl}}" 
        alt="{{contactName}}"
        width="24"
        height="24"
    >
    {{/if}}
    <div 
        class="chatwoot-avatar-initials" 
        style="background-color: {{initialsColor}};{{#if avatarUrl}} display: none;{{/if}}"
        title="{{contactName}}"
    >{{initials}}</div>
</div>

