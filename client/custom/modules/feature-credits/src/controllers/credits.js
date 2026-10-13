import Controller from 'controller';

export default class extends Controller {
    actionAiConfiguration(options = {}) {
        this.main('feature-credits:views/ai-configuration', {scope: 'Credits', params: options});
    }

    actionBillingCatalog(options = {}) {
        this.main('feature-credits:views/billing-catalog', {scope: 'Credits', params: options});
    }

    actionEnrichmentRates(options = {}) {
        this.main('feature-credits:views/enrichment-rates', {scope: 'Credits', params: options});
    }

    actionCheckout(options = {}) {
        this.main('feature-credits:views/checkout', {scope: 'Credits', params: options});
    }

    actionIndex(options = {}) {
        this.main('feature-credits:views/page', {scope: 'Credits', params: options});
    }
}
