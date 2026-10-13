<div aria-busy="{{#if busy}}true{{else}}false{{/if}}">
    <h2>{{translate 'title' scope='CreditCheckout'}}</h2>
    <p>{{translate 'description' scope='CreditCheckout'}}</p>
    <div class="row">
        <div class="col-sm-6 form-group"><label for="checkout-tenant">{{translate 'tenant' scope='CreditCheckout'}}</label>
            <select id="checkout-tenant" class="form-control" data-checkout-tenant>
                <option value="">{{translate 'selectTenant' scope='CreditCheckout'}}</option>
                {{#each tenants}}<option value="{{id}}" {{#if selected}}selected{{/if}}>{{name}}</option>{{/each}}
            </select>
        </div>
        <div class="col-sm-6 form-group"><button type="button" class="btn btn-default" data-checkout-refresh {{#if busy}}disabled{{/if}}>{{translate 'refresh' scope='CreditCheckout'}}</button> <a href="{{backUrl}}">{{translate 'back' scope='CreditCheckout'}}</a></div>
    </div>
    {{#if error}}<div class="alert alert-danger" role="alert">{{error}}</div>{{/if}}
    {{#if busy}}<p role="status">{{translate 'working' scope='CreditCheckout'}}</p>{{/if}}
    {{#if storageError}}<div class="alert alert-warning" role="alert">{{translate 'storageError' scope='CreditCheckout'}}</div>{{/if}}
    {{#if hasContext}}
    <fieldset {{#if busy}}disabled{{/if}}>
        {{#if pending}}<div class="alert alert-warning" role="status">{{translate 'pendingCommand' scope='CreditCheckout'}} <button type="button" class="btn btn-default" data-checkout-retry>{{translate 'retry' scope='CreditCheckout'}}</button></div>{{/if}}
        <form data-checkout-country class="form-inline">
            <label for="checkout-country">{{translate 'country' scope='CreditCheckout'}} ({{billingCountry}})</label>
            <select id="checkout-country" name="billingCountry" class="form-control" required>
                {{#each countries}}<option value="{{id}}" {{#if selected}}selected{{/if}}>{{id}}</option>{{/each}}
            </select>
            <button class="btn btn-default" {{#if pending}}disabled{{/if}} {{#if storageError}}disabled{{/if}}>{{translate 'saveCountry' scope='CreditCheckout'}}</button>
        </form>
        <h3>{{translate 'offers' scope='CreditCheckout'}}</h3>
        <div class="row">{{#each offers}}
            <div class="col-sm-4"><div class="panel panel-default"><div class="panel-body">
                <h4>{{creditsDisplay}} {{translate 'credits' scope='CreditCheckout'}}</h4>
                <p><strong>{{currency}} {{price}}</strong> — {{#if monthly}}{{translate 'monthly' scope='CreditCheckout'}}{{else}}{{translate 'oneTime' scope='CreditCheckout'}}{{/if}}</p>
                <p>{{#if monthly}}{{translate 'expiry' scope='CreditCheckout'}}{{else}}{{translate 'purchaseTerms' scope='CreditCheckout'}}{{/if}}</p>
                {{#if blocked}}<p class="text-muted">{{blocked}}</p>{{/if}}
                <button type="button" class="btn btn-primary" data-checkout-offer="{{offerId}}" {{#unless eligible}}disabled{{/unless}}>{{translate 'review' scope='CreditCheckout'}}</button>
            </div></div></div>
        {{else}}<p class="text-muted">{{translate 'noOffers' scope='CreditCheckout'}}</p>{{/each}}</div>

        {{#if selectedTerms}}
        <section class="panel panel-default"><div class="panel-heading">{{translate 'reviewTerms' scope='CreditCheckout'}}</div><div class="panel-body">
            <p><strong>{{selectedTerms.currency}} {{selectedTerms.price}}</strong> / {{selectedTerms.creditsDisplay}} {{translate 'credits' scope='CreditCheckout'}} — {{#if selectedTerms.monthly}}{{translate 'monthly' scope='CreditCheckout'}}{{else}}{{translate 'oneTime' scope='CreditCheckout'}}{{/if}}</p>
            <p>{{#if selectedTerms.monthly}}{{translate 'expiry' scope='CreditCheckout'}}{{else}}{{translate 'purchaseTerms' scope='CreditCheckout'}}{{/if}}</p>
            <p class="text-muted">{{translate 'offerVersion' scope='CreditCheckout'}}: {{selectedTerms.offerId}} / {{selectedTerms.version}}</p>
            <form data-checkout-accept>
                <div class="checkbox"><label><input type="checkbox" name="acceptTerms" required> {{translate 'agree' scope='CreditCheckout'}}</label></div>
                <button class="btn btn-primary">{{translate 'accept' scope='CreditCheckout'}}</button>
            </form>
        </div></section>
        {{/if}}

        {{#if acceptedTerms}}
        <section class="panel panel-default"><div class="panel-heading">{{translate 'acceptedTerms' scope='CreditCheckout'}}</div><div class="panel-body">
            <p><strong>{{acceptedTerms.currency}} {{acceptedTerms.price}}</strong> / {{acceptedTerms.creditsDisplay}} {{translate 'credits' scope='CreditCheckout'}} — {{#if acceptedTerms.monthly}}{{translate 'monthly' scope='CreditCheckout'}}{{else}}{{translate 'oneTime' scope='CreditCheckout'}}{{/if}}</p>
            <p>{{#if acceptedTerms.monthly}}{{translate 'expiry' scope='CreditCheckout'}}{{else}}{{translate 'purchaseTerms' scope='CreditCheckout'}}{{/if}}</p>
            <p class="text-muted">{{translate 'offerVersion' scope='CreditCheckout'}}: {{acceptedTerms.offerId}} / {{acceptedTerms.version}}</p>
            <p role="status">{{stateLabel}}</p>
            {{#if sandbox}}<div class="alert alert-info">{{translate 'sandbox' scope='CreditCheckout'}}</div>{{/if}}
            {{#if canCancel}}<button type="button" class="btn btn-default" data-checkout-cancel>{{translate 'cancel' scope='CreditCheckout'}}</button>{{/if}}
            {{#if subscriptionStatus}}<p role="status">{{subscriptionStatus}}</p>{{/if}}
            {{#if canManageSubscription}}
                <button type="button" class="btn btn-default" data-subscription-reconcile>{{translate 'reconcileSubscription' scope='CreditCheckout'}}</button>
                {{#if cancellationPending}}<p role="status">{{translate 'cancellationPending' scope='CreditCheckout'}}</p>{{else}}
                <form data-subscription-cancel>
                    <div class="checkbox"><label><input type="checkbox" name="stopSubscription" required> {{translate 'stopSubscriptionTerms' scope='CreditCheckout'}}</label></div>
                    <button class="btn btn-danger">{{translate 'stopSubscription' scope='CreditCheckout'}}</button>
                </form>
                {{/if}}
            {{/if}}
            {{#if canPayer}}
            <h4>{{translate 'payer' scope='CreditCheckout'}}</h4>
            <form data-checkout-payer autocomplete="off">
                <div class="form-group"><label for="checkout-name">{{translate 'name' scope='CreditCheckout'}}</label><input id="checkout-name" class="form-control" name="payerName" maxlength="160" required></div>
                <div class="form-group"><label for="checkout-tax">{{translate 'cpfCnpj' scope='CreditCheckout'}}</label><input id="checkout-tax" class="form-control" name="cpfCnpj" inputmode="numeric" maxlength="18" required></div>
                <div class="form-group"><label for="checkout-email">{{translate 'email' scope='CreditCheckout'}}</label><input id="checkout-email" class="form-control" type="email" name="email" maxlength="254"></div>
                <div class="checkbox"><label><input type="checkbox" name="notifications"> {{translate 'notifications' scope='CreditCheckout'}}</label></div>
                <p class="text-muted">{{translate 'payerNotice' scope='CreditCheckout'}}</p>
                <button class="btn btn-primary">{{translate 'savePayer' scope='CreditCheckout'}}</button>
            </form>
            {{/if}}
            {{#if canCharge}}
            <form data-checkout-charge>
                <p>{{translate 'existingPayer' scope='CreditCheckout'}}</p>
                <div class="form-group"><label for="checkout-method">{{translate 'method' scope='CreditCheckout'}}</label><select id="checkout-method" name="billingType" class="form-control"><option value="BOLETO">Boleto</option><option value="PIX">PIX</option><option value="CREDIT_CARD">{{translate 'card' scope='CreditCheckout'}}</option></select></div>
                <div class="form-group"><label for="checkout-due">{{translate 'dueDate' scope='CreditCheckout'}}</label><input id="checkout-due" name="dueDate" class="form-control" type="date" min="{{dueDate}}" value="{{dueDate}}" required></div>
                <p>{{translate 'chargeNotice' scope='CreditCheckout'}}</p>
                <button class="btn btn-primary">{{translate 'createCharge' scope='CreditCheckout'}}</button>
            </form>
            {{/if}}
            {{#if canRecover}}<p>{{translate 'unknownCreation' scope='CreditCheckout'}}</p><button type="button" class="btn btn-default" data-checkout-recover>{{translate 'recover' scope='CreditCheckout'}}</button>{{/if}}
            {{#if canPayment}}<button type="button" class="btn btn-primary" data-checkout-payment>{{translate 'getPayment' scope='CreditCheckout'}}</button>{{/if}}
            {{#if paymentState}}<p role="status">{{paymentState}}</p>{{/if}}
            {{#if invoiceUrl}}<p><a class="btn btn-primary" href="{{invoiceUrl}}" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">{{translate 'openPayment' scope='CreditCheckout'}}</a></p>{{/if}}
        </div></section>
        {{/if}}

        <h3>{{translate 'recent' scope='CreditCheckout'}}</h3>
        <div class="table-responsive"><table class="table table-striped"><thead><tr><th scope="col">{{translate 'offer' scope='CreditCheckout'}}</th><th scope="col">{{translate 'status' scope='CreditCheckout'}}</th><th scope="col">{{translate 'actions' scope='CreditCheckout'}}</th></tr></thead><tbody>
        {{#each checkouts}}<tr><td>{{currency}} {{price}} / {{creditsDisplay}} {{translate 'credits' scope='CreditCheckout'}}</td><td>{{stateLabel}}</td><td><button type="button" class="btn btn-link" data-checkout-open="{{id}}">{{translate 'open' scope='CreditCheckout'}}</button></td></tr>{{else}}<tr><td colspan="3">{{translate 'empty' scope='CreditCheckout'}}</td></tr>{{/each}}
        </tbody></table></div>
        {{#if hasMoreCheckouts}}<p class="text-muted">{{translate 'recentLimit' scope='CreditCheckout'}}</p>{{/if}}
    </fieldset>
    {{/if}}
</div>
