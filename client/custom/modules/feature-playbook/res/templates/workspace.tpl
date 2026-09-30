<div class="page-header"><h3>{{title}}</h3></div>
<p class="text-muted">{{description}}</p>
<div class="btn-group" role="group" aria-label="{{translate 'Playbooks'}}">
    <button type="button" class="btn btn-default {{#unless runs}}active{{/unless}}" aria-pressed="{{#if runs}}false{{else}}true{{/if}}" data-workspace-tab="templates">{{translate 'manageTemplates' scope='Playbook'}}</button>
    <button type="button" class="btn btn-default {{#if runs}}active{{/if}}" aria-pressed="{{#if runs}}true{{else}}false{{/if}}" data-workspace-tab="runs">{{translate 'manageRuns' scope='Playbook'}}</button>
</div>
<div class="panel panel-default">
    <div class="panel-body">
        <form data-workspace-filters class="form-inline">
            <div class="form-group">
                <input type="search" name="search" value="{{search}}" maxlength="200" class="form-control" placeholder="{{translate 'searchPlaybooks' scope='Playbook'}}" aria-label="{{translate 'searchPlaybooks' scope='Playbook'}}">
            </div>
            <div class="form-group">
                <select name="status" class="form-control" aria-label="{{translate 'status' scope='Playbook'}}">
                    <option value="">{{translate 'allStatuses' scope='Playbook'}}</option>
                    {{#each statuses}}<option value="{{value}}" {{#if selected}}selected{{/if}}>{{label}}</option>{{/each}}
                </select>
            </div>
            <button type="submit" class="btn btn-default">{{translate 'filterList' scope='Playbook'}}</button>
            <button type="button" class="btn btn-default" data-workspace-action="refresh" {{#if loading}}disabled{{/if}}>{{translate 'refresh' scope='Playbook'}}</button>
            {{#if canCreate}}<button type="button" class="btn btn-primary pull-right" data-workspace-action="new">{{translate 'newTemplate' scope='Playbook'}}</button>{{/if}}
        </form>
        {{#if opportunityId}}
        <div class="alert alert-info">
            {{translate 'filteredOpportunity' scope='Playbook'}}
            <button type="button" class="btn btn-link" data-workspace-action="clear">{{translate 'showAllRuns' scope='Playbook'}}</button>
        </div>
        {{/if}}
    </div>
    {{#if loading}}<div class="panel-body text-muted" role="status">{{translate 'loading' scope='Playbook'}}</div>{{/if}}
    {{#if error}}<div class="panel-body text-danger" role="alert">{{translate 'listError' scope='Playbook'}}</div>{{/if}}
    {{#if hasSnapshot}}
    <div class="table-responsive">
        <table class="table table-striped table-hover" aria-label="{{title}}">
            <thead><tr>
                <th scope="col">{{translate 'name' scope='Playbook'}}</th>
                {{#if runs}}<th scope="col">{{translate 'opportunity' scope='Playbook'}}</th>{{/if}}
                <th scope="col">{{translate 'status' scope='Playbook'}}</th>
                <th scope="col">{{#if runs}}{{translate 'progress' scope='Playbook'}}{{else}}{{translate 'stepCount' scope='Playbook'}}{{/if}}</th>
                <th scope="col">{{#if runs}}{{translate 'owner' scope='Playbook'}}{{else}}{{translate 'createdBy' scope='Playbook'}}{{/if}}</th>
                <th scope="col">{{#if runs}}{{translate 'startedAt' scope='Playbook'}}{{else}}{{translate 'updatedAt' scope='Playbook'}}{{/if}}</th>
                {{#unless runs}}<th scope="col">{{translate 'revision' scope='Playbook'}}</th>{{/unless}}
            </tr></thead>
            <tbody>
                {{#each rows}}
                <tr>
                    <td>{{#if ../runs}}<a href="{{opportunityUrl}}">{{name}}</a>{{else}}{{#if canEdit}}<button type="button" class="btn btn-link" data-workspace-template="{{id}}">{{name}}</button>{{else}}{{name}}{{/if}}{{/if}}</td>
                    {{#if ../runs}}<td><a href="{{opportunityUrl}}">{{opportunityName}}</a></td>{{/if}}
                    <td><span class="label label-default">{{statusLabel}}</span></td>
                    <td>{{#if ../runs}}<progress value="{{completed}}" max="{{progressMax}}" aria-label="{{translate 'progress' scope='Playbook'}}"></progress> {{completed}} / {{total}}{{else}}{{total}}{{/if}}</td>
                    <td>{{#if ../runs}}{{assignedUserName}}{{else}}{{createdByName}}{{/if}}</td>
                    <td>{{date}}</td>
                    {{#unless ../runs}}<td>{{revision}}</td>{{/unless}}
                </tr>
                {{else}}
                <tr><td colspan="7" class="text-muted">{{translate 'emptyList' scope='Playbook'}}</td></tr>
                {{/each}}
            </tbody>
        </table>
    </div>
    <div class="panel-footer">
        <button type="button" class="btn btn-default btn-sm" data-workspace-action="first" {{#unless hasPrevious}}disabled{{/unless}}>{{translate 'firstPage' scope='Playbook'}}</button>
        <button type="button" class="btn btn-default btn-sm" data-workspace-action="next" {{#unless hasNext}}disabled{{/unless}}>{{translate 'nextPage' scope='Playbook'}}</button>
    </div>
    {{/if}}
</div>
