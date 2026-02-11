{{#if closeButton}}
<a role="button" tabindex="0" class="pull-right close" data-action="close" aria-hidden="true"><span class="fas fa-times"></span></a>
{{/if}}
<h4>{{header}}</h4>

<div class="cell form-group">
    <div class="field">
        {{#if userName}}
        <span class="text-muted">{{userName}}</span>
        {{/if}}
    </div>
</div>

<div class="cell form-group">
    <div class="field popup-notification-message">
        {{{message}}}
    </div>
</div>

{{#if entityType}}
{{#if entityId}}
<div class="cell form-group">
    <div class="field">
        <a href="#{{entityType}}/view/{{entityId}}" data-action="close">
            {{#if entityName}}{{entityName}}{{else}}{{translate entityType category='scopeNames'}}{{/if}}
        </a>
    </div>
</div>
{{/if}}
{{/if}}
