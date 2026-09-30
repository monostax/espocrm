<form>
    <div class="form-group"><label>{{translate "name" scope="Playbook"}}<input class="form-control" name="name" value="{{name}}" required maxlength="200"></label></div>
    <div class="form-group">
        <label>{{translate "status" scope="Playbook"}}
            <select class="form-control" name="status">{{#each statuses}}<option value="{{value}}" {{#if selected}}selected{{/if}}>{{label}}</option>{{/each}}</select>
        </label>
    </div>
    <p class="text-muted">{{translate "snapshotHint" scope="Playbook"}}</p>
    {{#each steps}}
    <fieldset class="panel panel-default" data-editor-step>
        <div class="panel-body">
            <label>{{translate "stepTitle" scope="Playbook"}}<input class="form-control" name="stepName" value="{{name}}" required maxlength="200"></label>
            <label>{{translate "stepType" scope="Playbook"}}<select class="form-control" name="kind"><option value="Check" {{#unless task}}selected{{/unless}}>{{translate "Check" scope="Playbook"}}</option><option value="Task" {{#if task}}selected{{/if}}>{{translate "Task" scope="Playbook"}}</option></select></label>
            <div class="form-group"><label>{{translate "instructions" scope="Playbook"}}<textarea class="form-control" name="instructions" maxlength="10000">{{instructions}}</textarea></label></div>
            <div class="form-group"><label>{{translate "references" scope="Playbook"}}<textarea class="form-control" name="references">{{referenceText}}</textarea></label></div>
            <div class="btn-group">
                <button type="button" class="btn btn-default btn-sm" data-editor-move="-1" data-index="{{index}}" {{#if first}}disabled{{/if}}>{{translate "moveUp" scope="Playbook"}}</button>
                <button type="button" class="btn btn-default btn-sm" data-editor-move="1" data-index="{{index}}" {{#if last}}disabled{{/if}}>{{translate "moveDown" scope="Playbook"}}</button>
                <button type="button" class="btn btn-default btn-sm" data-editor-remove="{{index}}">{{translate "Remove"}}</button>
            </div>
        </div>
    </fieldset>
    {{/each}}
    <button type="button" class="btn btn-default" data-editor-add {{#if full}}disabled{{/if}}>{{translate "addStep" scope="Playbook"}}</button>
</form>
