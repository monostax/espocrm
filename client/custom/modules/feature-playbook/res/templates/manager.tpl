<div class="page-header"><h3>{{translate "Playbooks"}}</h3></div>
<div class="btn-group" role="group" aria-label="{{translate 'Playbooks'}}">
    <button type="button" class="btn btn-default {{#if tabTemplates}}active{{/if}}" data-manager-tab="templates">{{translate "manageTemplates" scope="Playbook"}}</button>
    <button type="button" class="btn btn-default {{#if tabRuns}}active{{/if}}" data-manager-tab="runs">{{translate "manageRuns" scope="Playbook"}}</button>
</div>
<div class="panel panel-default">
    <div class="panel-body">
        <p class="text-muted">{{translate "contextHint" scope="Playbook"}}</p>
        <button type="button" class="btn btn-default" data-manager-action="select">{{translate "selectOpportunity" scope="Playbook"}}</button>
        {{#if hasContext}}<a href="#Opportunity/view/{{opportunityId}}">{{opportunityName}}</a>{{/if}}
        {{#if opportunityId}}<button type="button" class="btn btn-link" data-manager-action="refresh">{{translate "refresh" scope="Playbook"}}</button>{{/if}}
    </div>
</div>
{{#if loading}}<p role="status" class="text-muted">{{translate "loading" scope="Playbook"}}</p>{{/if}}
{{#if error}}<p role="alert" class="text-danger">{{translate "error" scope="Playbook"}}</p>{{/if}}
{{#if hasContext}}
    {{#if tabTemplates}}
        {{#if canCreate}}<button type="button" class="btn btn-primary" data-manager-action="new">{{translate "newTemplate" scope="Playbook"}}</button>{{/if}}
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>{{translate "name" scope="Playbook"}}</th><th>{{translate "status" scope="Playbook"}}</th><th>{{translate "revision" scope="Playbook"}}</th><th>{{translate "Edit"}}</th></tr></thead>
                <tbody>
                    {{#each templates}}
                    <tr><td>{{name}}</td><td>{{statusLabel}}</td><td>{{revision}}</td><td>{{#if canEdit}}<button type="button" class="btn btn-default btn-sm" data-manager-template="{{id}}">{{translate "Edit"}}</button>{{/if}}</td></tr>
                    {{else}}
                    <tr><td colspan="4" class="text-muted">{{translate "noTemplates" scope="Playbook"}}</td></tr>
                    {{/each}}
                </tbody>
            </table>
        </div>
    {{/if}}
    {{#if tabRuns}}<div data-manager-runs>{{{runs}}}</div>{{/if}}
{{/if}}
