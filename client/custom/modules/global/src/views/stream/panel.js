import StreamPanelView from "views/stream/panel";

class GlobalStreamPanelView extends StreamPanelView {
    setupActions() {
        super.setupActions();

        this.actionList.unshift(false);
        this.actionList.unshift({
            action: "refresh",
            text: this.translate("Refresh"),
            onClick: () => this.actionRefresh(),
        });
    }
}

export default GlobalStreamPanelView;
