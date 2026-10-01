<div class="page-header">{{{header}}}</div>
{{#if error}}
<div class="alert alert-danger" role="alert">{{translate 'listError' scope='Playbook'}}</div>
{{else}}
{{#if snapshot}}
<div class="record detail">
    <div class="panel panel-default">
        <div class="panel-heading"><h4 class="panel-title">{{translate 'templateDetails' scope='Playbook'}}</h4></div>
        <div class="panel-body">
            <div class="row">
                <div class="cell col-sm-6 form-group"><label class="control-label">{{translate 'name' scope='Playbook'}}</label><div class="field">{{snapshot.name}}</div></div>
                <div class="cell col-sm-3 form-group"><label class="control-label">{{translate 'status' scope='Playbook'}}</label><div class="field">{{statusLabel}}</div></div>
                <div class="cell col-sm-3 form-group"><label class="control-label">{{translate 'revision' scope='Playbook'}}</label><div class="field">{{snapshot.revision}}</div></div>
            </div>
            <p class="text-muted">{{translate 'snapshotHint' scope='Playbook'}}</p>
        </div>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading"><h4 class="panel-title">{{translate 'stepCount' scope='Playbook'}}</h4></div>
        <div class="panel-body">
            {{#each steps}}
            <section class="well well-sm">
                <h4>{{number}}. {{name}} <small>{{kindLabel}}</small></h4>
                {{#if instructions}}<div style="white-space: pre-wrap; overflow-wrap: anywhere;">{{instructions}}</div>{{/if}}
                {{#if references}}<ul>{{#each references}}<li><a href="{{this}}" target="_blank" rel="noopener noreferrer" style="overflow-wrap: anywhere;">{{this}}</a></li>{{/each}}</ul>{{/if}}
            </section>
            {{else}}<p class="text-muted">{{translate 'noSteps' scope='Playbook'}}</p>{{/each}}
        </div>
    </div>
    <div class="panel panel-default">
        <div class="panel-heading"><h4 class="panel-title">{{translate 'relatedRuns' scope='Playbook'}}</h4></div>
        <div class="panel-body">
            <p class="text-muted">{{translate 'runsDescription' scope='Playbook'}}</p>
            {{#if runsError}}<div class="alert alert-danger" role="alert">{{translate 'listError' scope='Playbook'}}</div>{{else}}<div data-template-runs>{{{runs}}}</div>{{/if}}
        </div>
    </div>
</div>
{{/if}}
{{/if}}
