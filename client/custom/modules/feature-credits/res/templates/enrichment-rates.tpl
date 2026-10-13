<div class="page-header"><h3>{{translate 'title' scope='CreditEnrichmentRates'}}</h3></div>
<p>{{translate 'intro' scope='CreditEnrichmentRates'}}</p>
{{#if error}}<p class="text-danger" role="alert">{{error}}</p>{{/if}}
{{#unless allowed}}<p>{{translate 'forbidden' scope='CreditEnrichmentRates'}}</p>{{/unless}}
{{#if allowed}}
<div role="status" aria-live="polite">{{#if busy}}{{translate 'loading' scope='CreditEnrichmentRates'}}{{/if}}</div>
<fieldset {{#if busy}}disabled{{/if}}>
<button type="button" class="btn btn-default" data-rate-current>{{translate 'reload' scope='CreditEnrichmentRates'}}</button>
{{#if published}}<p role="status">{{translate 'published' scope='CreditEnrichmentRates'}}: {{published.version}} / {{published.effectiveFrom}} UTC / {{published.policyId}}</p>{{/if}}
{{#if card}}
<h4>{{translate 'current' scope='CreditEnrichmentRates'}}: {{card.version}}</h4>
<p>{{card.effectiveFrom}} UTC / {{card.policyId}}</p>
<h4>{{translate 'routing' scope='CreditEnrichmentRates'}}: {{card.routingPolicy}}</h4>
<dl>{{#each routes}}<dt>{{name}}</dt><dd>{{providers}}</dd>{{/each}}</dl>
<p>{{translate 'routingHelp' scope='CreditEnrichmentRates'}}</p>
{{#if pending}}<p role="status">{{translate 'pending' scope='CreditEnrichmentRates'}}</p>{{/if}}
{{#if command}}<h4>{{translate 'review' scope='CreditEnrichmentRates'}}</h4><p>{{command.version}} / {{command.effectiveFrom}} UTC</p>{{/if}}
<form data-rate-draft>
{{#unless command}}
<label>{{translate 'version' scope='CreditEnrichmentRates'}}<input class="form-control" name="version" required pattern="[a-z0-9][a-z0-9._:\-]{0,63}" maxlength="64"></label>
<label>{{translate 'effectiveFrom' scope='CreditEnrichmentRates'}}<input class="form-control" name="effectiveFrom" type="datetime-local" step="60" required></label>
{{/unless}}
<div class="table-responsive"><table class="table"><thead><tr>
<th scope="col">{{translate 'target' scope='CreditEnrichmentRates'}}</th><th scope="col">{{translate 'provider' scope='CreditEnrichmentRates'}}</th><th scope="col">{{translate 'product' scope='CreditEnrichmentRates'}}</th><th scope="col">{{translate 'credits' scope='CreditEnrichmentRates'}}</th>
</tr></thead><tbody>{{#each rates}}<tr><td>{{target}}</td><td>{{provider}}</td><td>{{product}} {{#if inactive}}({{translate 'inactive' scope='CreditEnrichmentRates'}}){{/if}}</td><td>{{#if ../command}}{{credits}}{{else}}<input aria-label="{{target}} {{provider}} {{product}}" name="rate-{{index}}" value="{{credits}}" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,4})?" required {{#if inactive}}readonly{{/if}}>{{/if}}</td></tr>{{/each}}</tbody></table></div>
{{#unless command}}<button class="btn btn-default" type="submit">{{translate 'review' scope='CreditEnrichmentRates'}}</button>{{/unless}}
</form>
{{#if command}}<button type="button" class="btn btn-primary" data-rate-publish>{{translate 'publish' scope='CreditEnrichmentRates'}}</button>{{/if}}
{{#if canEdit}}<button type="button" class="btn btn-default" data-rate-edit>{{translate 'edit' scope='CreditEnrichmentRates'}}</button>{{/if}}
{{/if}}
</fieldset>
{{/if}}
