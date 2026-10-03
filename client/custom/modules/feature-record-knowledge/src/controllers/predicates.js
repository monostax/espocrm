import Controller from 'controllers/record';

export default class extends Controller {
    actionIndex() { this.main('feature-record-knowledge:views/predicates'); }
    actionList() { this.actionIndex(); }
}
