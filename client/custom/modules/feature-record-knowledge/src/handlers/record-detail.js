export default class {
    constructor(view) { this.view = view; }
    process() {
        const view = this.view;
        const scopes = view.getMetadata().get('app.recordKnowledge.supportedScopes') || [];
        if (!scopes.includes(view.model.entityType) || !view.model.id) return;
        // Quick-edit normally has no bottom container. Its panels use their own APIs
        // and never contribute attributes to the parent record's fetch/PATCH.
        if (!view.bottomView) view.bottomView = 'views/record/edit-bottom';
        if (view.model.get('knowledgeRecordType')) view.hidePanel('overview');
    }
}
