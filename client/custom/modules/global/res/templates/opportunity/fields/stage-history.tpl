<div class="small text-muted">
    {{translate 'Tracking since' scope='Opportunity'}}: {{trackingStartedAt}}
    <button type="button" class="btn btn-default btn-xs" data-role="refresh" title="{{translate 'Refresh'}}"><i class="fas fa-sync-alt"></i></button>
</div>
{{#if failed}}
<p class="text-danger">{{translate 'History unavailable' scope='Opportunity'}}</p>
{{else}}
{{#if partial}}<p class="text-muted small">{{translate 'Partial history explanation' scope='Opportunity'}}</p>{{/if}}
{{#if hasRows}}
<div class="table-responsive">
    <table class="table table-condensed">
        <thead><tr>
            <th>{{translate 'opportunityStage' category='fields' scope='Opportunity'}}</th>
            <th>{{translate 'Entered' scope='Opportunity'}}</th>
            <th>{{translate 'Exited' scope='Opportunity'}}</th>
            <th>{{translate 'Time spent' scope='Opportunity'}}</th>
            <th>{{translate 'Target' scope='Opportunity'}}</th>
            <th>{{translate 'Result' scope='Opportunity'}}</th>
        </tr></thead>
        <tbody>{{#each rows}}<tr>
            <td>{{stageName}}<div class="small text-muted">{{funnelName}}</div>{{#if isPartial}}<span class="small text-muted">{{translate 'Partial history' scope='Opportunity'}}</span>{{/if}}</td>
            <td>{{entered}}</td><td>{{exited}}</td><td>{{elapsed}}</td><td>{{target}}</td>
            <td class="{{#if overdue}}text-danger{{/if}}">{{result}}</td>
        </tr>{{/each}}</tbody>
    </table>
</div>
<div class="btn-group">
    {{#if hasPrevious}}<button type="button" class="btn btn-default btn-sm" data-role="previous">{{translate 'Previous' scope='Opportunity'}}</button>{{/if}}
    {{#if hasNext}}<button type="button" class="btn btn-default btn-sm" data-role="next">{{translate 'Next' scope='Opportunity'}}</button>{{/if}}
</div>
<h5>{{translate 'Total time per stage' scope='Opportunity'}}</h5>
{{#each summary}}
<div>{{stageName}} <span class="small text-muted">({{funnelName}})</span>: <strong>{{elapsed}}</strong> · {{visits}} {{translate 'visits' scope='Opportunity'}}{{#if isPartial}} · {{translate 'Partial history' scope='Opportunity'}}{{/if}}</div>
{{/each}}
{{else}}
<p class="text-muted">{{translate 'No stage history' scope='Opportunity'}}</p>
{{/if}}
{{/if}}
