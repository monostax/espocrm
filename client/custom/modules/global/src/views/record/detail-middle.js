import DetailMiddleRecordView from "views/record/detail-middle";

class GlobalDetailMiddleRecordView extends DetailMiddleRecordView {
    getCollapsiblePanelNameList() {
        const layoutDefs = this.options.layoutDefs;

        const panelList = Array.isArray(layoutDefs)
            ? layoutDefs
            : Array.isArray(layoutDefs?.layout)
              ? layoutDefs.layout
              : [];

        return panelList
            .filter((panel) => panel && panel.name && panel.label)
            .map((panel) => panel.name);
    }
}

export default GlobalDetailMiddleRecordView;
