{{#if hasMultiple}}
<div class="navbar-config-selector">
    <div class="dropdown">
        <a
            class="navbar-config-selector-toggle dropdown-toggle"
            data-toggle="dropdown"
            role="button"
            tabindex="0"
            title="{{translate 'switchView' category='navbarConfig' scope='Global'}}"
        >
            {{#if activeConfig}}
                {{#if activeConfig.iconClass}}
                    <span class="{{activeConfig.iconClass}} navbar-config-icon"></span>
                {{/if}}
                {{#if activeConfig.color}}
                    <span class="fas fa-circle fa-xs navbar-config-color-dot" style="color: {{activeConfig.color}}"></span>
                {{/if}}
                <span class="navbar-config-name">{{activeConfig.name}}</span>
            {{else}}
                <span class="navbar-config-name">{{translate 'selectConfig' category='navbarConfig' scope='Global'}}</span>
            {{/if}}
            <span class="fas fa-caret-down navbar-config-caret"></span>
        </a>
        <ul class="dropdown-menu navbar-config-dropdown" role="menu">
            {{#each configList}}
            <li class="{{#if isActive}}active{{/if}}">
                <a
                    class="navbar-config-option"
                    role="button"
                    tabindex="0"
                    data-id="{{id}}"
                >
                    {{#if iconClass}}
                        <span class="{{iconClass}} text-muted"></span>
                    {{/if}}
                    {{#if color}}
                        <span class="fas fa-circle fa-xs" style="color: {{color}}; margin-right: var(--4px)"></span>
                    {{/if}}
                    <span>{{name}}</span>
                    {{#if isDefault}}
                        <span class="text-soft text-italic pull-right" style="font-size: 0.85em">(default)</span>
                    {{/if}}
                </a>
            </li>
            {{/each}}
        </ul>
    </div>
</div>
{{/if}}
