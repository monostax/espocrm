import DetailBottomRecordView from "views/record/detail-bottom";

class GlobalDetailBottomRecordView extends DetailBottomRecordView {
    setupStreamPanel() {
        let streamAllowed = this.getAcl().checkModel(this.model, "stream", true);

        if (streamAllowed === null) {
            this.listenToOnce(this.model, "sync", () => {
                streamAllowed = this.getAcl().checkModel(this.model, "stream", true);

                if (streamAllowed) {
                    this.onPanelsReady(() => {
                        this.showPanel("stream", "acl");
                    });
                }
            });
        }

        if (streamAllowed !== false) {
            this.panelList.push({
                name: "stream",
                label: "Stream",
                view:
                    this.getMetadata().get(["clientDefs", this.scope, "streamPanelView"]) ||
                    "global:views/stream/panel",
                notRefreshable: true,
                sticked: false,
                hidden: !streamAllowed,
                index: 2,
            });

            if (!streamAllowed) {
                this.recordHelper.setPanelStateParam("stream", "hiddenAclLocked", true);
            }
        }
    }
}

export default GlobalDetailBottomRecordView;
