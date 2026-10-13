<div class="page-header"><h3>{{translate 'title' scope='CreditAiConfiguration'}}</h3></div>
{{#unless allowed}}<div class="alert alert-warning" role="alert">{{translate 'forbidden' scope='CreditAiConfiguration'}}</div>{{else}}
<p>{{translate 'immutable' scope='CreditAiConfiguration'}}</p>
<p>{{translate 'baseRates' scope='CreditAiConfiguration'}}</p>
<p>{{translate 'nativeWarning' scope='CreditAiConfiguration'}}</p>
{{#if error}}<div class="alert alert-danger" role="alert">{{error}}</div>{{/if}}
{{#if busy}}<p role="status">{{translate 'loading' scope='CreditAiConfiguration'}}</p>{{/if}}
{{#if published}}<div class="alert alert-success" role="status">{{translate 'published' scope='CreditAiConfiguration'}} <code>{{published.id}}</code> {{published.version}} <code>{{published.policyId}}</code></div>{{/if}}
{{#unless command}}
<div class="btn-group">{{#each modes}}<button type="button" class="btn btn-default" data-config-mode="{{value}}" {{#if ../busy}}disabled{{/if}}>{{label}}</button>{{/each}}</div>
<h4>{{operationLabel}}</h4>
<form data-config-lookup>
<fieldset {{#if busy}}disabled{{/if}}>
<legend>{{translate 'lookup' scope='CreditAiConfiguration'}}</legend>
<label>{{translate 'originalId' scope='CreditAiConfiguration'}} <input class="form-control" name="originalId" required></label>
{{#unless model}}<label>{{translate 'tenantId' scope='CreditAiConfiguration'}} <input class="form-control" name="tenantId" required></label>{{/unless}}
<button class="btn btn-default" type="submit">{{translate 'load' scope='CreditAiConfiguration'}}</button>
</fieldset></form>
{{/unless}}
{{#if original}}<h4>{{translate 'originalTerms' scope='CreditAiConfiguration'}} <code>{{original.id}}</code></h4><dl>{{#each original.fields}}<dt>{{label}}</dt><dd>{{value}}</dd>{{/each}}</dl>{{/if}}
{{#if replacement}}<p class="text-warning">{{translate 'replacementNotice' scope='CreditAiConfiguration'}}</p>{{/if}}
{{#if command}}
<h4>{{translate 'review' scope='CreditAiConfiguration'}} — {{operationLabel}}</h4>
<dl>{{#each fields}}<dt>{{label}}</dt><dd>{{display}}</dd>{{/each}}</dl>
{{#if pending}}<p role="status">{{translate 'pending' scope='CreditAiConfiguration'}}</p>{{/if}}
<button type="button" class="btn btn-primary" data-config-publish {{#if busy}}disabled{{/if}}>{{translate 'publish' scope='CreditAiConfiguration'}}</button>
{{#if canEdit}}<button type="button" class="btn btn-default" data-config-edit {{#if busy}}disabled{{/if}}>{{translate 'edit' scope='CreditAiConfiguration'}}</button>{{/if}}
{{else}}
<form data-config-draft><fieldset {{#if busy}}disabled{{/if}}>
<legend>{{translate 'newTerms' scope='CreditAiConfiguration'}}</legend>
{{#each fields}}<div class="form-group"><label for="credit-config-{{key}}">{{label}}</label>
<input id="credit-config-{{key}}" class="form-control" name="{{key}}" type="{{type}}" value="{{value}}" {{#if required}}required{{/if}} {{#if pattern}}pattern="{{pattern}}"{{/if}} {{#if step}}step="{{step}}"{{/if}} {{#if min}}min="{{min}}"{{/if}} {{#if max}}max="{{max}}"{{/if}}></div>{{/each}}
<button type="submit" class="btn btn-primary">{{translate 'review' scope='CreditAiConfiguration'}}</button>
</fieldset></form>
{{/if}}
{{/unless}}
