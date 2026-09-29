<div class="row">
    <div class="col-md-7 center-block" style="float: none;">
        <div class="page-header"><h3>{{translate 'Configurations' scope='Configurations'}}</h3></div>

        <div class="admin-content admin-for-user">
            <div class="admin-search-container">
                <input
                    type="text"
                    maxlength="64"
                    placeholder="{{translate 'Search'}}"
                    data-name="quick-search"
                    class="form-control"
                    spellcheck="false"
                >
            </div>
            <div class="admin-tables-container">
                {{#each panelDataList}}
                <details class="admin-content-group" data-index="{{index}}" open>
                    <summary>
                        <span class="fas fa-chevron-right admin-disclosure-icon" aria-hidden="true"></span>
                        <h4>{{label}}</h4>
                    </summary>
                    {{#each sectionList}}
                    <section class="admin-content-section" data-index="{{index}}">
                    {{#if label}}<h5>{{label}}</h5>{{/if}}
                    {{#each lists}}
                    {{#if secondary}}
                    <details class="admin-history">
                        <summary>
                            <span class="fas fa-chevron-right admin-disclosure-icon" aria-hidden="true"></span>
                            {{translate 'History and Diagnostics' scope='Configurations'}}
                        </summary>
                    {{/if}}
                    <table class="table table-admin-panel" data-name="{{../name}}">
                        {{#each itemList}}
                        <tr class="admin-content-row" data-index="{{index}}">
                            <td>
                                <div>
                                {{#if iconClass}}
                                <span class="icon {{iconClass}}"></span>
                                {{/if}}
                                <a
                                    {{#if url}}href="{{url}}"{{else}}role="button"{{/if}}
                                    {{#if target}}target="{{target}}"{{/if}}
                                    tabindex="0"
                                    {{#if action}} data-action="{{action}}"{{/if}}
                                >{{label}}</a>
                                </div>
                            </td>
                            <td>{{translate description scope='Configurations' category='descriptions'}}</td>
                        </tr>
                        {{/each}}
                    </table>
                    {{#if secondary}}</details>{{/if}}
                    {{/each}}
                    </section>
                    {{/each}}
                </details>
                {{/each}}
                <div class="no-data hidden">{{translate 'No Data'}}</div>
            </div>
        </div>
    </div>
</div>
