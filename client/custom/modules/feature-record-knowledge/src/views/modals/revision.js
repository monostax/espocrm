import ModalView from 'views/modal';

export default class extends ModalView {
    templateContent = `<p>{{revision.createdAt}} · {{revision.contentHash}}</p><pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{revision.body}}</pre>`
    setup() {
        super.setup();
        this.buttonList = [{name: 'cancel', label: 'Close'}];
        this.wait(Espo.Ajax.getRequest('RecordKnowledge/revision', {id: this.options.revisionId}).then(data => {
            this.revision = data;
            this.headerText = `Document ${data.documentId} — Revision ${data.revisionNumber}`;
        }));
    }
    data() { return {...super.data(), revision: this.revision}; }
}
