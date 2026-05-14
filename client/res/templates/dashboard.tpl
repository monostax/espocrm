<div class="page-header dashboard-header">
    <div class="row">
        <div class="col-sm-4 dashboard-header-titles">
            {{#if titleText}}
            <h3 class="dashboard-title" title="{{titleText}}">{{titleText}}</h3>
            {{/if}}
            {{#if displayTitle}}
            <div class="text-muted dashboard-description" title="{{descriptionText}}">{{descriptionText}}</div>
            {{/if}}
        </div>
        <div class="col-sm-8 clearfix dashboard-header-controls">
            {{#if showDateRange}}
            <div
                class="dashboard-date-range"
                data-action="toggleDateRange"
                role="button"
                tabindex="0"
                title="{{translate 'Date Range' category='labels' scope='Global'}}"
            >
                <span class="far fa-calendar-alt"></span>
                <span class="dashboard-date-range-label">{{dateRangeLabel}}</span>
                <div class="dashboard-date-range-popover" hidden>
                    <div class="dashboard-date-range-presets">
                        <button type="button" class="btn btn-text btn-block" data-action="selectDatePreset" data-preset="last7Days">{{translate 'Last 7 Days' category='labels' scope='Global'}}</button>
                        <button type="button" class="btn btn-text btn-block" data-action="selectDatePreset" data-preset="last30Days">{{translate 'Last 30 Days' category='labels' scope='Global'}}</button>
                        <button type="button" class="btn btn-text btn-block" data-action="selectDatePreset" data-preset="thisMonth">{{translate 'This Month' category='labels' scope='Global'}}</button>
                        <button type="button" class="btn btn-text btn-block" data-action="selectDatePreset" data-preset="lastMonth">{{translate 'Last Month' category='labels' scope='Global'}}</button>
                        <button type="button" class="btn btn-text btn-block" data-action="selectDatePreset" data-preset="thisYear">{{translate 'This Year' category='labels' scope='Global'}}</button>
                        <button type="button" class="btn btn-text btn-block" data-action="selectDatePreset" data-preset="last12Months">{{translate 'Last 12 Months' category='labels' scope='Global'}}</button>
                    </div>
                    <div class="dashboard-date-range-inputs">
                        <div class="form-group">
                            <label class="control-label">{{translate 'From' category='labels' scope='Global'}}</label>
                            <input type="text" class="form-control input-sm dashboard-date-range-start" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label class="control-label">{{translate 'To' category='labels' scope='Global'}}</label>
                            <input type="text" class="form-control input-sm dashboard-date-range-end" autocomplete="off">
                        </div>
                        <div class="dashboard-date-range-actions">
                            <button type="button" class="btn btn-default btn-sm" data-action="cancelDateRange">{{translate 'Cancel'}}</button>
                            <button type="button" class="btn btn-primary btn-sm" data-action="applyDateRange">{{translate 'Apply'}}</button>
                        </div>
                    </div>
                </div>
            </div>
            {{/if}}
            {{#ifNotEqual dashboardLayout.length 1}}
            <div class="btn-group dashboard-tabs">
                {{#each dashboardLayout}}
                    <button
                        class="btn btn-text{{#ifEqual @index ../currentTab}} active{{/ifEqual}}"
                        data-action="selectTab"
                        data-tab="{{@index}}"
                    >{{name}}</button>
                {{/each}}
            </div>
            {{/ifNotEqual}}
            {{#unless layoutReadOnly}}
            <div class="btn-group dashboard-buttons">
                <button
                    class="btn btn-text btn-icon dropdown-toggle"
                    data-toggle="dropdown"
                ><span class="fas fa-ellipsis-h"></span></button>
                <ul class="dropdown-menu pull-right dropdown-menu-with-icons">
                    <li>
                        <a role="button" tabindex="0" data-action="editTabs">
                            <span class="fas fa-pencil-alt fa-sm"></span>
                            <span class="item-text">{{translate 'Edit Dashboard'}}</span>
                        </a>
                    </li>
                    {{#if hasAdd}}
                    <li>
                        <a role="button" tabindex="0" data-action="addDashlet">
                            <span class="fas fa-plus"></span>
                            <span class="item-text">{{translate 'Add Dashlet'}}</span>
                        </a>
                    </li>
                    {{/if}}
                </ul>
            </div>
            {{/unless}}
        </div>
    </div>
</div>
<div class="dashlets grid-stack grid-stack-12">{{{dashlets}}}</div>
