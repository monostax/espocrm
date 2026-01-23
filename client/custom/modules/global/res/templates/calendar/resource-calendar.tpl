<style>
.resource-calendar-user-color {
    display: inline-block;
    width: 14px;
    height: 14px;
    border-radius: 3px;
    margin-right: 8px;
    vertical-align: middle;
    cursor: pointer;
    border: 1px solid rgba(0,0,0,0.1);
    transition: transform 0.1s, box-shadow 0.1s;
}
.resource-calendar-user-color:hover {
    transform: scale(1.2);
    box-shadow: 0 0 0 2px rgba(0,0,0,0.1);
}
.resource-calendar-user-name {
    vertical-align: middle;
}
.resource-calendar-dropdown-menu .dropdown-header {
    font-size: 11px;
    text-transform: uppercase;
    color: #999;
    padding: 8px 15px 4px;
}
.resource-calendar-dropdown-menu .dropdown-header .fas {
    margin-right: 5px;
}
</style>
{{#if header}}
<div class="row button-container">
    <div class="col-sm-4 col-xs-5">
        <div class="btn-group range-switch-group">
            <button class="btn btn-text btn-icon" data-action="prev"><span class="fas fa-chevron-left"></span></button>
            <button class="btn btn-text btn-icon" data-action="next"><span class="fas fa-chevron-right"></span></button>
        </div>
        <div class="btn-group range-switch-group">
        <button class="btn btn-text strong" data-action="today" title="{{todayLabel}}">
            <span class="hidden-sm hidden-xs">{{todayLabel}}</span><span class="visible-sm visible-xs">{{todayLabelShort}}</span>
        </button>
        </div>

        <div class="btn-group" role="group">
            <button
                type="button"
                class="btn btn-text btn-icon dropdown-toggle"
                data-toggle="dropdown"
                title="{{translate 'Users' scope='Calendar'}}"
            ><span class="fas fa-users fa-sm"></span> <span class="caret"></span></button>
            <ul class="dropdown-menu resource-calendar-dropdown-menu">
                {{#if hasUserFilter}}
                    <li class="dropdown-header">
                        <span class="fas fa-users text-muted"></span>
                        {{translate 'Users' scope='Calendar'}}
                    </li>
                    {{#each userFilterDataList}}
                        <li>
                            <a
                                role="button"
                                tabindex="0"
                                data-action="toggleUserFilter"
                                data-user-id="{{id}}"
                            >
                                <span class="fas fa-check filter-check-icon check-icon pull-right{{#if disabled}} hidden{{/if}}"></span>
                                <span
                                    class="resource-calendar-user-color"
                                    style="background-color: {{color}};"
                                    data-action="changeUserColor"
                                    data-user-id="{{id}}"
                                    title="{{translate 'Change Color' scope='Calendar'}}"
                                ></span>
                                <span class="resource-calendar-user-name">{{name}}</span>
                            </a>
                        </li>
                    {{/each}}
                    <li class="divider"></li>
                {{/if}}

                <li class="dropdown-header">
                    <span class="fas fa-filter text-muted"></span>
                    {{translate 'Entity Types' scope='Calendar'}}
                </li>
                {{#each scopeFilterDataList}}
                    <li>
                        <a
                            role="button"
                            tabindex="0"
                            data-action="toggleScopeFilter"
                            data-name="{{scope}}"
                        >
                            <span class="fas fa-check filter-check-icon check-icon pull-right{{#if disabled}} hidden{{/if}}"></span>
                            <div>{{translate scope category='scopeNamesPlural'}}</div>
                        </a>
                    </li>
                {{/each}}

                <li class="divider"></li>
                <li>
                    <a
                        role="button"
                        tabindex="0"
                        data-action="showResourceOptions"
                    >
                        <span class="fas fa-cog text-muted"></span>
                        {{translate 'Manage Users' scope='Calendar'}}
                    </a>
                </li>
            </ul>
        </div>

        <button
            class="btn btn-text{{#unless isCustomView}} hidden{{/unless}} btn-icon"
            data-action="editCustomView"
            title="{{translate 'Edit'}}"
        ><span class="fas fa-pencil-alt fa-sm"></span></button>
    </div>

    <div class="date-title col-sm-4 col-xs-7">
    <h4><span style="cursor: pointer;" data-action="refresh" title="{{translate 'Refresh'}}"></span></h4></div>

    <div class="col-sm-4 col-xs-12">
        <div class="btn-group pull-right mode-buttons">
            {{{modeButtons}}}
        </div>
    </div>
</div>
{{/if}}

<div class="calendar"></div>
