<style>
.calendar-user-color {
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
.calendar-user-color:hover {
    transform: scale(1.2);
    box-shadow: 0 0 0 2px rgba(0,0,0,0.1);
}
.calendar-user-name {
    vertical-align: middle;
}
.calendar-dropdown-menu .dropdown-header {
    font-size: 11px;
    text-transform: uppercase;
    color: #999;
    padding: 8px 15px 4px;
}
.calendar-dropdown-menu .dropdown-header .fas {
    margin-right: 5px;
}
</style>
{{#each visibleModeDataList}}
<button class="btn btn-text strong{{#ifEqual mode ../mode}} active{{/ifEqual}}" data-action="mode" data-mode="{{mode}}" title="{{label}}"><span class="hidden-md hidden-sm hidden-xs">{{label}}</span><span class="visible-md visible-sm visible-xs">{{labelShort}}</span></button>
{{/each}}
<div class="btn-group" role="group">
    <button type="button" class="btn btn-text dropdown-toggle" data-toggle="dropdown"><span class="fas fa-ellipsis-h"></span></button>
    <ul class="dropdown-menu pull-right calendar-dropdown-menu">
        {{#each hiddenModeDataList}}
            <li>
                <a
                    role="button"
                    tabindex="0"
                    class="{{#ifEqual mode ../mode}} active{{/ifEqual}}"
                    data-action="mode"
                    data-mode="{{mode}}"
                >{{label}}</a>
            </li>
        {{/each}}

        {{#if hasUserFilter}}
            {{#if hiddenModeDataList.length}}
                <li class="divider"></li>
            {{/if}}
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
                            class="calendar-user-color"
                            style="background-color: {{color}};"
                            data-action="changeUserColor"
                            data-user-id="{{id}}"
                            title="{{translate 'Change Color' scope='Calendar'}}"
                        ></span>
                        <span class="calendar-user-name">{{name}}</span>
                    </a>
                </li>
            {{/each}}
        {{/if}}

        <li class="divider"></li>
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
        {{#if hasMoreItems}}
            <li class="divider"></li>
        {{/if}}
        {{#if isCustomViewAvailable}}
            <li>
                <a
                    role="button"
                    tabindex="0"
                    data-action="createCustomView"
                >{{translate 'Create Shared View' scope='Calendar'}}</a>
            </li>
        {{/if}}
        <li>
            <a
                role="button"
                tabindex="0"
                data-action="manageUsers"
            >
                <span class="fas fa-cog text-muted"></span>
                {{translate 'Manage Users' scope='Calendar'}}
            </a>
        </li>
        {{#if hasWorkingTimeCalendarLink}}
            <li>
                <a href="#WorkingTimeCalendar">{{translate 'WorkingTimeCalendar' category='scopeNamesPlural'}}</a>
            </li>
        {{/if}}
    </ul>
</div>
