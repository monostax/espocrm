import Controller from 'controller';

export default class extends Controller {
    actionCheckout(options = {}) {
        this.main('feature-credits:views/checkout', {scope: 'Credits', params: options});
    }

    actionIndex(options = {}) {
        this.main('feature-credits:views/page', {scope: 'Credits', params: options});
    }
}
