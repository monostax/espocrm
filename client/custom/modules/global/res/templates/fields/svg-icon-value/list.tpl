{{#if isNotEmpty}}
<span style="display:inline-flex;align-items:center;gap:6px;">
    {{#if iconUrl}}
    <img src="{{iconUrl}}" alt="" width="14" height="14" style="display:inline-block;vertical-align:middle;">
    {{/if}}
    <span>{{displayValue}}</span>
</span>
{{else}}
{{#if valueIsSet}}<span class="none-value">{{translate 'None'}}</span>{{else}}
<span class="loading-value"></span>{{/if}}
{{/if}}
