{{#if streamUrl~}}
    <audio controls preload="none" src="{{streamUrl}}" class="full-width"></audio>
    <div class="small text-muted">
        <a href="{{streamUrl}}" target="_blank" rel="noopener noreferrer">Open recording</a>
    </div>
{{~else}}
    {{#if valueIsSet}}
        <span class="text-muted">Recording unavailable</span>
    {{else}}
        <span class="none-value">{{translate 'None'}}</span>
    {{/if}}
{{/if}}
