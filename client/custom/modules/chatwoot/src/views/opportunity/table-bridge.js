import ListView from "views/list";
import {invalidateTags} from "global:crm-tags";

/** Embed the same record list used by #Opportunity, with Chatwoot's scope. */
export default class OpportunityTableBridgeView extends ListView {
    templateContent = '<div class="list-container"></div>';
    searchPanel = false;
    createButton = false;
    viewModeList = ["list"];
    keepCurrentRootUrl = true;

    setupHeader() {
        // The workspace supplies the header and search controls.
    }

    setup() {
        super.setup();
        this.parentOrigin = new URL(document.referrer).origin;
        this.requestId = null;
        this.wait(new Promise((resolve) => {
            this.resolveInitialContext = resolve;
        }));

        this.onParentMessage = (event) => {
            if (event.source !== window.parent || event.origin !== this.parentOrigin ||
                event.data?.type !== "OPPORTUNITY_TABLE_CONTEXT") {
                return;
            }

            if (event.data.requestId === this.requestId) {
                return;
            }

            if (this.loading) {
                this.pendingContext = event.data;
                this.collection.abortLastFetch();
                this.getView("list")?.abortLoading?.();
                return;
            }

            this.applyContext(event.data);
        };
        window.addEventListener("message", this.onParentMessage);
        this.listenTo(this.collection, "sync", () => {
            if (this.groups !== null) return;
            this.post("OPPORTUNITY_TABLE_LOADED", { total: this.collection.total });
        });
        this.onRowClick = (event) => {
            const link = event.target.closest('td[data-name="name"] a.link[data-id]');

            if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            this.post("OPPORTUNITY_TABLE_SELECT", { id: link.dataset.id });
        };
        this.post("OPPORTUNITY_TABLE_READY");
    }

    afterRender() {
        super.afterRender();
        this.element.addEventListener("click", this.onRowClick, true);
    }

    post(type, data = {}) {
        window.parent.postMessage({ type, requestId: this.requestId, ...data }, this.parentOrigin);
    }

    applyContext({ requestId, where, primaryFilter, groups = null, groupBy = "none" }) {
        invalidateTags();
        this.collection.abortLastFetch();
        this.requestId = requestId;
        this.expandedGroupKeys = groupBy === this.groupBy
            ? this.getView("list")?.getExpandedKeys?.() ?? this.expandedGroupKeys
            : null;
        this.groupBy = groupBy;
        this.groups = groups;
        this.collection.where = where;
        this.collection.data.primaryFilter = primaryFilter;
        this.collection.offset = 0;
        this.collection.reset([], { silent: true });
        this.collection.isFetched = false;

        if (this.resolveInitialContext) {
            this.resolveInitialContext();
            this.resolveInitialContext = null;
            return;
        }

        this.clearView("list");
        this.reRender();
    }

    async loadList() {
        const requestId = this.requestId;
        this.loading = true;

        try {
            if (this.groups !== null) {
                const view = await this.createView("list", "chatwoot:views/opportunity/table-groups", {
                    selector: ".list-container",
                    collection: this.collection,
                    groups: this.groups,
                    expandedGroupKeys: this.expandedGroupKeys,
                    listViewName: this.getRecordViewName(),
                });
                if (this.disposed || this.pendingContext) {
                    view.abortLoading();
                    return;
                }
                this.listenTo(view, "changed", () => this.post("OPPORTUNITY_TABLE_CHANGED"));
                this.listenTo(view, "load-error", () => this.post("OPPORTUNITY_TABLE_ERROR"));
                await view.render();
                await view.loadInitial();
                if (!this.disposed && !this.pendingContext) {
                    this.post("OPPORTUNITY_TABLE_LOADED", {
                        total: this.groups.reduce((sum, group) => sum + group.count, 0),
                    });
                }
                return;
            }

            const view = await this.createListRecordView(true);

            if (this.disposed || requestId !== this.requestId) {
                return;
            }

            this.listenTo(view, "after:save after:mass-update after:mass-remove after:remove", () => {
                this.post("OPPORTUNITY_TABLE_CHANGED");
            });
        } catch {
            if (!this.disposed && !this.pendingContext && requestId === this.requestId) {
                this.post("OPPORTUNITY_TABLE_ERROR");
            }
        } finally {
            this.loading = false;
            if (!this.disposed && this.pendingContext) {
                const context = this.pendingContext;
                this.pendingContext = null;
                this.applyContext(context);
            }
        }
    }

    onRemove() {
        this.disposed = true;
        this.options.mediator.abort = true;
        this.collection.abortLastFetch();
        this.resolveInitialContext?.();
        window.removeEventListener("message", this.onParentMessage);
        this.element?.removeEventListener("click", this.onRowClick, true);
    }
}
