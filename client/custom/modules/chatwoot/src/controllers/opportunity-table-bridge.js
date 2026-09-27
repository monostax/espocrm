import RecordController from "controllers/record";

export default class OpportunityTableBridgeController extends RecordController {
    actionIndex() {
        this.name = "Opportunity";
        this.beforeList();
        this.actionList({});
    }

    getViewName() {
        return "chatwoot:views/opportunity/table-bridge";
    }
}
