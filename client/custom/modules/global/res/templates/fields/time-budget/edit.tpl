<div style="display: flex; gap: 8px;">
    <label style="flex: 1; min-width: 0;">
        <span class="small text-muted">{{translate 'Days' scope='OpportunityStage'}}</span>
        <input type="number" class="form-control" data-name="{{name}}" data-unit="days" min="0" step="1" value="{{days}}">
    </label>
    <label style="flex: 1; min-width: 0;">
        <span class="small text-muted">{{translate 'Hours' scope='OpportunityStage'}}</span>
        <input type="number" class="form-control" data-unit="hours" min="0" step="1" value="{{hours}}">
    </label>
    <label style="flex: 1; min-width: 0;">
        <span class="small text-muted">{{translate 'Minutes' scope='OpportunityStage'}}</span>
        <input type="number" class="form-control" data-unit="minutes" min="0" step="1" value="{{minutes}}">
    </label>
</div>
<span class="small text-muted">{{translate 'Empty means no target' scope='OpportunityStage'}}</span>
