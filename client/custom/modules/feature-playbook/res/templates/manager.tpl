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
        <div data-manager-templates>{{{templates}}}</div>
    {{/if}}
    {{#if tabRuns}}<div data-manager-runs>{{{runs}}}</div>{{/if}}
{{/if}}
