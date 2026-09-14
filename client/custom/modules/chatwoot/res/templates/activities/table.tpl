{{#if topBar}}
    <div class="list-buttons-container clearfix">
        <div class="btn-group actions fix-position">
            <button
                type="button"
                class="btn btn-default btn-xs-wide dropdown-toggle actions-button hidden"
                data-toggle="dropdown"
            >{{translate 'Actions'}} <span class="caret"></span></button>
            <ul class="dropdown-menu actions-menu">
                {{#each massActionDataList}}
                    {{#if this}}
                        <li {{#if hidden}}class="hidden"{{/if}}>
                            <a role="button" tabindex="0" data-action="{{name}}" class="mass-action">
                                {{#if iconClass}}<span class="item-icon {{iconClass}}"></span>{{/if}}
                                <span class="item-text">{{translate name category="massActions"}}</span>
                            </a>
                        </li>
                    {{/if}}
                {{/each}}
            </ul>
        </div>
        <span class="selected-count text-muted pull-right" role="status" aria-live="polite"></span>
    </div>
{{/if}}

<div class="list chatwoot-activities-table {{#if showMoreActive}}has-show-more{{/if}}" tabindex="-1">
    <table class="table">
        <thead>
            <tr>
                {{#if checkboxes}}
                    <th scope="col" class="checkbox-cell" data-name="r-checkbox" style="width: {{checkboxColumnWidth}};">
                        <span class="select-all-container">
                            <input
                                type="checkbox"
                                class="select-all form-checkbox form-checkbox-small"
                                aria-label="{{translate 'selectAllLoaded' scope='ChatwootActivities'}}"
                                {{#unless collectionLength}}disabled{{/unless}}
                            >
                        </span>
                    </th>
                {{/if}}
                {{#each headerDefs}}
                    <th
                        scope="col"
                        class="field-header-cell {{className}}"
                        title="{{label}}"
                        style="{{#if width}}width: {{width}};{{/if}}{{#if align}} text-align: {{align}};{{/if}}"
                        {{#if name}}data-name="{{name}}"{{/if}}
                    >
                        {{label}}
                    </th>
                {{/each}}
            </tr>
        </thead>
        <tbody>
            {{#each rowDataList}}
                <tr data-id="{{id}}" class="list-row">{{{var id ../this}}}</tr>
            {{else}}
                <tr>
                    <td colspan="{{columnCount}}">
                        <div class="no-data">{{translate 'No Data'}}</div>
                    </td>
                </tr>
            {{/each}}
        </tbody>
    </table>

    {{#if showMoreEnabled}}
        <div
            class="show-more {{#unless showMoreActive}}hidden{{/unless}}"
            data-owner-cid="{{viewObject.cid}}"
        >
            <a
                role="button"
                tabindex="0"
                class="btn btn-default btn-block"
                data-action="showMore"
                data-owner-cid="{{viewObject.cid}}"
            >
                {{#if showCount}}
                    <div class="pull-right text-muted more-count">{{moreCountFormatted}}</div>
                {{/if}}
                <span>{{translate 'Show more'}}</span>
            </a>
        </div>
    {{/if}}
</div>
