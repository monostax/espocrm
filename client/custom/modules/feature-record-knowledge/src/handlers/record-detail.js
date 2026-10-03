export default class {
    constructor(view) { this.view = view; }
    process() {
        const view = this.view;
        const scopes = view.getMetadata().get('app.recordKnowledge.supportedScopes') || [];
        if (!scopes.includes(view.model.entityType) || !view.model.id) return;
        // Quick-edit normally has no bottom container. Its panels use their own APIs
        // and never contribute attributes to the parent record's fetch/PATCH.
        if (!view.bottomView) view.bottomView = 'views/record/edit-bottom';
        if (view.convertDetailLayout) this.setupDocumentTab();
        const hideTerminalOverview = () => {
            if (view.model.get('knowledgeRecordType')) view.hidePanel('overview');
        };
        hideTerminalOverview();
        view.listenTo(view.model, 'sync change:knowledgeRecordType', hideTerminalOverview);
    }

    setupDocumentTab() {
        const view = this.view;
        const convertDetailLayout = view.convertDetailLayout;
        const panels = view.getMetadata().get('app.recordKnowledge.panels') || [];
        view.convertDetailLayout = layout => {
            const original = layout.filter(panel => !panel.recordKnowledge);
            const documentPanels = panels.map((panel, index) => ({
                name: panel.name,
                label: panel.label,
                recordKnowledge: true,
                tabBreak: index === 0,
                tabLabel: index === 0 ? view.translate('Record Knowledge Document', 'labels', 'Global') : undefined,
                rows: [[{
                    name: `${panel.name}KnowledgePanel`,
                    view: 'feature-record-knowledge:views/fields/knowledge-panel',
                    noLabel: true,
                    span: 4,
                    inlineEditDisabled: true,
                    options: {panel, recordViewObject: view},
                }]],
            }));
            // Keep every panel of the first tab together before inserting the document tab.
            const nextTab = original.findIndex((panel, index) => index > 0 && panel.tabBreak);
            const extended = [...original];
            extended.splice(nextTab === -1 ? extended.length : nextTab, 0, ...documentPanels);
            view.detailLayout = extended;
            delete view._hasMiddleTabs;
            return convertDetailLayout.call(view, extended);
        };

        const selectTab = view.selectTab;
        view.selectTab = (...args) => {
            const result = selectTab.apply(view, args);
            view.whenRendered().then(() => this.syncMentionedIn());
            return result;
        };
        view.on('after:render', () => {
            const bottom = view.getBottomView();
            if (bottom && bottom !== this.mentionedInBottom) {
                this.mentionedInBottom = bottom;
                view.listenTo(bottom, 'after:render', () => this.syncMentionedIn());
            }
            this.syncMentionedIn();
        });
    }

    syncMentionedIn() {
        const view = this.view;
        const panel = view.getBottomView()?.el?.querySelector('.panel[data-name="mentionedIn"]');
        panel?.classList.toggle('tab-hidden', (view.currentTab || 0) !== 0);
    }
}
