<div class="virtual-folder-header">
    <a class="virtual-folder-toggle" role="button" data-action="toggleVirtualFolder" data-id="{{id}}">
        <span class="virtual-folder-icon {{iconClass}}"{{#if color}} style="color: {{color}}"{{/if}}></span>
        <span class="virtual-folder-label">{{label}}</span>
        <span class="virtual-folder-caret fas fa-chevron-down"></span>
    </a>
    <div class="virtual-folder-actions">
        <a class="action" role="button" data-action="quickCreate" title="{{translate 'Create' scope='Global'}}">
            <span class="fas fa-plus"></span>
        </a>
        <a class="dropdown-toggle" data-toggle="dropdown" role="button">
            <span class="fas fa-ellipsis-v"></span>
        </a>
        <ul class="dropdown-menu pull-right">
            <li><a role="button" data-action="refresh">{{translate 'Refresh' scope='Global'}}</a></li>
            <li><a role="button" data-action="viewAll">{{translate 'View All' scope='Global'}}</a></li>
        </ul>
    </div>
</div>
<ul class="virtual-folder-items{{#if isCollapsed}} hidden{{/if}}">
    {{#if isLoading}}
        <li class="virtual-folder-loading"><span class="fas fa-spinner fa-spin"></span></li>
    {{else if hasError}}
        <li class="virtual-folder-error">
            <span class="text-danger">{{errorMessage}}</span>
            <a role="button" data-action="refresh">{{translate 'Retry' scope='Global'}}</a>
        </li>
    {{else}}
        {{#each recordList}}
            <li class="virtual-folder-item">
                <a href="{{url}}">{{name}}</a>
            </li>
        {{/each}}
        {{#unless recordList.length}}
            <li class="virtual-folder-empty">{{translate 'No records found' scope='Global'}}</li>
        {{/unless}}
        {{#if hasMore}}
            <li class="virtual-folder-more">
                <a role="button" data-action="viewAll">
                    {{translate 'View all' scope='Global'}} ({{totalCount}})
                </a>
            </li>
        {{/if}}
    {{/if}}
</ul>
