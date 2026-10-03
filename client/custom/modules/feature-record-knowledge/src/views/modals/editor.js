import ModalView from 'views/modal';
import {endpoint} from 'feature-record-knowledge:content';

/** An independent document editor: the parent model is never mutated or saved. */
export default class extends ModalView {
    templateContent = `
        <div class="field" data-name="body">{{{body}}}</div>
        <div class="text-danger" role="alert" data-name="error"></div>
    `
    setup() {
        super.setup();
        this.parentModel = this.options.parentModel;
        this.document = this.options.document;
        this.headerText = this.document.name;
        this.buttonList = [{name: 'save', label: 'Save', style: 'primary'}, {name: 'cancel', label: 'Cancel'}];
        this.wait(this.loadEditor());
    }

    async loadEditor() {
        const model = await this.getModelFactory().create('Document');
        model.set({
            id: this.document.documentId,
            contentType: 'Page',
            body: this.document.body || '',
            bodyEditorState: this.document.bodyEditorState,
            bodyFormat: 'Markdown',
            bodyAuthoringMode: this.document.bodyAuthoringMode || 'Markdown',
            knowledgeRecordType: this.parentModel.entityType,
            knowledgeRecordId: this.parentModel.id,
        });
        await this.createView('body', 'feature-document-pages:views/document/fields/body', {
            model,
            mode: 'edit',
            selector: '.field[data-name="body"]',
            defs: {name: 'body', params: {minHeight: 360}},
        });
    }

    async actionSave() {
        if (this.saving) return;
        this.saving = true;
        this.disableButton('save');
        try {
            const {body, bodyEditorState} = this.getView('body').fetch();
            await Espo.Ajax.putRequest(endpoint('overview', this.parentModel), {body, bodyEditorState},
                {headers: {'X-Version-Number': this.document.versionNumber}});
            this.trigger('saved');
            this.close();
        } catch (error) {
            this.el.querySelector('[data-name="error"]').textContent = this.translate(error.status === 409 ? 'knowledgeConflict' : 'knowledgeUnavailable', 'messages');
            this.enableButton('save');
        } finally { this.saving = false; }
    }
}
