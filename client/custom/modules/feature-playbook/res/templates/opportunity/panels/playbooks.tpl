{{#if error}}<p class="text-danger" role="alert">{{translate "error" scope="Playbook"}}</p>{{/if}}
<button type="button" class="btn btn-link btn-sm" data-playbook-action="refresh">{{translate "refresh" scope="Playbook"}}</button>
<a href="{{manageUrl}}">{{translate "manageTemplates" scope="Playbook"}}</a>
{{#if canEdit}}
<details>
    <summary>{{translate "apply" scope="Playbook"}}</summary>
    <form data-playbook-form="apply">
        <select name="templateId" class="form-control" aria-label="{{translate 'apply' scope='Playbook'}}">
            <option value="">{{translate "blank" scope="Playbook"}}</option>
            {{#each templates}}<option value="{{id}}">{{name}}</option>{{/each}}
        </select>
        <label>{{translate "name" scope="Playbook"}}<input name="name" maxlength="200" class="form-control"></label>
        <p class="text-muted small">{{translate "reapply" scope="Playbook"}}</p>
        <button type="submit" class="btn btn-primary btn-sm">{{translate "apply" scope="Playbook"}}</button>
    </form>
</details>
{{/if}}
{{#each runs}}
<details data-run-id="{{id}}" open>
    <summary>{{name}} · {{completed}}/{{total}} · {{statusLabel}}</summary>
    <p class="text-muted small">{{assignedUserName}} · {{createdAt}}</p>
    {{#if skipped}}<p class="text-muted small">{{translate "skipped" scope="Playbook"}}: {{skipped}}</p>{{/if}}
    {{#if stopReason}}<p>{{stopReason}}</p>{{/if}}
    {{#each steps}}
    <div class="form-group">
        <label><input type="checkbox" data-step-id="{{id}}" {{#if checked}}checked{{/if}} {{#if disabled}}disabled{{/if}}> {{name}}</label>
        <span class="text-muted small">{{statusLabel}}</span>
        {{#if completedAt}}<p class="text-muted small">{{completedByName}} · {{completedAt}}</p>{{/if}}
        <details>
            <summary>{{translate "guidance" scope="Playbook"}}</summary>
            <p class="text-muted">{{instructions}}</p>
            {{#each references}}<p><a href="{{this}}" target="_blank" rel="noopener noreferrer">{{this}}</a></p>{{/each}}
        </details>
        {{#if taskId}}<a href="#Task/view/{{taskId}}" target="_blank" rel="noopener noreferrer">{{translate "task" scope="Playbook"}}</a>{{/if}}
        {{#if canActivate}}<button type="button" class="btn btn-link btn-sm" data-playbook-action="activate" data-step-id="{{id}}">{{translate "activate" scope="Playbook"}}</button>{{/if}}
        {{#if canSkip}}<button type="button" class="btn btn-link btn-sm" data-playbook-action="step" data-status="Skipped" data-step-id="{{id}}">{{translate "skip" scope="Playbook"}}</button>{{/if}}
        {{#if canReopen}}<button type="button" class="btn btn-link btn-sm" data-playbook-action="step" data-status="Pending" data-step-id="{{id}}">{{translate "reopen" scope="Playbook"}}</button>{{/if}}
    </div>
    {{/each}}
    {{#if canAdd}}
    <details>
        <summary>{{translate "addStep" scope="Playbook"}}</summary>
        <form data-playbook-form="addStep">
            <label>{{translate "name" scope="Playbook"}}<input class="form-control" name="name" required maxlength="200"></label>
            <select name="kind" class="form-control" aria-label="{{translate 'addStep' scope='Playbook'}}"><option value="Check">{{translate "Check" scope="Playbook"}}</option><option value="Task">{{translate "Task" scope="Playbook"}}</option></select>
            <label>{{translate "instructions" scope="Playbook"}}<textarea class="form-control" name="instructions" maxlength="10000"></textarea></label>
            <label>{{translate "references" scope="Playbook"}}<textarea class="form-control" name="references"></textarea></label>
            <button type="submit" class="btn btn-default btn-sm">{{translate "addStep" scope="Playbook"}}</button>
        </form>
    </details>
    {{/if}}
    {{#if editable}}
    <details>
        <summary>{{translate "stop" scope="Playbook"}}</summary>
        <form data-playbook-form="close">
            <label>{{translate "reason" scope="Playbook"}}<textarea name="reason" class="form-control" required maxlength="2000"></textarea></label>
            <select name="action" class="form-control" aria-label="{{translate 'stop' scope='Playbook'}}"><option value="stop">{{translate "stop" scope="Playbook"}}</option><option value="cancel">{{translate "cancel" scope="Playbook"}}</option></select>
            <button type="submit" class="btn btn-default btn-sm">{{translate "stop" scope="Playbook"}}</button>
        </form>
    </details>
    {{/if}}
    {{#if resumable}}<button type="button" class="btn btn-link btn-sm" data-playbook-action="resume">{{translate "resume" scope="Playbook"}}</button>{{/if}}
</details>
{{/each}}
