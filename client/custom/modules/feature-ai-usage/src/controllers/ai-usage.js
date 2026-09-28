import Controller from 'controller';

export default class extends Controller {
    actionIndex(options = {}) {
        this.main('feature-ai-usage:views/page', {scope: 'AiUsage', params: options});
    }
}
