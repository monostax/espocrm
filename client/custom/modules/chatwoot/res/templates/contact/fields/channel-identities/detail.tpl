{{#unless isEmpty}}
<div class="channel-identities-container">
    {{#each itemList}}
    <div class="channel-identity-item">
        <span class="{{iconClass}} text-muted" title="{{typeLabel}}"></span>
        {{#if url}}
        <a href="{{url}}" target="_blank" rel="noopener noreferrer">{{display}}</a>
        {{else}}
        <span>{{display}}</span>
        {{/if}}
        {{#if secondary}}
        <span class="text-muted small">{{secondary}}</span>
        {{/if}}
        {{#if isPrimary}}
        <span
            class="fas fa-star fa-sm text-muted"
            title="{{translate 'isPrimary' category='fields' scope='ContactChannelIdentity'}}"
        ></span>
        {{/if}}
    </div>
    {{/each}}
</div>
{{else}}
    {{#if valueIsSet}}
    <span class="none-value">{{translate 'None'}}</span>
    {{else}}
    <span class="loading-value"></span>
    {{/if}}
{{/unless}}
