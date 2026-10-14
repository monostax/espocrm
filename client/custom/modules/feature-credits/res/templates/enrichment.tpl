<div class="credit-enrichment">
    <p class="text-muted">{{translate 'intro' scope='CreditEnrichment'}}</p>
    <div role="status" aria-live="polite">{{#if busy}}{{translate 'loading' scope='CreditEnrichment'}}{{/if}}</div>
    {{#if error}}<p class="text-danger" role="alert">{{error}}</p>{{/if}}
    {{#if hasContext}}
    <fieldset {{#if busy}}disabled{{/if}}>
    {{#if canQuote}}
    <form data-enrichment-quote>
        <div class="form-group"><label>{{translate 'product' scope='CreditEnrichment'}}
            <select class="form-control" name="product">{{#each products}}<option value="{{value}}">{{label}}</option>{{/each}}<option value="combined">{{translate 'combined' scope='CreditEnrichment'}}</option></select>
        </label></div>
        <div class="checkbox"><label><input type="checkbox" name="waterfall">{{translate 'waterfall' scope='CreditEnrichment'}}</label></div>
        <div class="form-group"><label>{{translate 'budget' scope='CreditEnrichment'}}<input class="form-control" name="budget" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,4})?"></label></div>
        <p class="text-muted">{{translate 'budgetHelp' scope='CreditEnrichment'}}</p>
        {{#if account}}
        <div class="form-group"><label>{{translate 'count' scope='CreditEnrichment'}}<input class="form-control" type="number" name="count" min="1" max="50" value="10"></label></div>
        <div class="form-group"><label>{{translate 'titles' scope='CreditEnrichment'}}<input class="form-control" name="titles" maxlength="2000"></label></div>
        <div class="form-group"><label>{{translate 'seniorities' scope='CreditEnrichment'}}<input class="form-control" name="seniorities" maxlength="2000"></label></div>
        {{/if}}
        <div class="checkbox"><label><input type="checkbox" name="refresh">{{translate 'paidRefresh' scope='CreditEnrichment'}}</label></div>
        <button class="btn btn-default" type="submit">{{translate 'quote' scope='CreditEnrichment'}}</button>
    </form>
    {{/if}}
    {{#if saved}}<button type="button" class="btn btn-default" data-enrichment-resume>{{translate 'resume' scope='CreditEnrichment'}}</button>{{/if}}
    {{#if canReset}}<button type="button" class="btn btn-default" data-enrichment-new>{{translate 'newQuote' scope='CreditEnrichment'}}</button>{{/if}}
    {{#if receipt}}
        <h4>{{translate 'maximum' scope='CreditEnrichment'}}: {{maximum}}</h4>
        <p>{{translate 'combinedHelp' scope='CreditEnrichment'}}</p>
        <p>{{translate 'finderHelp' scope='CreditEnrichment'}}</p>
        <dl>{{#each routes}}<dt>{{product}}</dt><dd>{{#each steps}}<span>{{translate 'source' scope='CreditEnrichment'}} {{source}}: {{credits}} </span>{{else}}{{translate 'noRoute' scope='CreditEnrichment'}}{{/each}}</dd>{{/each}}</dl>
        <p>{{translate 'settings' scope='CreditEnrichment'}}: {{translate 'waterfall' scope='CreditEnrichment'}} {{#if receipt.waterfall}}{{translate 'yes' scope='CreditEnrichment'}}{{else}}{{translate 'no' scope='CreditEnrichment'}}{{/if}};
        {{translate 'paidRefresh' scope='CreditEnrichment'}} {{#if receipt.refresh}}{{translate 'yes' scope='CreditEnrichment'}}{{else}}{{translate 'no' scope='CreditEnrichment'}}{{/if}}</p>
        {{#each progress}}<p>{{product}}: {{state}} {{stopReason}}</p>{{/each}}
        {{#if canConfirm}}<button type="button" class="btn btn-primary" data-enrichment-confirm>{{translate 'confirm' scope='CreditEnrichment'}}</button>{{/if}}
    {{/if}}
    <hr>
    <h4>{{translate 'history' scope='CreditEnrichment'}}</h4>
    <label>{{translate 'product' scope='CreditEnrichment'}}<select class="form-control" data-enrichment-history-product>{{#each products}}<option value="{{value}}" {{#if selected}}selected{{/if}}>{{label}}</option>{{/each}}</select></label>
    <button type="button" class="btn btn-default" data-enrichment-history>{{translate 'loadHistory' scope='CreditEnrichment'}}</button>
    <div class="table-responsive"><table class="table"><thead><tr><th scope="col">{{translate 'product' scope='CreditEnrichment'}}</th><th scope="col">{{translate 'source' scope='CreditEnrichment'}}</th><th scope="col">{{translate 'actor' scope='CreditEnrichment'}}</th><th scope="col">{{translate 'purchasedAt' scope='CreditEnrichment'}}</th><th scope="col">{{translate 'acquiredAt' scope='CreditEnrichment'}}</th><th scope="col">{{translate 'credits' scope='CreditEnrichment'}}</th><th scope="col">{{translate 'application' scope='CreditEnrichment'}}</th></tr></thead><tbody>
    {{#each items}}<tr><td><button class="btn btn-link" type="button" data-enrichment-review="{{receipt.id}}">{{product}}</button></td><td>{{translate 'source' scope='CreditEnrichment'}} {{receipt.source}} / {{provenance.delivery}}</td><td>{{provenance.actorId}}</td><td>{{receipt.purchasedAt}}</td><td>{{receipt.acquiredAt}}</td><td>{{credits}}</td><td>{{#if application.applied}}{{translate 'applied' scope='CreditEnrichment'}}{{else}}{{translate 'purchasedOnly' scope='CreditEnrichment'}}{{/if}}</td></tr>{{/each}}
    </tbody></table></div>
    {{#if next}}<button class="btn btn-default" type="button" data-enrichment-more>{{translate 'more' scope='CreditEnrichment'}}</button>{{/if}}
    {{#if detail}}
        <h4>{{translate 'review' scope='CreditEnrichment'}}</h4>
        <dl>{{#each values}}<dt>{{field}}</dt><dd>{{value}}</dd>{{/each}}</dl>
        {{#if detail.application.reason}}<p role="status">{{detail.application.reason}}</p>{{/if}}
        {{#if detail.application.available}}<form data-enrichment-apply>
            {{#if finder}}
            {{#if detail.application.creationForm.available}}
            <div class="checkbox"><label><input name="createIfUnmatched" type="checkbox">{{translate 'createIfUnmatched' scope='CreditEnrichment'}}</label></div>
            <fieldset data-enrichment-create disabled hidden>
            {{#each creationFields}}<label>{{label}}
                {{#if isEnum}}<select class="form-control" name="contact-{{name}}" {{#if required}}required{{/if}}><option value=""></option>{{#each options}}<option value="{{this}}">{{this}}</option>{{/each}}</select>
                {{else}}{{#if isBool}}<select class="form-control" name="contact-{{name}}" {{#if required}}required{{/if}}><option value=""></option><option value="true">{{translate 'yes' scope='CreditEnrichment'}}</option><option value="false">{{translate 'no' scope='CreditEnrichment'}}</option></select>
                {{else}}{{#if isText}}<textarea class="form-control" name="contact-{{name}}" {{#if required}}required{{/if}} {{#if maxLength}}maxlength="{{maxLength}}"{{/if}}></textarea>
                {{else}}<input class="form-control" name="contact-{{name}}" type="{{inputType}}" {{#if required}}required{{/if}} {{#if maxLength}}maxlength="{{maxLength}}"{{/if}}>{{/if}}{{/if}}{{/if}}
            </label>{{/each}}
            {{#if detail.application.creationForm.teamsAllowed}}<label>{{translate 'teamsIds' scope='CreditEnrichment'}}<select class="form-control" name="teamsIds" multiple {{#if detail.application.creationForm.teamsRequired}}required{{/if}}>{{#each detail.application.creationForm.teams}}<option value="{{id}}">{{name}}</option>{{/each}}</select></label>{{/if}}
            </fieldset>
            {{else}}<p>{{translate 'creationUnavailable' scope='CreditEnrichment'}}</p>{{/if}}
            {{/if}}
            {{#each choices}}<div class="form-group"><label>{{field}} <span class="text-muted">{{translate 'current' scope='CreditEnrichment'}}: {{current}}</span>
                <select class="form-control" data-enrichment-field="{{field}}"><option value="">{{translate 'preserve' scope='CreditEnrichment'}}</option>{{#each options}}<option value="{{this}}">{{this}}</option>{{/each}}</select>
            </label></div>{{/each}}
            <button class="btn btn-primary" type="submit">{{translate 'apply' scope='CreditEnrichment'}}</button>
        </form>{{/if}}
    {{/if}}
    </fieldset>
    {{/if}}
</div>
