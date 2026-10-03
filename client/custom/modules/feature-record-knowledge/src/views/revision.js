import View from 'view';

export default class extends View {
    templateContent = `<h3>Document {{revision.documentId}} — Revision {{revision.revisionNumber}}</h3><p>{{revision.createdAt}} · {{revision.contentHash}}</p><pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{revision.body}}</pre>`
    setup() {
        super.setup();
        this.wait(Espo.Ajax.getRequest('RecordKnowledge/revision', {id: this.options.revisionId}).then(data => { this.revision = data; }));
    }
    data() { return {revision: this.revision}; }
}
