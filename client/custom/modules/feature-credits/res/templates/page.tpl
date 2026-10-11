<div aria-busy="{{#if loading}}true{{else}}false{{/if}}">
    <h2>{{translate 'title' scope='Credits'}}</h2>
    <p class="text-muted">{{translate 'description' scope='Credits'}}</p>
    <div class="row">
        <div class="col-sm-6 form-group"><label for="credit-tenant">{{translate 'tenant' scope='Credits'}}</label>
            <select id="credit-tenant" class="form-control" data-credit-tenant>
                <option value="">{{translate 'selectTenant' scope='Credits'}}</option>
                {{#each tenants}}<option value="{{id}}" {{#if selected}}selected{{/if}}>{{name}}</option>{{/each}}
            </select>
        </div>
        <div class="col-sm-6 form-group"><button class="btn btn-default" data-credit-refresh {{#if loading}}disabled{{/if}}>{{translate 'refresh' scope='Credits'}}</button> <a href="{{legacyUrl}}">{{translate 'legacyUsage' scope='Credits'}}</a></div>
    </div>
    {{#if error}}<div class="alert alert-danger" role="alert">{{error}}</div>{{/if}}
    {{#if noAccess}}<p role="status">{{translate 'forbidden' scope='Credits'}}</p>{{/if}}
    {{#if loading}}<p role="status">{{translate 'loading' scope='Credits'}}</p>{{/if}}
    {{#if hasData}}
        {{#if walletMissing}}<div class="alert alert-info" role="status">{{translate 'walletMissing' scope='Credits'}}</div>{{/if}}
        {{#if exhausted}}<div class="alert alert-warning" role="status">{{translate 'exhausted' scope='Credits'}}</div>{{/if}}
        <div class="row">{{#each cards}}<div class="col-sm-6 col-md-3"><div class="panel panel-default"><div class="panel-heading">{{label}}</div><div class="panel-body"><strong class="numeric-text">{{value}}</strong></div></div></div>{{/each}}</div>
        <p class="text-muted">{{translate 'balanceObserved' scope='Credits'}} {{observedAt}} UTC</p>
    {{/if}}
    <nav aria-label="{{translate 'reports' scope='Credits'}}">{{#each tabs}}<button class="btn {{#if selected}}btn-primary{{else}}btn-default{{/if}}" data-credit-tab="{{key}}" aria-current="{{#if selected}}page{{else}}false{{/if}}">{{label}}</button> {{/each}}</nav>
    {{#if sourceMessage}}<p role="status">{{sourceMessage}}</p>{{/if}}
    {{#if hasData}}
        <p class="text-muted">{{translate 'amountNotice' scope='Credits'}}</p>
        <p class="text-muted">{{translate 'pageObserved' scope='Credits'}} {{pageObservedAt}} UTC. {{translate 'livePages' scope='Credits'}}</p>
        {{#if empty}}<p role="status">{{translate 'empty' scope='Credits'}}</p>{{else}}
        <div class="table-responsive"><table class="table table-striped"><caption class="sr-only">{{translate 'reports' scope='Credits'}}</caption>
            <thead><tr>{{#each headers}}<th scope="col">{{this}}</th>{{/each}}{{#if showSource}}<th scope="col">{{translate 'source' scope='Credits'}}</th>{{/if}}</tr></thead>
            <tbody>{{#each rows}}<tr>{{#each cells}}<td>{{value}}</td>{{/each}}{{#if ../showSource}}<td><button class="btn btn-link" data-credit-source="{{sourceId}}">{{translate 'openSource' scope='Credits'}}</button></td>{{/if}}</tr>{{/each}}</tbody>
        </table></div>{{/if}}
    {{/if}}
    <div class="btn-group" role="group" aria-label="{{translate 'pagination' scope='Credits'}}"><button class="btn btn-default" data-credit-previous {{#unless canPrevious}}disabled{{/unless}}>{{translate 'previous' scope='Credits'}}</button><button class="btn btn-default" data-credit-next {{#unless canNext}}disabled{{/unless}}>{{translate 'next' scope='Credits'}}</button></div>
</div>
