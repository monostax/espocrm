import KnowledgeBaseHelper from 'feature-knowledge-base-editor:helpers/knowledge-base-helper';
import KnowledgeBaseRecordDetailView from 'crm:views/knowledge-base-article/record/detail';

class FeatureKnowledgeBaseRecordDetailView extends KnowledgeBaseRecordDetailView {
    // noinspection JSUnusedGlobalSymbols
    actionSendInEmail() {
        Espo.Ui.notifyWait();

        const open = () => {
            const helper = new KnowledgeBaseHelper(this.getLanguage());

            helper.getAttributesForEmail(this.model, {}, attributes => {
                const viewName = this.getMetadata().get('clientDefs.Email.modalViews.compose') ||
                    'views/modals/compose-email';

                this.createView('composeEmail', viewName, {
                    attributes: attributes,
                    selectTemplateDisabled: true,
                    signatureDisabled: true,
                }, view => {
                    Espo.Ui.notify(false);

                    view.render();
                });
            });
        };

        // Ensure Lexical helper is available for Markdown → HTML.
        if (window.EspoLexical) {
            open();
            return;
        }

        Espo.loader.requirePromise('lib!lexical-kb')
            .then(() => open())
            .catch(() => open());
    }
}

export default FeatureKnowledgeBaseRecordDetailView;
