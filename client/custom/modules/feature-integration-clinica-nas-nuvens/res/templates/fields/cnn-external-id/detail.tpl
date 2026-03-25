{{#if url~}}
    <a
        href="{{url}}"
        target="_blank"
        rel="noopener noreferrer"
    >{{value}}</a>
{{~else}}
    {{#if valueIsSet}}<span class="none-value">{{translate 'None'}}</span>{{else}}
    <span class="loading-value"></span>{{/if}}
{{/if}}