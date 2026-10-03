import ModalView from 'views/modal';
import {endpoint, preview, sourceValue} from 'feature-record-knowledge:content';

/** An independent document editor: the parent model is never mutated or saved. */
export default class extends ModalView {
    templateContent = `
        <label for="knowledge-source-{{cid}}">Markdown</label>
        <textarea id="knowledge-source-{{cid}}" class="form-control" data-name="source" rows="18" style="resize:vertical;font-family:monospace"></textarea>
        <button type="button" class="btn btn-default btn-sm margin-top" data-action="preview">{{translate 'Preview'}}</button>
        <div class="html-container margin-top" data-name="preview"></div>
        <div class="text-danger" role="alert" data-name="error"></div>
    `
    setup() {
        super.setup();
        this.parentModel = this.options.parentModel;
        this.document = this.options.document;
        this.source = this.document.body || '';
        this.headerText = this.document.name;
        this.buttonList = [{name: 'save', label: 'Save', style: 'primary'}, {name: 'cancel', label: 'Cancel'}];
        this.addHandler('click', '[data-action="preview"]', () => this.showPreview());
    }
    data() { return {...super.data(), cid: this.cid, source: this.source}; }
    afterRender() {
        super.afterRender();
        // Assign the value, rather than textarea HTML (HTML parsing strips an initial LF).
        this.el.querySelector('[data-name="source"]').value = this.source;
    }
    async showPreview() {
        const data = await Espo.Ajax.postRequest(endpoint('preview', this.parentModel), {body: sourceValue(this.el.querySelector('[data-name="source"]'), this.source)});
        if (!this.isRemoved()) preview(this, this.el.querySelector('[data-name="preview"]'), data.html);
    }
    async actionSave() {
        if (this.saving) return;
        this.saving = true;
        this.disableButton('save');
        try {
            await Espo.Ajax.putRequest(endpoint('overview', this.parentModel), {body: sourceValue(this.el.querySelector('[data-name="source"]'), this.source)},
                {headers: {'X-Version-Number': this.document.versionNumber}});
            this.trigger('saved');
            this.close();
        } catch (error) {
            this.el.querySelector('[data-name="error"]').textContent = this.translate(error.status === 409 ? 'knowledgeConflict' : 'knowledgeUnavailable', 'messages');
            this.enableButton('save');
        } finally { this.saving = false; }
    }
}
