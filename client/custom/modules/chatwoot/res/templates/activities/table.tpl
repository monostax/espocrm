<div class="list chatwoot-activities-table {{#if showMoreActive}}has-show-more{{/if}}" tabindex="-1">
    <table class="table">
        <thead>
            <tr>
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
