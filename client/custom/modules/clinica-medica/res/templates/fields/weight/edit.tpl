<div class="input-group input-group-weight">
    <span class="input-group-item">
        <input
            type="text"
            class="main-element form-control radius-left numeric-text"
            data-name="{{name}}"
            value="{{value}}"
            autocomplete="espo-{{name}}"
            pattern="[\-]?[0-9,.]*"
            {{#if params.maxLength}} maxlength="{{params.maxLength}}"{{/if}}
        >
    </span>
    {{#if multipleUnits}}
    <span class="input-group-item">
        <select
            data-name="{{unitFieldName}}"
            class="form-control radius-right"
        >{{{options unitList unitValue}}}</select>
    </span>
    {{else}}
    <span class="input-group-addon radius-right">{{defaultUnit}}</span>
    {{/if}}
</div>

