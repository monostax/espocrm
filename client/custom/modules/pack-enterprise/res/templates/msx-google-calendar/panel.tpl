{{#unless isBlocked}}
<div class="panel panel-default panel-{{name}}" data-panel-name="{{name}}">
    {{#if hasFields}}
    <div class="panel-body">
        {{#each fields}}
            <div class="cell cell-{{./this}} form-group">
                <label class="control-label">{{translate ./this scope='MsxGoogleCalendarUser' category='fields'}}</label>
                <div class="field field-{{./this}}" data-name="{{./this}}"> {{var this ../this}} </div>
            </div>
        {{/each}}
    </div>
    {{/if}}
</div>
{{else}}
<div class="panel panel-default">
    <div class="panel-body">
        <span class="text-muted">{{translate 'Loading...' scope='Global'}}</span>
    </div>
</div>
{{/unless}}
