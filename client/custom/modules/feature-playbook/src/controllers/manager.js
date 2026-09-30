import Controller from 'controller';

export default class extends Controller {
    checkAccessGlobal() {
        return this.getAcl().check('Opportunity', 'read') || this.getAcl().check('Playbook', 'read');
    }

    actionIndex(options = {}) {
        this.main(options.accountId ? 'feature-playbook:views/workspace' : 'feature-playbook:views/manager', {params: options});
    }
}
