import View from "view";
import OpportunityGroupIcon from "chatwoot:helpers/opportunity-group-icon";

/** Each section is a native record list with its own server-side pagination. */
export default class OpportunityTableGroupsView extends View {
    templateContent = `
        {{#each groups}}
            <div class="table-group-list" data-group-index="{{@index}}">
                <details class="panel panel-default" data-group-index="{{@index}}" {{#if expanded}}open{{/if}}>
                    <summary class="panel-heading">
                        <h4 class="panel-title">
                            <span class="panel-collapse-chevron fas {{#if expanded}}fa-chevron-down{{else}}fa-chevron-right{{/if}}" aria-hidden="true"></span>
                            <span class="table-group-icon" aria-hidden="true">{{{iconHtml}}}</span>
                            <span title="{{title}}">{{title}}</span>
                        </h4>
                        <span class="text-muted">{{totalLabel}}</span>
                    </summary>
                    <div class="panel-body">
                        <div class="text-muted">{{translate 'Loading...'}}</div>
                    </div>
                </details>
            </div>
        {{else}}
            <div class="no-data">{{translate 'No Data'}}</div>
        {{/each}}
    `;

    data() {
        return {
            groups: this.options.groups.map((group, index) => ({
                ...group,
                iconHtml: this.groupIcon.html(group),
                expanded: this.options.expandedGroupKeys
                    ? this.options.expandedGroupKeys.includes(group.key)
                    : index === 0,
            })),
        };
    }

    setup() {
        this.sections = new Map();
        this.groupIcon = new OpportunityGroupIcon(this);
    }

    afterRender() {
        this.element.querySelectorAll("details").forEach(element => {
            element.addEventListener("toggle", () => {
                const chevron = element.querySelector(".panel-collapse-chevron");
                chevron.classList.toggle("fa-chevron-down", element.open);
                chevron.classList.toggle("fa-chevron-right", !element.open);
                if (!element.open) return;
                this.loadGroup(Number(element.dataset.groupIndex)).catch(() => {
                    if (!this.disposed) this.trigger("load-error");
                });
            });
        });
    }

    async loadInitial() {
        await Promise.all(this.data().groups.map((group, index) => {
            return group.expanded ? this.loadGroup(index) : null;
        }));
    }

    getExpandedKeys() {
        return [...this.element.querySelectorAll("details[open]")].map(element => {
            return this.options.groups[Number(element.dataset.groupIndex)].key;
        });
    }

    loadGroup(index) {
        if (this.sections.has(index)) return this.sections.get(index).promise;
        const section = {
            panel: this.element.querySelector(`details[data-group-index="${index}"]`),
        };
        this.sections.set(index, section);
        section.promise = this.createGroup(index, section);
        return section.promise;
    }

    async createGroup(index, section) {
        const collection = await this.getCollectionFactory().create("Opportunity");
        if (this.disposed) return;
        section.collection = collection;
        collection.where = [...this.collection.where, ...this.options.groups[index].where];
        collection.data.primaryFilter = this.collection.data.primaryFilter;
        collection.maxSize = this.collection.maxSize;
        collection.setOrder(this.collection.orderBy, this.collection.order, true);

        const view = await this.createView(`group-${index}`, this.options.listViewName, {
            collection,
            selector: `.table-group-list[data-group-index="${index}"]`,
            scope: "Opportunity",
            skipBuildRows: true,
            forceDisplayTopBar: true,
            keepCurrentRootUrl: true,
            settingsEnabled: true,
            forceSettings: this.getMetadata().get("clientDefs.Opportunity.forceListViewSettings"),
            additionalRowActionList: this.getMetadata().get("clientDefs.Opportunity.rowActionList"),
            pagination: true,
        });
        if (this.disposed) return;
        this.listenTo(view, "after:render", () => this.renderGroupPanel(view, section.panel));
        this.listenTo(view, "after:save after:mass-update after:mass-remove after:remove", () => {
            this.trigger("changed");
        });
        const attributes = await view.getSelectAttributeList();
        if (this.disposed) return;
        if (attributes) collection.data.select = attributes.join(",");
        await collection.fetch({ main: true });
    }

    renderGroupPanel(view, panel) {
        if (this.disposed || view.element.contains(panel)) return;
        const heading = panel.querySelector("summary");
        const toolbar = view.element.querySelector(":scope > .list-buttons-container");
        heading.querySelector(".list-buttons-container")?.remove();
        if (toolbar) {
            toolbar.classList.remove("clearfix");
            // Cancel the summary's default toggle without stopping native toolbar handlers.
            toolbar.addEventListener("click", event => event.preventDefault(), true);
            heading.append(toolbar);
        }
        // Keep the heading and toolbar inside the record view's event/selector root.
        // Reuse the panel so pagination, sorting and column changes preserve its open state.
        panel.querySelector(":scope > .panel-body").replaceChildren(...view.element.childNodes);
        view.element.append(panel);
    }

    abortLoading() {
        this.disposed = true;
        this.sections.forEach(section => {
            section.collection?.abortLastFetch();
        });
    }

    onRemove() {
        this.abortLoading();
    }
}
