<div class="relationship-list-field">
    <div class="btn-group pull-right relationship-buttons">
        {{#if showCreateButton}}
        <button
            class="btn btn-default btn-sm action"
            data-action="createRelated"
            title="{{translate 'Create'}}"
        ><span class="fas fa-plus"></span></button>
        {{/if}}
        {{#if showSelectButton}}
        <button
            class="btn btn-default btn-sm action"
            data-action="selectRelated"
            title="{{translate 'Select'}}"
        ><span class="fas fa-link"></span></button>
        {{/if}}
        {{#if showViewListButton}}
        <button
            class="btn btn-default btn-sm action"
            data-action="viewRelatedList"
            title="{{translate 'View List'}}"
        ><span class="fas fa-list"></span></button>
        {{/if}}
    </div>
    <div class="clearfix"></div>
    <div class="relationship-list-container" style="margin-top: 10px;"></div>
</div>


