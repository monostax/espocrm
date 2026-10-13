<div class="page-header"><h3>{{translate 'title' scope='CreditBillingCatalog'}}</h3></div>
<p>{{translate 'intro' scope='CreditBillingCatalog'}}</p>
{{#if error}}<p class="text-danger" role="alert">{{error}}</p>{{/if}}
{{#unless allowed}}<p>{{translate 'forbidden' scope='CreditBillingCatalog'}}</p>{{/unless}}
{{#if allowed}}
<div role="status" aria-live="polite">{{#if busy}}{{translate 'loading' scope='CreditBillingCatalog'}}{{/if}}</div>
<fieldset {{#if busy}}disabled{{/if}}>
<button type="button" class="btn btn-default" data-catalog-load>{{translate 'reload' scope='CreditBillingCatalog'}}</button>
{{#if card}}<p>{{translate 'current' scope='CreditBillingCatalog'}}: {{card.version}} / {{card.effectiveFrom}} UTC / {{card.catalogId}}</p>{{/if}}
{{#if published}}<p role="status">{{translate 'published' scope='CreditBillingCatalog'}}: {{published.version}} / {{published.effectiveFrom}} UTC / {{published.catalogId}}</p>{{/if}}
{{#if pending}}<p role="status">{{translate 'pending' scope='CreditBillingCatalog'}}</p>{{/if}}
{{#if command}}<h4>{{translate 'review' scope='CreditBillingCatalog'}}</h4><p>{{command.version}} / {{command.effectiveFrom}} UTC</p>{{/if}}
<p class="text-warning">{{translate 'completeCatalog' scope='CreditBillingCatalog'}}</p>
<p>{{translate 'versionsHelp' scope='CreditBillingCatalog'}}</p>
<form data-catalog-draft>
{{#unless command}}
<label>{{translate 'version' scope='CreditBillingCatalog'}}<input class="form-control" name="version" value="{{draft.version}}" required pattern="[a-z0-9][a-z0-9._:\-]{0,63}" maxlength="64"></label>
<label>{{translate 'effectiveFrom' scope='CreditBillingCatalog'}}<input class="form-control" name="effectiveFrom" value="{{effectiveInput}}" type="datetime-local" step="60" required></label>
{{/unless}}
<h4>{{translate 'products' scope='CreditBillingCatalog'}}</h4>
{{#each products}}
<fieldset class="well well-sm"><legend>{{translate 'product' scope='CreditBillingCatalog'}} {{index}}</legend>
{{#each fields}}<label>{{label}}{{#if ../../command}}<span class="form-control-static"> {{display}}</span>{{else}}{{#if options}}<select class="form-control" name="{{name}}">{{#each options}}<option value="{{value}}" {{#if selected}}selected{{/if}}>{{label}}</option>{{/each}}</select>{{else}}<input class="form-control" name="{{name}}" value="{{value}}" {{#if required}}required{{/if}} {{#if pattern}}pattern="{{pattern}}"{{/if}}>{{/if}}{{/if}}</label> {{/each}}
{{#unless ../command}}<button class="btn btn-default" type="button" data-catalog-remove="products" data-index="{{index}}">{{translate 'remove' scope='CreditBillingCatalog'}}</button>{{/unless}}
</fieldset>{{/each}}
{{#unless command}}<button class="btn btn-default" type="button" data-catalog-add="products">{{translate 'addProduct' scope='CreditBillingCatalog'}}</button>{{/unless}}
<h4>{{translate 'offers' scope='CreditBillingCatalog'}}</h4>
<p>{{translate 'offerHelp' scope='CreditBillingCatalog'}}</p>
{{#each offers}}
<fieldset class="well well-sm"><legend>{{translate 'offer' scope='CreditBillingCatalog'}} {{index}}</legend>
{{#each fields}}<label>{{label}}{{#if ../../command}}<span class="form-control-static"> {{display}}</span>{{else}}{{#if options}}<select class="form-control" name="{{name}}">{{#each options}}<option value="{{value}}" {{#if selected}}selected{{/if}}>{{label}}</option>{{/each}}</select>{{else}}<input class="form-control" name="{{name}}" value="{{value}}" {{#if required}}required{{/if}} {{#if pattern}}pattern="{{pattern}}"{{/if}}>{{/if}}{{/if}}</label> {{/each}}
{{#unless ../command}}<button class="btn btn-default" type="button" data-catalog-remove="offers" data-index="{{index}}">{{translate 'remove' scope='CreditBillingCatalog'}}</button>{{/unless}}
</fieldset>{{/each}}
{{#unless command}}<button class="btn btn-default" type="button" data-catalog-add="offers">{{translate 'addOffer' scope='CreditBillingCatalog'}}</button>
<p><button class="btn btn-default" type="submit">{{translate 'review' scope='CreditBillingCatalog'}}</button></p>{{/unless}}
</form>
{{#if command}}<button type="button" class="btn btn-primary" data-catalog-publish>{{translate 'publish' scope='CreditBillingCatalog'}}</button>{{/if}}
{{#if canEdit}}<button type="button" class="btn btn-default" data-catalog-edit>{{translate 'edit' scope='CreditBillingCatalog'}}</button>{{/if}}
</fieldset>
{{/if}}
