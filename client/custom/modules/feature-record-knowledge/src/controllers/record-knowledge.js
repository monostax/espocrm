import Controller from 'controller';

export default class extends Controller {
    actionRevision(options) {
        this.main('feature-record-knowledge:views/revision', {revisionId: options.id});
    }
}
