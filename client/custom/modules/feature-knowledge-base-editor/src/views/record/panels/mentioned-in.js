import BottomPanelView from 'views/record/panels/bottom';

export default class extends BottomPanelView {
    templateContent = `
        {{#if loading}}<span role="status">Loading…</span>{{/if}}
        {{#if error}}<span role="status">References unavailable.</span>{{/if}}
        <ul class="list-unstyled">
            {{#each items}}<li><a href="#{{entityType}}/view/{{recordId}}">{{label}}</a> <small class="text-muted">{{entityType}}</small></li>{{/each}}
        </ul>
        {{#if empty}}<span class="text-muted">No accessible references.</span>{{/if}}
        {{#if cursor}}<button type="button" class="btn btn-default btn-sm" data-action="more">More</button>{{/if}}
    `

    setup() {
        super.setup();
        this.items = [];
        this.listenTo(this.model, 'sync', () => this.load(false));
        this.addHandler('click', '[data-action="more"]', () => this.load(true));
        this.wait(this.load(false));
    }

    async load(more) {
        if (!this.model.id) return;
        const generation = (this.generation || 0) + 1;
        this.generation = generation;
        this.loading = true;
        this.error = false;
        try {
            const data = await Espo.Ajax.getRequest('EditorReference/backlinks', {
                entityType: this.model.entityType, recordId: this.model.id, cursor: more ? this.cursor : '',
            });
            if (generation !== this.generation) return;
            this.items = more ? [...this.items, ...data.list] : data.list;
            this.cursor = data.cursor;
        } catch (e) {
            if (generation !== this.generation) return;
            this.items = []; this.cursor = null; this.error = true;
        }
        this.loading = false;
        if (this.isRendered()) this.reRender();
    }

    data() {
        return {...super.data(), items: this.items, cursor: this.cursor,
            loading: this.loading, error: this.error, empty: !this.loading && !this.error && !this.items.length};
    }

    onRemove() { this.generation = (this.generation || 0) + 1; super.onRemove(); }
}
