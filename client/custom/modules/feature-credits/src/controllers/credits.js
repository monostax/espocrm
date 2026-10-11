import Controller from 'controller';

export default class extends Controller {
    actionIndex(options = {}) {
        this.main('feature-credits:views/page', {scope: 'Credits', params: options});
    }
}
