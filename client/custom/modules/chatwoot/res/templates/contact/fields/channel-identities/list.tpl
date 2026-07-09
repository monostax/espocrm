{{#unless isEmpty}}
{{#each itemList}}
<span class="channel-identity-item" style="margin-right: 8px; white-space: nowrap;">
    <span class="{{iconClass}} text-muted" title="{{typeLabel}}"></span>
    {{#if url}}
    <a href="{{url}}" target="_blank" rel="noopener noreferrer">{{display}}</a>
    {{else}}
    <span>{{display}}</span>
    {{/if}}
</span>
{{/each}}
{{/unless}}
