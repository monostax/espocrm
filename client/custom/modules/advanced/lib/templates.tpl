_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/record/edit-bottom.tpl
<div class="panel panel-default panel-conditions hidden" data-name="conditions">
    <div class="panel-heading"><h4 class="panel-title">{{translate 'Conditions' scope='Workflow'}}</h4></div>
    <div class="panel-body conditions-container">
        {{{conditions}}}
    </div>
</div>

<div class="panel panel-default panel-actions hidden" data-name="actions">
    <div class="panel-heading"><h4 class="panel-title">{{translate 'Actions' scope='Workflow'}}</h4></div>
    <div class="panel-body actions-container">
        {{{actions}}}
    </div>
</div>

{{#if workflowLogRecords}}
<div class="panel panel-default" data-name="workflowLogRecords">
    <div class="panel-heading">
        <h4 class="panel-title">
            <span style="cursor: pointer;" class="action" data-action="refresh" data-panel="workflowLogRecords" title="{{translate 'clickToRefresh' category='messages'}}">{{translate 'workflowLogRecords' scope='Workflow' category='links'}}</span>
        </h4>
    </div>
    <div class="panel-body">
        {{{workflowLogRecords}}}
    </div>
</div>
{{/if}}
_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/record/conditions.tpl
{{#if showConditionsAll}}
    <div>
        <div class="all-conditions"></div>
    </div>
{{/if}}

{{#if showConditionsAny}}
    <div
        {{#if marginForConditionsAny}}style="margin-top: var(--20px)"{{/if}}
    >
        <div class="any-conditions"></div>
    </div>
{{/if}}

{{#if showFormula}}
    <div
        {{#if marginForFormula}}style="margin-top: var(--20px)"{{/if}}
    >
        <label class="control-label"
        >{{translate 'Formula' scope='Workflow'}}
            <small class="text-muted"> · {{translate 'formulaInfo' category='texts' scope='Workflow'}}</small></label>
        <div
            class="formula-conditions clearfix"
            {{#if readOnly}}style="margin-left: 10px;"{{/if}}
        ></div>
    </div>
{{/if}}

{{#if showNoData}}
    <div class="list-container margin-top">
        <div class="no-data">
            {{translate 'No Data'}}
        </div>
    </div>
{{/if}}

<!--suppress CssUnusedSymbol -->
<style>
    .all-conditions,
    .any-conditions {
        > div {
            .cell {
                > label {
                    color: var(--gray-soft);
                }

                &,
                > .clearfix {
                    > a[data-action="removeCondition"] {
                        visibility: hidden;
                    }
                }

                &:hover {
                    &,
                    > .clearfix {
                        > a[data-action="removeCondition"] {
                            visibility: visible;
                        }
                    }
                }

                padding: var(--2px) var(--10px) var(--10px) var(--10px);
                border: var(--1px) solid var(--default-border-color);
                border-radius: var(--border-radius-small);

                a[data-action="removeCondition"] {
                    position: relative;
                }

                &:last-child {
                    margin-bottom: var(--4px);
                }

                &[data-role="field-cell"] {
                    max-width: calc(var(--340px) + var(--10px));
                }

                &[data-role="group-cell"] {
                    > .condition {
                        margin-top: var(--6px);
                    }

                    &:has(> [data-action="removeCondition"]) {
                        > .condition {
                            margin-top: var(--20px);
                        }
                    }
                }

                .condition {
                    .row + .row {
                        > div:not(:empty) {
                            margin-top: var(--8px);
                        }
                    }

                    padding-top: var(--2px);
                }

                padding-top: var(--4px);
            }

            [data-role="operator"] {
                font-size: 0.85em;
                margin: var(--5px) var(--4px) var(--4px);
                user-select: none;

                &:last-child {
                    display: none;
                }
            }
        }

        .items {
            + .btn-group {
                margin-top: var(--2px);
            }
        }

        > .no-data {
            margin-right: var(--10px);
        }

        > .items {
            > .cell[data-role="group-cell"] {
                max-width: calc(var(--340px) + var(--10px) + var(--22px));

                &:has(.cell[data-role="group-cell"]) {
                    max-width: calc(var(--340px) + var(--10px) + var(--22px) + var(--22px));
                }
            }
        }
    }
</style>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/record/actions.tpl
<div>
    <div class="actions{{#unless readOnly}} margin margin-bottom{{/unless}} no-side-margin"></div>
    {{#unless readOnly}}
    <div class="btn-group">
        <button
            class="btn btn-default btn-sm btn-icon radius-right"
            type="button"
            data-action="showAddAction"
            title="{{translate 'Add Action' scope='Workflow'}}"
        ><span class="fas fa-plus"></span></button>
    </div>
    {{/unless}}
</div>

{{#if showNoData}}
<div class="list-container margin-top">
    <div class="no-data">
        {{translate 'No Data'}}
    </div>
</div>
{{/if}}

<!--suppress CssUnusedSymbol -->
<style>
    .actions-container {
        .actions {
            .drag-handle {
                cursor: grab;
            }
        }
    }
</style>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/fields/help-text/detail.tpl
{{#if isNotEmpty}}<div class="well"><span class="complex-text">{{{value}}}</span></div>{{/if}}
_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/field-definitions/date.tpl
{{#if readOnly}}
    <span class="subject">
        {{#if subject}} {{{subject}}} {{else}} {{translate 'today' scope='Workflow' category='labels'}} {{/if}}
    </span>
    <span class="shift-days">
        {{{shiftDays}}}
    </span>
{{else}}
    <div class="row">
        <div class="col-sm-2 subject-type">
            <span data-field="subjectType">{{{subjectTypeField}}}</span>
        </div>

        <div class="col-sm-4 subject">
            {{{subject}}}
        </div>

        <div class="col-sm-5 shift-days">
            {{{shiftDays}}}
        </div>
    </div>
{{/if}}

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/field-definitions/base.tpl
{{#if readOnly}}
    <div class="subject">
        {{{subject}}}
    </div>
{{else}}
<div class="row">
    <div class="col-sm-2 subject-type">
        <span data-field="subjectType">{{{subjectTypeField}}}</span>
    </div>

    <div class="col-sm-6 subject">
        {{{subject}}}
    </div>

    {{#if hasActionType}}
        <div class="col-sm-3" data-field="actionType">{{{actionTypeField}}}</div>
    {{/if}}
</div>
{{/if}}

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/conditions/enum.tpl

{{#if readOnly}}
    <span class="comparison">
        {{translate comparisonValue category='labels' scope='Workflow'}}
    </span>

    <span class="subject-type">
        {{{subjectType}}}
    </span>

    <span class="subject">
        {{{subject}}}
    </span>
{{else}}
    <div class="row">
        <div class="col-sm-6 comparison">
            <span data-field="comparison">{{{comparisonField}}}</span>
        </div>
    </div>
    <div class="row">
        <div class="col-sm-12 subject">
            {{{subject}}}
        </div>
    </div>
{{/if}}

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/conditions/date.tpl
{{#if readOnly}}
    <span class="comparison">
        {{translate comparisonValue category='labels' scope='Workflow'}}
    </span>
    <span class="subject-type">
        {{{subjectType}}}
    </span>
    <span class="subject">
        {{{subject}}}
    </span>
    <span class="shift-days">
        {{{shiftDays}}}
    </span>
{{else}}
    <div class="row">
        <div class="col-sm-6 comparison">
            <span data-field="comparison">{{{comparisonField}}}</span>
        </div>
        <div class="col-sm-6 subject-type">
            {{{subjectType}}}
        </div>
    </div>
    <div class="row">
        <div class="col-sm-12 subject">
            {{{subject}}}
        </div>
    </div>
    <div class="row">
        <div class="col-sm-12 shift-days">
            {{{shiftDays}}}
        </div>
    </div>
{{/if}}


_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/conditions/base.tpl
{{#if readOnly}}
    <span class="comparison">
        {{translate comparisonValue category='labels' scope='Workflow'}}
    </span>
    <span class="subject-type">
        {{{subjectType}}}
    </span>
    <span class="subject">
        {{{subject}}}
    </span>
{{else}}
    <div class="row">
        <div class="col-sm-6 comparison">
            <span data-field="comparison">{{{comparisonField}}}</span>
        </div>
        <div class="col-sm-6 subject-type">
            {{{subjectType}}}
        </div>
    </div>
    <div class="row">
        <div class="col-sm-12 subject">
            {{{subject}}}
        </div>
    </div>
{{/if}}


_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/condition-fields/subject-type.tpl
{{#if readOnly}}
    {{translateOption value scope='Workflow' field='subjectType'}}
{{else}}
    <span data-field="value">{{{valueField}}}</span>
{{/if}}

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/condition-fields/shift-days.tpl
{{#if readOnly}}
    {{translate shiftDaysOperator scope='Workflow'}} {{value}} {{translate 'days' scope='Workflow'}}
{{else}}
    <div class="row">
        <div class="col-sm-12">
            <div class="input-group input-group-sm">
                <span data-field="operator" class="input-group-item" style="width: 40px;">{{{operatorField}}}</span>
                <span data-field="value" class="input-group-item input-group-item-middle">{{{valueField}}}</span>
                <span class="small input-group-addon radius-right" style="max-width: 60px;">{{translate 'days' scope='Workflow'}}</span>
            </div>
        </div>
    </div>
{{/if}}

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/condition-fields/subjects/text-input.tpl
{{#if readOnly}}
    <code>{{value}}</code>
{{else}}
    <input type="text" class="form-control input-sm" data-name="subject" value="{{value}}">
{{/if}}

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/condition-fields/subjects/field.tpl
{{#if readOnly}}
    {{{listHtml}}}
{{else}}
    <span data-field="value">{{{valueField}}}</span>
{{/if}}

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/condition-fields/subjects/enum-input.tpl
{{#if readOnly}}
    <code>
{{/if}}
<div
    class="field-container"
    style="display: inline-block;{{#unless readOnly}} min-width: 100%;{{/unless}}"
>{{{field}}}</div>
{{#if readOnly}}
    </code>
{{/if}}

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/actions/update-related-entity.tpl
<div class="row">
    {{#unless readOnly}}
        <div class="col-md-1">
            <button class="btn btn-default btn-sm btn-icon" type="button" data-action='editAction'><span class="fas fa-pencil-alt fa-sm"></span></button>
            <div>
                <a class="btn btn-text btn-sm btn-icon drag-handle"><span class="fas fa-grip fa-sm fa-rotate-90"></span></a>
            </div>
        </div>
    {{/unless}}

    <div class="col-md-10">
        {{translate actionType scope='Workflow' category='actionTypes'}}
        {{#if linkTranslated}} <span class="text-muted chevron-right"></span> {{{linkTranslated}}}{{/if}}
        {{#if parentEntityTypeTranslated}} <span class="text-muted chevron-right"></span> {{{parentEntityTypeTranslated}}}{{/if}}

        <div class="field-list small" style="margin-top: 12px;">
            {{#if actionData.fieldList}}
                {{#each actionData.fieldList}}
                    <div class="field-row cell form-group" data-field="{{./this}}">
                        <label class="control-label">{{translate ./this category='fields' scope=../linkedEntityName}}</label>
                        {{#if (lookup ../fieldActionLabelMap this)}}
                            <span class="text-muted"> · {{lookup ../fieldActionLabelMap this}}</span>
                        {{/if}}
                        <div class="field-container field" data-field="{{./this}}"></div>
                    </div>
                {{/each}}
            {{/if}}
        </div>

        {{#if actionData.linkList}}
        {{#if actionData.linkList.length}}
        <div class="field-row cell form-group" data-field="linkList">
            <label class="control-label small">{{translate 'linkListShort' category='fields' scope='Workflow'}}</label>
            <div class="field small" data-name="linkList">{{{linkList}}}</div>
        </div>
        {{/if}}
        {{/if}}

        <div class="field hidden" data-name="formula">{{{formula}}}</div>

    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/actions/update-created-entity.tpl
<div class="row">
    {{#unless readOnly}}
        <div class="col-md-1">
            <button class="btn btn-default btn-sm btn-icon" type="button" data-action='editAction'><span class="fas fa-pencil-alt fa-sm"></span></button>
            <div>
                <a class="btn btn-text btn-sm btn-icon drag-handle"><span class="fas fa-grip fa-sm fa-rotate-90"></span></a>
            </div>
        </div>
    {{/unless}}

    <div class="col-md-10">
        {{translate actionType scope='Workflow' category='actionTypes'}}
        {{#if entityTypeTranslated}}{{#if linkTranslated}} <span class="text-muted chevron-right"></span> {{linkTranslated}}{{/if}} <span class="text-muted chevron-right"></span> {{{entityTypeTranslated}}}{{/if}}
        {{#if text}}
            '{{text}}'
        {{else}}
            {{#if numberId}} #{{numberId}}{{/if}}
        {{/if}}

        <div class="field-list small" style="margin-top: 12px;">
            {{#if actionData.fieldList}}
                {{#each actionData.fieldList}}
                    <div class="field-row cell form-group" data-field="{{./this}}">
                        <label class="control-label">{{translate ./this category='fields' scope=../linkedEntityName}}</label>
                        {{#if (lookup ../fieldActionLabelMap this)}}
                            <span class="text-muted"> · {{lookup ../fieldActionLabelMap this}}</span>
                        {{/if}}
                        <div class="field-container field" data-field="{{./this}}"></div>
                    </div>
                {{/each}}
            {{/if}}
        </div>

        <div class="field hidden" data-name="formula">{{{formula}}}</div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/actions/trigger-workflow.tpl
<div class="row">
    {{#unless readOnly}}
        <div class="col-md-1">
            <button class="btn btn-default btn-sm btn-icon" type="button" data-action='editAction'><span class="fas fa-pencil-alt fa-sm"></span></button>
            <div>
                <a class="btn btn-text btn-sm btn-icon drag-handle"><span class="fas fa-grip fa-sm fa-rotate-90"></span></a>
            </div>
        </div>
    {{/unless}}

    <div class="col-md-10">
        {{translate actionType scope='Workflow' category='actionTypes'}}

        <div class="field-list small" style="margin-top: 12px;">
            <div class="field-row cell form-group execution-time-container" data-field="execution-time">
                <div class="field" data-field="execution-time">{{{executionTime}}}</div>
            </div>

            <div class="cell form-group" data-name="target">
                <label class="control-label">{{translate 'target' category='fields' scope='Workflow'}}</label>
                <div class="field">{{{targetTranslated}}}</div>
            </div>

            {{#if actionData.workflowId}}
                <div class="field-row cell form-group" data-field="workflow">
                    <label class="control-label">{{translate 'Workflow' scope='Workflow' category='labels'}}</label>
                    <div class="field-container field field-workflow" data-field="workflow">{{{workflow}}}</div>
                </div>
            {{/if}}
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/actions/start-bpmn-process.tpl
<div class="row">
    {{#unless readOnly}}
        <div class="col-md-1">
            <button class="btn btn-default btn-sm btn-icon" type="button" data-action='editAction'><span class="fas fa-pencil-alt fa-sm"></span></button>
            <div>
                <a class="btn btn-text btn-sm btn-icon drag-handle"><span class="fas fa-grip fa-sm fa-rotate-90"></span></a>
            </div>
        </div>
    {{/unless}}

    <div class="col-md-10">
        {{translate actionType scope='Workflow' category='actionTypes'}}

        <div class="field-list small" style="margin-top: 12px;">
            <div class="cell form-group" data-name="target">
                <label class="control-label">{{translate 'target' category='fields' scope='Workflow'}}</label>
                <div class="field">{{{targetTranslated}}}</div>
            </div>

            {{#if actionData.flowchartId}}
                <div class="field-row cell form-group" data-name="flowchart">
                    <label class="control-label">{{translate 'BpmnFlowchart' category='scopeNames'}}</label>
                    <div class="field" data-name="flowchart">{{{flowchart}}}</div>
                </div>
            {{/if}}

            {{#if actionData.elementId}}
                <div class="field-row cell form-group" data-name="elementId">
                    <label class="control-label">{{translate 'startElementId' scope='BpmnProcess' category='fields'}}</label>
                    <div class="field" data-name="elementId">{{{elementId}}}</div>
                </div>
            {{/if}}
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/actions/send-request.tpl
<div class="row">
    {{#unless readOnly}}
        <div class="col-md-1">
            <button class="btn btn-default btn-sm btn-icon" type="button" data-action='editAction'><span class="fas fa-pencil-alt fa-sm"></span></button>
            <div>
                <a class="btn btn-text btn-sm btn-icon drag-handle"><span class="fas fa-grip fa-sm fa-rotate-90"></span></a>
            </div>
        </div>
    {{/unless}}

    <div class="col-md-10">
        {{translate actionType scope='Workflow' category='actionTypes'}}

        <div class="field-list small margin-top">
            <div class="cell form-group" data-name="requestType">
                <label class="control-label">{{translate 'requestType' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="requestType">{{{requestType}}}</div>
            </div>

            <div class="cell form-group" data-name="requestUrl">
                <label class="control-label">{{translate 'requestUrl' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="requestUrl">{{{requestUrl}}}</div>
            </div>
            <div class="cell form-group" data-name="headers">
                <label class="control-label">{{translate 'headers' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="headers">{{{headers}}}</div>
            </div>
            <div class="cell form-group" data-name="contentType">
                <label class="control-label">{{translate 'requestContentType' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="contentType">{{{contentType}}}</div>
            </div>
            <div class="cell form-group {{#if actionData.contentVariable}} hidden {{/if}} " data-name="content">
                <label class="control-label">{{translate 'requestContent' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="content">{{{content}}}</div>
            </div>
            <div class="cell form-group{{#unless actionData.contentVariable}} hidden{{/unless}}" data-name="contentVariable">
                <label class="control-label">{{translate 'requestContentVariable' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="contentVariable">{{{contentVariable}}}</div>
            </div>
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/actions/send-email.tpl
<div class="row">
    {{#unless readOnly}}
        <div class="col-md-1">
            <button class="btn btn-default btn-sm btn-icon" type="button" data-action='editAction'><span class="fas fa-pencil-alt fa-sm"></span></button>
            <div>
                <a class="btn btn-text btn-sm btn-icon drag-handle"><span class="fas fa-grip fa-sm fa-rotate-90"></span></a>
            </div>
        </div>
    {{/unless}}

    <div class="col-md-10">
        {{translate actionType scope='Workflow' category='actionTypes'}}

        <div class="field-list small" style="margin-top: 12px;">
            <div class="field-row cell form-group execution-time-container" data-field="execution-time">
                <div class="field" data-field="execution-time">{{{executionTime}}}</div>
            </div>

            {{#if actionData.from}}
                <div class="field-row cell form-group" data-field="from">
                    <label class="control-label">{{translate 'From' scope='Workflow'}}</label>
                    <div class="field-container field" data-field="from">
                        {{#ifEqual actionData.from 'specifiedEmailAddress'}}
                            {{actionData.fromEmail}}
                        {{else}}
                            {{fromLabel}}
                        {{/ifEqual}}
                    </div>
                </div>
            {{/if}}

            {{#if actionData.to}}
                <div class="field-row cell form-group" data-field="to">
                    <label class="control-label">{{translate 'To' scope='Workflow'}}</label>
                    <div class="field-container field" data-field="to">
                        {{#ifEqual actionData.to 'specifiedEmailAddress'}}
                            {{actionData.toEmail}}
                        {{else}}
                            {{toLabel}}
                        {{/ifEqual}}
                        {{#ifEqual actionData.to 'specifiedTeams'}}
                            <div class="field-container field field-toSpecifiedTeams" data-field="toSpecifiedTeams">{{{toSpecifiedTeams}}}</div>
                        {{/ifEqual}}
                        {{#ifEqual actionData.to 'specifiedUsers'}}
                            <div class="field-container field field-toSpecifiedUsers" data-field="toSpecifiedUsers">{{{toSpecifiedUsers}}}</div>
                        {{/ifEqual}}
                        {{#ifEqual actionData.to 'specifiedContacts'}}
                            <div class="field-container field field-toSpecifiedContacts" data-field="toSpecifiedContacts">{{{toSpecifiedContacts}}}</div>
                        {{/ifEqual}}
                    </div>
                </div>
            {{/if}}

            {{#if actionData.cc}}
                <div class="field-row cell form-group" data-field="replyTo">
                    <label class="control-label">{{translate 'CC' scope='Workflow'}}</label>
                    <div class="field-container field" data-field="replyTo">
                        {{#ifEqual actionData.cc 'specifiedEmailAddress'}}
                            {{actionData.ccEmail}}
                        {{else}}
                            {{ccLabel}}
                        {{/ifEqual}}
                    </div>
                </div>
            {{/if}}

            {{#if actionData.replyTo}}
                <div class="field-row cell form-group" data-field="replyTo">
                    <label class="control-label">{{translate 'Reply-To' scope='Workflow'}}</label>
                    <div class="field-container field" data-field="replyTo">
                        {{#ifEqual actionData.replyTo 'specifiedEmailAddress'}}
                            {{actionData.replyToEmail}}
                        {{else}}
                            {{replyToLabel}}
                        {{/ifEqual}}
                    </div>
                </div>
            {{/if}}

            {{#if actionData.emailTemplateId}}
                <div class="field-row cell form-group" data-field="emailTemplate">
                    <label class="control-label">{{translate 'Email Template' scope='Workflow' category='labels'}}</label>
                    <div class="field-container field" data-field="emailTemplate">{{{emailTemplate}}}</div>
                </div>
            {{/if}}

            {{#if actionData.doNotStore}}
                <div class="field-row cell form-group" data-field="doNotStore">
                    <label class="control-label">{{translate 'doNotStore' scope='Workflow'}}</label>
                    <div class="field-container field-doNotStore" data-field="doNotStore">{{{doNotStore}}}</div>
                </div>
            {{/if}}

            <div class="field-row cell form-group" data-name="optOutLink">
                <label class="control-label">{{translate 'optOutLink' scope='Workflow' category='fields'}}</label>
                <div class="field-container field" data-name="optOutLink">{{{optOutLink}}}</div>
            </div>

            {{#if actionData.attachmentsVariable}}
                <div class="field-row cell form-group" data-name="attachmentsVariable">
                    <label class="control-label">{{translate 'attachmentsVariable' scope='Workflow' category='fields'}}</label>
                    <div class="field-container field" data-name="attachmentsVariable">{{{attachmentsVariable}}}</div>
                </div>
            {{/if}}
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/actions/run-service.tpl
<div class="row">
    {{#unless readOnly}}
        <div class="col-md-1">
            <button class="btn btn-default btn-sm btn-icon" type="button" data-action='editAction'><span class="fas fa-pencil-alt fa-sm"></span></button>
            <div>
                <a class="btn btn-text btn-sm btn-icon drag-handle"><span class="fas fa-grip fa-sm fa-rotate-90"></span></a>
            </div>
        </div>
    {{/unless}}

    <div class="col-md-10">
        {{translate actionType scope='Workflow' category='actionTypes'}}

        <div class="field-list small" style="margin-top: 12px;">

            <div class="cell form-group">
                <label class="control-label">{{translate 'Entity' scope='Workflow' category='labels'}}</label>
                <div class="field" data-name="target">{{{targetTranslated}}}
                </div>
            </div>

            <div class="field-row cell form-group" data-field="methodName">
                <label class="control-label">{{translate 'methodName' scope='Workflow' category='labels'}}</label>
                <div class="field-container field field-methodName" data-field="methodName">{{{methodName}}}</div>
            </div>

            {{#if actionData.additionalParameters}}
                <div class="field-row cell form-group" data-field="additionalParameters">
                    <label class="control-label">{{translate 'additionalParameters' category='labels' scope='Workflow'}}</label>
                    <div class="field-container field field-additionalParameters" data-field="additionalParameters">{{{additionalParameters}}}</div>
                </div>
            {{/if}}

        </div>
    </div>
</div>


_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/actions/relate-with-entity.tpl
<div class="row">
    {{#unless readOnly}}
        <div class="col-md-1">
            <button class="btn btn-default btn-sm btn-icon" type="button" data-action='editAction'><span class="fas fa-pencil-alt fa-sm"></span></button>
            <div>
                <a class="btn btn-text btn-sm btn-icon drag-handle"><span class="fas fa-grip fa-sm fa-rotate-90"></span></a>
            </div>
        </div>
    {{/unless}}

    <div class="col-md-10">
        {{translate actionType scope='Workflow' category='actionTypes'}} <span class="chevron-right text-muted"></span> {{{linkTranslated}}}

        <div class="field-list small" style="margin-top: 12px;">
            <div class="field-row cell form-group" data-name="entity">
                <div class="field-container field" data-name="entity"></div>
            </div>
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/actions/make-followed.tpl
<div class="row">
    {{#unless readOnly}}
        <div class="col-md-1">
            <button class="btn btn-default btn-sm btn-icon" type="button" data-action='editAction'><span class="fas fa-pencil-alt fa-sm"></span></button>
            <div>
                <a class="btn btn-text btn-sm btn-icon drag-handle"><span class="fas fa-grip fa-sm fa-rotate-90"></span></a>
            </div>
        </div>
    {{/unless}}

    <div class="col-md-10">
        {{translate actionType scope='Workflow' category='actionTypes'}}

        <div class="field-list small" style="margin-top: 12px;">

            <div class="cell form-group" data-name="whatToFollow">
                <label class="control-label">{{translate 'whatToFollow' category='fields' scope='Workflow'}}</label>
                <div class="field">{{{targetTranslated}}}</div>
            </div>

            <div class="cell form-group" data-name="recipient">
                <label class="control-label">{{translate 'whoFollow' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="recipient">{{{recipient}}}</div>
            </div>

            <div class="cell form-group" data-name="usersToMakeToFollow">
                <label class="control-label">{{translate 'User' category='scopeNamesPlural'}}</label>
                <div class="field" data-name="usersToMakeToFollow">{{{usersToMakeToFollow}}}</div>
            </div>

            <div class="cell form-group" data-name="specifiedTeams">
                <label class="control-label">{{translate 'Team' category='scopeNamesPlural'}}</label>
                <div class="field" data-name="specifiedTeams">{{{specifiedTeams}}}</div>
            </div>

        </div>

    </div>
</div>


_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/actions/execute-formula.tpl

<div class="row">
    {{#unless readOnly}}
        <div class="col-md-1">
            <button class="btn btn-default btn-sm btn-icon" type="button" data-action='editAction'><span class="fas fa-pencil-alt fa-sm"></span></button>
            <div>
                <a class="btn btn-text btn-sm btn-icon drag-handle"><span class="fas fa-grip fa-sm fa-rotate-90"></span></a>
            </div>
        </div>
    {{/unless}}

    <div class="col-md-10">
        {{translate actionType scope='Workflow' category='actionTypes'}}
        {{#if linkTranslated}} <span class="chevron-right text-muted"></span> {{{linkTranslated}}}{{/if}}
        {{#if parentEntityTypeTranslated}} <span class="chevron-right text-muted"></span> {{{parentEntityTypeTranslated}}}{{/if}}

        <div class="margin-top">
            <div class="field hidden" data-name="formula">{{{formula}}}</div>
        </div>

    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/actions/create-notification.tpl
<div class="row">
    {{#unless readOnly}}
        <div class="col-md-1">
            <button class="btn btn-default btn-sm btn-icon" type="button" data-action='editAction'><span class="fas fa-pencil-alt fa-sm"></span></button>
            <div>
                <a class="btn btn-text btn-sm btn-icon drag-handle"><span class="fas fa-grip fa-sm fa-rotate-90"></span></a>
            </div>
        </div>
    {{/unless}}

    <div class="col-md-10">
        {{translate actionType scope='Workflow' category='actionTypes'}}

        <div class="field-list small" style="margin-top: 12px;">
            {{#if actionData.recipient}}
                <div class="field-row cell form-group">
                    <label class="control-label">{{translate 'recipient' scope='Workflow'}}</label>
                    <div class="field-container">
                        {{recipientLabel}}
                    </div>
                    <div class="field-recipient" data-field="recipient">
                    </div>
                </div>
            {{/if}}

            {{#if actionData.messageTemplate}}
                <div class="field-row cell form-group" data-field="messageTemplate">
                    <label class="control-label">{{translate 'messageTemplate' scope='Workflow' category='labels'}}</label>
                    <div
                        class="field-container field field-messageTemplate complex-text"
                        data-field="messageTemplate"
                    >{{complexText messageTemplate}}</div>
                </div>
            {{/if}}
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/actions/base.tpl
<div class="row">
    {{#unless readOnly}}
        <div class="col-md-1">
            <button class="btn btn-default btn-sm btn-icon" type="button" data-action='editAction'><span class="fas fa-pencil-alt fa-sm"></span></button>
            <div>
                <a class="btn btn-text btn-sm btn-icon drag-handle"><span class="fas fa-grip fa-sm fa-rotate-90"></span></a>
            </div>
        </div>
    {{/unless}}

    <div class="col-md-10">
        {{translate actionType scope='Workflow' category='actionTypes'}}
        {{#unless noEntityName}}
        {{#if displayedLinkedEntityName}}
            {{#if linkTranslated}} <span class="text-muted chevron-right"></span> {{linkTranslated}}{{/if}}
            <span class="text-muted chevron-right"></span>
            {{{displayedLinkedEntityName}}}{{/if}}{{#if numberId}} #{{numberId}}{{/if}}
        {{/unless}}
        {{#if aliasId}} <span class="text-muted not-draggable chevron-right"></span> <span class="text-danger"><i>{{aliasId}}</i></span>{{/if}}

        <div class="field-list small" style="margin-top: 12px;">
            {{#if actionData.fieldList}}
                {{#each actionData.fieldList}}
                    <div class="field-row cell form-group" data-field="{{./this}}">
                        <label class="control-label">{{translate ./this category='fields' scope=../linkedEntityName}}</label>
                        {{#if (lookup ../fieldActionLabelMap this)}}
                            <span class="text-muted"> · {{lookup ../fieldActionLabelMap this}}</span>
                        {{/if}}
                        <div class="field-container field" data-field="{{./this}}"></div>
                    </div>
                {{/each}}
            {{/if}}
        </div>

        {{#if actionData.linkList}}
        {{#if actionData.linkList.length}}
        <div class="field-row cell form-group" data-field="linkList">
            <label class="control-label small">{{translate 'linkListShort' category='fields' scope='Workflow'}}</label>
            <div class="field small" data-name="linkList">{{{linkList}}}</div>
        </div>
        {{/if}}
        {{/if}}

        <div class="field hidden" data-name="formula">{{{formula}}}</div>

    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/actions/apply-assignment-rule.tpl
<div class="row">
    {{#unless readOnly}}
        <div class="col-md-1">
            <button class="btn btn-default btn-sm btn-icon" type="button" data-action='editAction'><span class="fas fa-pencil-alt fa-sm"></span></button>
            <div>
                <a class="btn btn-text btn-sm btn-icon drag-handle"><span class="fas fa-grip fa-sm fa-rotate-90"></span></a>
            </div>
        </div>
    {{/unless}}

    <div class="col-md-10">
        {{translate actionType scope='Workflow' category='actionTypes'}}

        <div class="field-list small" style="margin-top: 12px;">
            {{#if hasTarget}}
                <div class="cell form-group">
                    <label class="control-label">{{translate 'Entity' scope='Workflow' category='labels'}}</label>
                    <div class="field" data-name="target">{{{targetTranslated}}}
                    </div>
                </div>
            {{/if}}
            <div class="cell form-group">
                <label class="control-label">{{translate 'assignmentRule' scope='Workflow' category='fields'}}</label>
                <div class="field" data-name="assignmentRule">
                </div>
            </div>

            <div class="cell form-group">
                <label class="control-label">{{translate 'targetTeam' scope='Workflow' category='fields'}}</label>
                <div class="field" data-name="targetTeam"></div>
            </div>

            <div class="cell form-group">
                <label class="control-label">{{translate 'targetUserPosition' scope='Workflow' category='fields'}}</label>
                <div class="field" data-name="targetUserPosition"></div>
            </div>

            {{#if hasListReport}}
            <div class="cell form-group">
                <label class="control-label">{{translate 'listReport' scope='Workflow' category='fields'}}</label>
                <div class="field" data-name="listReport"></div>
            </div>
            {{/if}}
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-modals/update-related-entity.tpl
<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'Link' scope='Workflow'}}</label>
                <div class="field" data-name="link">{{{link}}}</div>
            </div>
        </div>
        <div class="row">
            <div class="cell col-sm-6 form-group hidden" data-name="parentEntityType">
                <label class="control-label">{{translate 'Entity Type' scope='Workflow'}}</label>
                <div class="field" data-name="parentEntityType">
                    {{{parentEntityType}}}
                </div>
            </div>
        </div>

        <div class="row">
            <div class="cell col-sm-6 form-group add-field-container">
                {{{addField}}}
            </div>
        </div>

        <div class="row">
            <div class="cell col-md-12">
                <div class="field-definitions form-group">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="cell form-group col-md-12 hidden" data-name="formula">
                <label class="control-label">{{translate 'Formula' scope='Workflow'}}</label>
                <div class="field" data-name="formula"></div>
            </div>
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-modals/update-entity.tpl
<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="row">
            <div class="cell col-sm-6 form-group add-field-container">
                {{{addField}}}
            </div>
        </div>

        <div class="row">
            <div class="cell col-md-12">
                <div class="field-definitions form-group">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="cell form-group col-md-12 hidden" data-name="formula">
                <label class="control-label">{{translate 'Formula' scope='Workflow'}}</label>
                <div class="field" data-name="formula"></div>
            </div>
        </div>
    </div>
</div>


_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-modals/update-created-entity.tpl
<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'Entity' scope='Workflow'}}</label>
                <div class="field" data-name="target">{{{target}}}</div>
            </div>
        </div>

        <div class="row">
            <div class="cell col-sm-6 form-group add-field-container">
                {{{addField}}}
            </div>
        </div>

        <div class="row">
            <div class="cell col-md-12">
                <div class="field-definitions form-group"></div>
            </div>
        </div>

        <div class="row">
            <div class="cell col-md-12 form-group hidden" data-name="formula">
                <label class="control-label">{{translate 'Formula' scope='Workflow'}}</label>
                <div class="field" data-name="formula"></div>
            </div>
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-modals/trigger-workflow.tpl
<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="execution-time-container form-group">{{{executionTime}}}</div>

        <div class="row">
            <div class="cell col-sm-6 form-group" data-name="target">
                <label class="control-label">{{translate 'target' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="target">
                    {{{target}}}
                </div>
            </div>
        </div>

        <div class="row">
            <div class="cell cell-workflow col-sm-6 form-group">
                <label class="control-label">{{translate 'Workflow Rule' scope='Workflow'}}</label>
                <div class="field field-workflow">{{{workflow}}}</div>
            </div>
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-modals/start-bpmn-process.tpl
<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="row">
            <div class="cell col-sm-6 form-group" data-name="target">
                <label class="control-label">{{translate 'target' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="target">
                    {{{target}}}
                </div>
            </div>
        </div>

        <div class="row">
            <div class="cell cell-workflow col-sm-6 form-group">
                <label class="control-label">{{translate 'BpmnFlowchart' category='scopeNames'}}</label>
                <div class="field"  data-name="flowchart">{{{flowchart}}}</div>
            </div>
        </div>

        <div class="row">
            <div class="cell cell-workflow col-sm-6 form-group">
                <label class="control-label">{{translate 'startElementId' scope='BpmnProcess' category='fields'}}</label>
                <div class="field" data-name="elementId">{{{elementId}}}</div>
            </div>
        </div>

    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-modals/send-request.tpl
<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="row">
            <div class="cell form-group col-md-6" data-name="requestType">
                <label class="control-label">{{translate 'requestType' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="requestType">{{{requestType}}}</div>
            </div>
        </div>

        <div class="row">
            <div class="cell form-group col-md-12" data-name="requestUrl">
                <label class="control-label">{{translate 'requestUrl' category='fields' scope='Workflow'}} *</label>
                <div class="field" data-name="requestUrl">{{{requestUrl}}}</div>
            </div>
        </div>

        <div class="row">
            <div class="cell form-group col-md-12" data-name="headers">
                <label class="control-label">{{translate 'headers' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="headers">{{{headers}}}</div>
            </div>
        </div>

        <div class="row">
            <div class="cell form-group col-md-6" data-name="contentType">
                <label class="control-label">{{translate 'requestContentType' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="contentType">{{{contentType}}}</div>
            </div>
        </div>

        <div class="row">
            <div class="cell form-group col-md-12" data-name="content">
                <label class="control-label">{{translate 'requestContent' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="content">{{{content}}}</div>
            </div>
        </div>

        <div class="row">
            <div class="cell form-group col-md-6" data-name="contentVariable">
                <label class="control-label">{{translate 'requestContentVariable' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="contentVariable">{{{contentVariable}}}</div>
            </div>
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-modals/send-email.tpl
<style type="text/css">
    .field-toSpecifiedTeams .list-group, .field-toSpecifiedUsers .list-group, .field-toSpecifiedContacts .list-group {
        margin-bottom: 0;
    }
</style>

<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="execution-time-container form-group">{{{executionTime}}}</div>
        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'From' scope='Workflow'}}</label>
                <div class="field field-from">{{{from}}}</div>
            </div>
            <div class="cell col-sm-6 from-email-container hidden form-group">
                <label class="control-label">{{translate 'Email Address' scope='Workflow'}}</label>
                <div class="field" data-name="fromEmailAddress">{{{fromEmailAddress}}}</div>
            </div>
        </div>
        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'To' scope='Workflow'}}</label>
                <div class="field field-to">{{{to}}}</div>
            </div>
            <div class="cell col-sm-6 to-email-container hidden form-group">
                <label class="control-label">{{translate 'Email Address' scope='Workflow'}}</label>
                <div class="field" data-name="toEmailAddress">{{{toEmailAddress}}}</div>
            </div>
            <div class="cell col-sm-6 toSpecifiedTeams-container hidden form-group">
                <label class="control-label">{{translate 'Team' category='scopeNamesPlural'}}</label>
                <div class="field-toSpecifiedTeams">
                    {{{toSpecifiedTeams}}}
                </div>
            </div>
            <div class="cell col-sm-6 toSpecifiedUsers-container hidden form-group">
                <label class="control-label">{{translate 'User' category='scopeNamesPlural'}}</label>
                <div class="field-toSpecifiedUsers">
                    {{{toSpecifiedUsers}}}
                </div>
            </div>
            <div class="cell col-sm-6 toSpecifiedContacts-container hidden form-group">
                <label class="control-label">{{translate 'Contact' category='scopeNamesPlural'}}</label>
                <div class="field-toSpecifiedContacts">
                    {{{toSpecifiedContacts}}}
                </div>
            </div>
        </div>
        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'CC' scope='Workflow'}}</label>
                <div class="field" data-name="cc">{{{cc}}}</div>
            </div>
            <div class="cell col-sm-6 cc-email-container hidden form-group">
                <label class="control-label">{{translate 'Email Address' scope='Workflow'}}</label>
                <div class="field" data-name="ccEmailAddress">{{{ccEmailAddress}}}</div>
            </div>
        </div>
        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'Reply-To' scope='Workflow'}}</label>
                <div class="field field-replyTo">{{{replyTo}}}</div>
            </div>
            <div class="cell col-sm-6 reply-to-email-container hidden form-group">
                <label class="control-label">{{translate 'Email Address' scope='Workflow'}}</label>
                <div class="field" data-name="replyToEmailAddress">{{{replyToEmailAddress}}}</div>
            </div>
        </div>
        <div class="row">
            <div class="cell cell-emailTemplate col-sm-6 form-group">
                <label class="control-label">{{translate 'Email Template' scope='Workflow'}}</label>
                <div class="field field-emailTemplate">{{{emailTemplate}}}</div>
            </div>
        </div>
        <div class="row">
            <div class="cell col-sm-6 doNotStore-container form-group">
                <label class="control-label">{{translate 'doNotStore' scope='Workflow'}}</label>
                <div class="field-doNotStore">
                    {{{doNotStore}}}
                </div>
            </div>
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'optOutLink' scope='Workflow' category='fields'}}</label>
                <div class="field" data-name="optOutLink">
                    {{{optOutLink}}}
                </div>
            </div>
        </div>

        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'attachmentsVariable' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="attachmentsVariable">
                    {{{attachmentsVariable}}}
                </div>
            </div>
        </div>

    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-modals/run-service.tpl
<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="cell cell-usersToMakeToFollow form-group">
            <div class="row">
                <div class="cell col-sm-6 form-group">
                    <label class="control-label">{{translate 'Entity' scope='Workflow' category='labels'}}</label>
                    <div class="field" data-name="target">
                        {{{target}}}
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="cell col-sm-6 form-group">
                    <label
                        class="control-label field-label-methodName"
                    >{{translate 'methodName' category='labels' scope='Workflow'}}</label>
                    <div class="field" data-name="methodName">{{{methodName}}}</div>
                </div>
            </div>

            <div class="row">
                <div class="cell col-sm-12 form-group">
                    <label
                        class="control-label field-label-additionalParameters"
                    >{{translate 'additionalParameters' category='labels' scope='Workflow'}}</label>
                    <div class="field" data-name="additionalParameters">{{{additionalParameters}}}</div>
                </div>
            </div>

            <div class="row">
                <div class="cell col-sm-12 form-group">
                    <div class="field" data-name="helpText">{{{helpText}}}</div>
                </div>
            </div>
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-modals/relate-with-entity.tpl
<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'Link' scope='Workflow'}}</label>
                <div class="field" data-name="link">{{{link}}}</div>
            </div>
        </div>

        <div class="row">
            <div class="cell col-sm-6 form-group">
                <div class="field" data-name="entity"></div>
            </div>
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-modals/make-followed.tpl
<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="row">
            <div class="cell form-group col-md-6" data-name="whatToFollow">
                <label class="control-label">{{translate 'whatToFollow' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="whatToFollow">{{{whatToFollow}}}</div>
            </div>
        </div>
        <div class="row">
            <div class="cell form-group col-md-6" data-name="recipient">
                <label class="control-label">{{translate 'whoFollow' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="recipient">{{{recipient}}}</div>
            </div>
        </div>
        <div class="row">
            <div class="cell form-group col-md-6" data-name="usersToMakeToFollow">
                <label class="control-label">{{translate 'User' category='scopeNamesPlural'}}</label>
                <div class="field" data-name="usersToMakeToFollow">{{{usersToMakeToFollow}}}</div>
            </div>
        </div>
        <div class="row">
            <div class="cell form-group col-md-6" data-name="specifiedTeams">
                <label class="control-label">{{translate 'Team' category='scopeNamesPlural'}}</label>
                <div class="field" data-name="specifiedTeams">{{{specifiedTeams}}}</div>
            </div>
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-modals/execute-formula.tpl
<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="cell form-group" data-name="formula">
            <label class="control-label">{{translate 'Formula' scope='Workflow'}}</label>
            <div class="field" data-name="formula">{{{formula}}}</div>
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-modals/create-related-entity.tpl
<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'Link' scope='Workflow'}}</label>
                <div class="field" data-name="link">{{{link}}}</div>
            </div>
        </div>

        <div class="row">
            <div class="cell col-sm-6 form-group add-field-container">
                {{{addField}}}
            </div>
        </div>

        <div class="row">
            <div class="cell col-md-12">
                <div class="field-definitions form-group">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="cell form-group col-md-12 hidden" data-name="formula">
                <label class="control-label">{{translate 'Formula' scope='Workflow'}}</label>
                <div class="field" data-name="formula"></div>
            </div>
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-modals/create-notification.tpl
<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'recipient' scope='Workflow'}}</label>
                <div class="field field-recipient">
                    {{{recipient}}}
                </div>
            </div>
            <div class="cell col-sm-6 cell-users form-group">
                <label class="control-label">{{translate 'users' scope='Workflow'}}</label>
                <div class="field field-users">
                    {{{users}}}
                </div>
            </div>
            <div class="cell col-sm-6 cell-specifiedTeams form-group">
                <label class="control-label">{{translate 'Team' category='scopeNamesPlural'}}</label>
                <div class="field field-specifiedTeams">
                    {{{specifiedTeams}}}
                </div>
            </div>
        </div>
        <div class="row">
            <div class="cell cell-messageTemplate col-sm-6 form-group">
                <label class="control-label">{{translate 'messageTemplate' scope='Workflow'}}</label>
                <div class="field field-messageTemplate">{{{messageTemplate}}}</div>
            </div>
            <div class="cell col-sm-6 form-group">
                {{complexText messageTemplateHelpText}}
            </div>
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-modals/create-entity.tpl
<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'Entity' scope='Workflow'}}</label>
                <div class="field" data-name="link">{{{link}}}</div>
            </div>
        </div>

        <div class="row">
            <div class="cell col-sm-6 form-group add-field-container">
                {{{addField}}}
            </div>
        </div>

        <div class="row">
            <div class="cell col-md-12">
                <div class="field-definitions form-group">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="cell form-group col-md-12 hidden" data-name="formula">
                <label class="control-label">{{translate 'Formula' scope='Workflow'}}</label>
                <div class="field" data-name="formula"></div>
            </div>
        </div>

        <div class="row">
            <div class="cell form-group col-md-6 hidden" data-name="linkList">
                <label class="control-label">{{translate 'linkList' category='fields' scope='Workflow'}}</label>
                <div class="field" data-name="linkList"></div>
            </div>
        </div>

    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-modals/apply-assignment-rule.tpl
<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">

        {{#if target}}
            <div class="row">
                <div class="cell col-sm-6 form-group">
                    <label class="control-label">{{translate 'Entity' scope='Workflow' category='labels'}}</label>
                    <div class="field" data-name="target">
                        {{{target}}}
                    </div>
                </div>
            </div>
        {{/if}}
        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'assignmentRule' scope='Workflow' category='fields'}}</label>
                <div class="field" data-name="assignmentRule">
                    {{{assignmentRule}}}
                </div>
            </div>
        </div>
        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'targetTeam' scope='Workflow' category='fields'}}</label>
                <div class="field" data-name="targetTeam">
                    {{{targetTeam}}}
                </div>
            </div>
        </div>
        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'targetUserPosition' scope='Workflow' category='fields'}}</label>
                <div class="field" data-name="targetUserPosition">
                    {{{targetUserPosition}}}
                </div>
            </div>
        </div>
        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'listReport' scope='Workflow' category='fields'}}</label>
                <div class="field" data-name="listReport">
                    {{{listReport}}}
                </div>
            </div>
        </div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-fields/shift-days.tpl
{{#if readOnly}}
    {{translate shiftDaysOperator scope='Workflow' category='labels'}} {{value}} {{translate unitValue scope='Workflow' category='labels'}}
{{else}}
<div class="row">
    <div class="col-sm-4">
        <span data-field="operator">{{{operatorField}}}</span>
    </div>
    <div class="col-sm-4">
        <span data-field="value">{{{valueField}}}</span>
    </div>
    <div class="col-sm-4">
        <span data-field="unit">{{{unitField}}}</span>
    </div>
</div>
{{/if}}

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-fields/execution-time.tpl
{{#if readOnly}}
    {{translate type scope='Workflow' category='labels'}}
    <span class="field-container hidden">{{{field}}}</span>
    <span class="shift-days-container hidden">{{{shiftDays}}}</span>
{{else}}
    <div class="row">
        <div class="col-sm-2">
            <span data-field="type">{{{typeField}}}</span>
        </div>
        <div class="field-container col-sm-4 hidden">{{{field}}}</div>
        <div class="shift-days-container col-sm-6 hidden">{{{shiftDays}}}</div>
    </div>
{{/if}}

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-fields/date-field.tpl
{{#if readOnly}}
    ({{stringValue}})
{{else}}
    <span data-field="executionField">{{{executionField}}}</span>
{{/if}}

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/workflow/action-fields/subjects/field.tpl
{{#if readOnly}}
    {{{listHtml}}}
{{else}}
    <span data-field="value">{{{valueField}}}</span>
{{/if}}

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/runtime-filters.tpl
<div class="row filters-row grid-auto-fill-xs">
    {{#each filterDataList}}
        <div class="filter col-sm-4 col-md-3" data-name="{{name}}">{{{var key ../this}}}</div>
    {{/each}}
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/result.tpl
<div class="header page-header">{{{header}}}</div></div>

<div class="panel panel-default">
    <div class="panel-body">
        <div class="report-container">{{{report}}}</div>
    </div>
</div>


_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/reports/charts/chart.tpl
<div class="chart-container" data-type="{{type}}"></div>
<div class="legend-container"></div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/record/export-grid.tpl
<div class="cell form-group" data-name="exportFormat">
    <label class="control-label" data-name="exportFormat">{{translate 'exportFormat' category='fields' scope='Report'}}</label>
    <div class="field" data-name="exportFormat">{{{exportFormat}}}</div>
</div>

{{#if column}}
<div class="cell form-group" data-name="column">
    <label class="control-label" data-name="column">{{translate 'column' category='fields' scope='Report'}}</label>
    <div class="field" data-name="column">{{{column}}}</div>
</div>
{{/if}}
_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/record/panels/report.tpl
<div class="report-container">{{{report}}}</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/modals/result.tpl

<div class="panel no-side-margin">
    <div class="panel-body report-container">{{{record}}}</div>
</div>


_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/modals/edit-group-by.tpl
<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="cell form-group">
            <label class="control-label">#1</label>
            <div class="field margin-bottom v1-container">
                {{{v1}}}
            </div>
        </div>
        <div class="cell form-group">
            <label class="control-label">#2</label>
            <div class="field margin-bottom v2-container">
                {{{v2}}}
            </div>
        </div>
    </div>
</div>


_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/modals/create.tpl
<div class="row">
    <div class="cell cell-entityType col-sm-6 form-group">
        <label class="field-label-entityType control-label">{{translate 'entityType' scope='Report' category='fields'}}</label>
        <div class="field field-entityType" data-name="entityType">
           {{{entityType}}}
        </div>
    </div>
</div>
<div class="list-group no-side-margin">
    <div class="list-group-item">
        <h4 class="list-group-item-heading">{{translate 'Grid Report' scope='Report'}}</h4>
        <p>{{translate 'gridReportDescription' category='messages' scope='Report'}}</p>
        <div class="form-group">
            <button class="btn btn-primary" data-action="create" data-type="Grid">{{translate 'Create'}}</button>
        </div>
    </div>
    <div class="list-group-item">
        <h4 class="list-group-item-heading">{{translate 'List Report' scope='Report'}}</h4>
        <p>{{translate 'listReportDescription' category='messages' scope='Report'}}</p>
        <button class="btn btn-primary" data-action="create" data-type="List">{{translate 'Create'}}</button>
    </div>
</div>
_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/filters/node.tpl
<style>
    .node-operator:last-child {
        display: none;
    }
</style>

<div class="item-list"></div>

<div class="buttons btn-group">
    <a class="small dropdown-toggle" role="button" tabindex="0" data-toggle="dropdown"><span class="fas fa-plus"></span> {{translate operator category='filtersGroupTypes' scope='Report'}}</a>
    <ul class="dropdown-menu">
        {{#unless fieldDisabled}}
        <li><a data-action="addField" role="button" tabindex="0" title="{{translate 'Add field' scope='Report'}}">{{translate 'Field' scope='Report'}}</a></li>
        {{/unless}}
        {{#unless orDisabled}}
        <li><a data-action="addOr" role="button" tabindex="0" title="{{translate 'Add OR group' scope='Report'}}">(... {{translate 'OR' scope='Report'}} ...)</a></li>
        {{/unless}}
        {{#unless andDisabled}}
        <li><a data-action="addAnd" role="button" tabindex="0" title="{{translate 'Add AND group' scope='Report'}}">(... {{translate 'AND' scope='Report'}} ...)</a></li>
        {{/unless}}
        {{#unless notDisabled}}
        <li><a data-action="addNot" role="button" tabindex="0" title="{{translate 'Add NOT group' scope='Report'}}">{{translate 'NOT' scope='Report'}} (...)</a></li>
        {{/unless}}
        {{#unless subQueryInDisabled}}
        <li><a data-action="addSubQueryIn" role="button" tabindex="0" title="{{translate 'Add IN group' scope='Report'}}">{{translate 'IN' scope='Report'}} (...)</a></li>
        {{/unless}}
        {{#unless complexExpressionDisabled}}
        <li><a data-action="addComplexExpression" role="button" tabindex="0" title="{{translate 'Add Complex expression' scope='Report'}}">{{translate 'Complex expression' scope='Report'}}</a></li>
        {{/unless}}
        {{#unless havingDisabled}}
        <li><a data-action="addHavingGroup" role="button" tabindex="0" title="{{translate 'Add Having group' scope='Report'}}">{{translate 'Having' scope='Report'}}</a></li>
        {{/unless}}
    </ul>
</div>
_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/filters/container-group.tpl
<a
    role="button"
    tabindex="0"
    class="pull-right"
    data-action="removeGroup"
    style="position: relative;"
><span class="fas fa-times"></span></a>

<div>
    <span
    >{{#if showGroupTypeLabel}}<span>{{translate type category='filtersGroupTypes' scope='Report'}}</span> {{/if}}(</span>
</div>
<!--suppress CssOverwrittenProperties -->
<div
    class="node"
    style="
        {{#unless noOffset}}
            left: var(--20px); width: calc(100% - var(--20px)); position: relative;
        {{else}}
            margin-left: var(--18px); width: calc(100% - var(--18px));
        {{/unless}}
    "
>{{{node}}}</div>
<div>)</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/filters/container-complex.tpl
<div class="clearfix">
    <a
        role="button"
        tabindex="0"
        class="pull-right"
        data-action="removeGroup"
    ><span class="fas fa-times"></span></a>
</div>
<div class="row form-group">
    <div class="col-md-12 function-container field" data-name="function">{{{function}}}</div>
    <div class="col-md-12 attribute-container field" data-name="attribute">{{{attribute}}}</div>
    <div class="col-md-12 expression-container field" data-name="expression">{{{expression}}}</div>
    <div class="col-md-12 operator-container field" data-name="operator" style="margin-top: var(--2px);">{{{operator}}}</div>
    <div class="col-md-12 value-container field" data-name="value">{{{value}}}</div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/fields/filters-control-2/edit.tpl
<div class="node-row">
    <div class="node">{{{node}}}</div>
</div>
<!--suppress CssUnusedSymbol -->
<style>
    .node-row {
        width: 64%;

        .item-list {
            > div {
                &:not(.node-operator) {
                    padding: var(--10px) var(--10px);
                    border: var(--1px) solid var(--default-border-color);
                    border-radius: var(--border-radius-small);
                    margin: var(--10px) 0;
                }

                label.control-label {
                    color: var(--gray-soft);
                    margin-bottom: var(--3px);
                }

                a.remove-filter,
                a[data-action="removeGroup"] {
                    position: relative;
                    top: var(--minus-4px);
                }

                &.node-operator {
                    > div {
                        margin-bottom: var(--2px);
                    }
                }

                &:has(> .filter) {
                    max-width: var(--340px);
                }

                &:has(> .clearfix) {
                    max-width: var(--400px);
                }

                &,
                > .clearfix {
                    > a[data-action="removeGroup"] {
                        visibility: hidden;
                    }
                }

                &:hover {
                    &,
                    > .clearfix {
                        > a[data-action="removeGroup"] {
                            visibility: visible;
                        }
                    }
                }
            }
        }
    }

    @media screen and (max-width: 640px) {
        .node-row {
            width: 100%;
        }
    }
</style>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/fields/filters-control/edit.tpl
<div class="grid-auto-fill-xs filters-row"></div>

<!--suppress CssUnusedSymbol -->
<style>
    .filters-row {
        grid-column-gap: var(--10px);

        > .column {
            > div {
                padding: var(--8px);
                border: var(--1px) solid var(--default-border-color);
                border-radius: var(--border-radius-small);

                .column-label {
                    min-height: calc(var(--40px) + var(--4px));
                    user-select: none;
                }

                .column-field {
                    margin-bottom: var(--2px);

                    .control-label {
                        margin-bottom: var(--1px);
                    }
                }
            }

            margin-bottom: var(--10px);
        }
    }
</style>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/fields/filters-control/detail.tpl
<span></span>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/fields/email-sending-weekdays/edit.tpl
{{#each days}}
    <div>
        <label style="cursor: pointer;">
            <input
                type="checkbox"
                data-name="{{../name}}"
                value="{{@index}}"
                {{#ifPropEquals ../selectedWeekdays @index true}} checked {{/ifPropEquals}}
                class="main-element form-checkbox"
            > {{.}}
        </label>
    </div>
{{/each}}

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/fields/email-sending-weekdays/detail.tpl
{{#each days}}
    <div>
        <label>
            <input
                type="checkbox" {{#ifPropEquals ../selectedWeekdays @index true}} checked {{/ifPropEquals}}
                class="main-element form-checkbox" disabled
            > {{.}} &nbsp;
        </label>
    </div>
{{/each}}

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/report/fields/email-sending-time/edit.tpl

<div class="input-group">
    <input class="form-control main-element" type="text" data-name="{{name}}-time" value="{{time}}" autocomplete="off">
    <span class="input-group-btn">
        <button type="button" class="btn btn-default time-picker-btn btn-icon" tabindex="-1"><i class="far fa-clock"></i></button>
    </span>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/dashlets/options/report.tpl
<div class="no-side-margin">
    <form id="dashlet-options">
        <div class="record middle no-side-margin">{{{record}}}</div>
    </form>
</div>

<div class="panel runtime-filters-panel">
    <div class="panel-body">
        <div class="runtime-filters-container"></div>
    </div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/bpmn-flowchart-element/fields/timer/edit.tpl
<div class="row">
    <div class="col-md-4">
        <select data-name="timerBase" class="form-control">
            {{#each timerBaseOptionDataList}}
            <option value="{{value}}"{{#if isSelected}} selected{{/if}}>{{label}}</option>
            {{/each}}
        </select>
    </div>
    <div class="col-md-2">
        <select data-name="timerShiftOperator" class="form-control hidden">
            {{#each timerShiftOperatorOptionDataList}}
            <option value="{{value}}"{{#if isSelected}} selected{{/if}}>{{label}}</option>
            {{/each}}
        </select>
    </div>
    <div class="col-md-3">
        <input data-name="timerShift" class="form-control hidden" value="{{timerShiftValue}}">
    </div>
    <div class="col-md-3">
        <select data-name="timerShiftUnits" class="form-control hidden">
            {{#each timerShiftUnitsOptionDataList}}
            <option value="{{value}}"{{#if isSelected}} selected{{/if}}>{{label}}</option>
            {{/each}}
        </select>
    </div>
</div>

<div class="formula-container">{{{timerFormula}}}</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/bpmn-flowchart-element/fields/timer/detail.tpl
<div>
    <span>{{{timerBaseTranslatedValue}}}</span>
    {{#if hasShift}}
        <span class="text-italic">{{{timerShiftOperatorTranslatedValue}}}</span>
        <span>{{{timerShiftValue}}}</span>
        <span>{{{timerShiftUnitsTranslatedValue}}}</span>
    {{/if}}
</div>
{{#if hasFormula}}
<div class="form-group"></div>
{{/if}}
<div class="formula-container">{{{timerFormula}}}</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/bpmn-flowchart-element/fields/flows-conditions/detail.tpl
<div class="flow-list-container list-group">
    {{#each flowDataList}}
    <div class="list-group-item" data-id="{{id}}">
        <div>
            {{#if ../isEditMode}}
            <div class="btn-group pull-right">
                <button
                    class="btn btn-default {{#if isTop}} hidden {{/if}} {{#if isBottom}} radius-right {{/if}}"
                    data-action="moveUp"
                    data-id="{{id}}"
                ><span class="fas fa-arrow-up"></span></button>
                <button
                    class="btn btn-default {{#if isBottom}} hidden {{/if}} {{#if isTop}} radius-left {{/if}} "
                    data-action="moveDown"
                    data-id="{{id}}"
                ><span class="fas fa-arrow-down"></span></button>
            </div>
            {{/if}}
            <h5>{{label}}</h5>
        </div>
        <div class="flow" data-id="{{id}}" style="margin-top: 20px;">{{{var id ../this}}}</div>
    </div>
    {{/each}}
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/bpmn-flowchart-element/fields/conditions/detail.tpl
<div class="conditions-container">{{{conditions}}}</div>
_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/bpmn-flowchart-element/fields/actions/detail.tpl
<div class="actions-container">{{{actions}}}</div>
_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/bpmn-flowchart/record/panels/flowchart.tpl
<div class="cell" data-name="flowchart">
    <label class="field-label" data-name="flowchart"></label>
    <div class="field" data-name="flowchart">{{{flowchart}}}</div>
</div>
_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/bpmn-flowchart/modals/element-detail.tpl
<div class="record-container record no-side-margin">{{{record}}}</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/bpmn-flowchart/fields/flowchart/edit.tpl
<link href="{{basePath}}client/custom/modules/advanced/css/bpmn.css" rel="stylesheet">

<!--suppress CssUnusedSymbol -->
<style>
    .flowchart-group-container.fullscreen {
        > .button-container {
            margin: var(--4px) var(--6px);
        }
    }

    .flowchart-group-container {
        button {
            span.fa-long-arrow-right {
                position: relative;
                top: var(--1px);
            }
        }

        > .button-container {
            user-select: none;
        }
    }


    [data-role="bpm-menu-item-text"] {
        padding-right: var(--20px);
    }

    [data-role="bpm-menu-item-checkbox"]:not(.hidden) + [data-role="bpm-menu-item-text"] {
        padding-right: var(--26px);
    }

    span[data-role="bpm-menu-item-icon"] {
        > svg {
            max-height: var(--18px);
        }

        width: var(--30px);
        height: var(--22px);
        text-align: center;
        vertical-align: middle;
        display: inline-block;
    }

    ul[data-role="bpm-element-menu"] {
        > li > a {
            padding-left: var(--10px);

            overflow: unset !important;
        }
    }

    span[data-role="bpm-menu-item-icon"] + span {
        padding-left: var(--8px);
    }

    span[data-role="bpm-button-icon"] {
        position: relative;
        top: var(--1px);
        padding-right: var(--1px);
        color: var(--gray-soft);
    }

    .fullscreen {
        ul[data-role="bpm-element-menu"][data-element-group="event"] {
            max-height: calc(100vh - var(--50px));
            overflow-y: scroll;
        }
    }
</style>

<div class="flowchart-group-container">
    <div class="button-container">
        <div class="btn-group">
            <button type="button" class="btn btn-default action" data-action="resetState" title="{{translate 'Hand tool' scope='BpmnFlowchart'}}"><span class="far fa-hand-paper"></span></button>
        </div>
        <div class="btn-group">
            <div class="btn-group">
                <button
                    type="button"
                    class="btn btn-default dropdown-toggle add-event-element"
                    data-toggle="dropdown"
                    title="{{translate 'Create Event tool' scope='BpmnFlowchart'}}"
                >
                    <span class="bpmn-icon-event" data-role="bpm-button-icon"></span>
                    {{translate 'Events' scope='BpmnFlowchart'}}
                    <span class="caret"></span></button>
                <ul
                    class="dropdown-menu"
                    data-role="bpm-element-menu"
                    data-element-group="event"
                >
                    {{#each elementEventDataList}}
                    {{#ifEqual name '_divider'}}
                    <li class="divider"></li>
                    {{else}}
                    <li>
                        <a
                            class="action"
                            role="button"
                            tabindex="0"
                            data-name="{{name}}"
                            data-action="setStateCreateFigure"
                        >
                            <span
                                class="fas fa-check pull-right{{#ifNotEqual ../currentElement name}} hidden{{/ifNotEqual}}"
                                data-role="bpm-menu-item-checkbox"
                            ></span>
                            <div
                                style="color: {{color}}"
                                data-role="bpm-menu-item-text"
                            ><span data-role="bpm-menu-item-icon">{{#if iconHtml}}{{{iconHtml}}}{{/if}}</span><span>{{translate name category='elements' scope='BpmnFlowchart'}}</span></div>
                        </a>
                    </li>
                    {{/ifEqual}}
                    {{/each}}
                </ul>
            </div>
            <div class="btn-group">
                <button
                    type="button"
                    class="btn btn-default dropdown-toggle add-gateway-element"
                    data-toggle="dropdown"
                    title="{{translate 'Create Gateway tool' scope='BpmnFlowchart'}}"
                ><span class="bpmn-icon-gateway" data-role="bpm-button-icon"></span>
                    {{translate 'Gateways' scope='BpmnFlowchart'}}
                    <span class="caret"></span></button>
                <ul
                    class="dropdown-menu"
                    data-role="bpm-element-menu"
                >
                    {{#each elementGatewayDataList}}
                    <li>
                        <a
                            class="action"
                            role="button"
                            tabindex="0"
                            data-name="{{name}}"
                            data-action="setStateCreateFigure"
                        >
                            <span
                                class="fas fa-check pull-right{{#ifNotEqual name ../currentElement}} hidden{{/ifNotEqual}}"
                                data-role="bpm-menu-item-checkbox"
                            ></span>
                            <div
                                data-role="bpm-menu-item-text"
                            ><span data-role="bpm-menu-item-icon">{{#if iconHtml}}{{{iconHtml}}}{{/if}}</span><span>{{translate name category='elements' scope='BpmnFlowchart'}}</span></div>
                        </a>
                    </li>
                    {{/each}}
                </ul>
            </div>
            <div class="btn-group">
                <button
                    type="button"
                    class="btn btn-default dropdown-toggle add-task-element"
                    data-toggle="dropdown"
                    title="{{translate 'Create Activity tool' scope='BpmnFlowchart'}}"
                ><span class="bpmn-icon-task" data-role="bpm-button-icon"></span>
                    {{translate 'Activities' scope='BpmnFlowchart'}}
                    <span class="caret"></span></button>
                <ul
                    class="dropdown-menu"
                    data-role="bpm-element-menu"
                >
                    {{#each elementTaskDataList}}
                    {{#ifEqual name '_divider'}}
                    <li class="divider"></li>
                    {{else}}
                    <li>
                        <a class="action" role="button" tabindex="0" data-name="{{name}}" data-action="setStateCreateFigure">
                            <span
                                class="fas fa-check pull-right{{#ifNotEqual ../currentElement name}} hidden{{/ifNotEqual}}"
                                data-role="bpm-menu-item-checkbox"
                            ></span>
                            <div
                                data-role="bpm-menu-item-text"
                            ><span data-role="bpm-menu-item-icon">{{#if iconHtml}}{{{iconHtml}}}{{/if}}</span><span>{{translate name category='elements' scope='BpmnFlowchart'}}</span></div>
                        </a>
                    </li>
                    {{/ifEqual}}
                    {{/each}}
                </ul>
            </div>
        </div>

        <div class="btn-group">
            <button
                type="button"
                class="btn btn-default action"
                data-action="setStateCreateFlow"
                title="{{translate 'Connect tool' scope='BpmnFlowchart'}}"
            ><span class="fa fa-long-arrow-right"></span></button>
        </div>

        <div class="btn-group">
            <button
                type="button"
                class="btn btn-default action"
                data-action="setStateRemove"
                title="{{translate 'Erase tool' scope='BpmnFlowchart'}}"
            ><i class="fa fa-eraser"></i></button>
        </div>
        <div class="btn-group">
            <button
                class="btn btn-text dropdown-toggle"
                data-toggle="dropdown"
            ><span class="fas fa-ellipsis-h"></span></button>
            <ul class="dropdown-menu dropdown-menu-with-icons pull-right">
                <li>
                    <a
                        role="button"
                        tabindex="0"
                        data-action="moveToCenter"
                    >
                        <span class="fas fa-arrows-to-dot fa-sm"></span>
                        <span class="item-text">{{translate 'Move to Center' scope='BpmnFlowchart'}}</span>
                    </a>
                </li>
                <li class="divider"></li>
                <li>
                    <a
                        role="button"
                        tabindex="0"
                        data-action="undo"
                    >
                        <span class="fas fa-rotate-left fa-sm"></span>
                        <span class="item-text">{{translate 'Undo' scope='BpmnFlowchart'}}</span>
                    </a>
                </li>
                <li>
                    <a
                        role="button"
                        tabindex="0"
                        data-action="redo"
                    >
                        <span class="fas fa-rotate-right fa-sm"></span>
                        <span class="item-text">{{translate 'Redo' scope='BpmnFlowchart'}}</span>
                    </a>
                </li>
            </ul>
        </div>

        <button type="button" class="btn btn-text action hidden" data-action="apply" title="{{translate 'Apply'}}"><i class="fas fa-save"></i></button>

        <div class="btn-group pull-right">
            <button
                type="button"
                class="btn btn-text action"
                data-action="switchFullScreenMode"
                title="{{translate 'Full Screen' scope='BpmnFlowchart'}}"
            ><i class="fas fa-maximize"></i></button>
        </div>

        <div class="btn-group pull-right">
            <button type="button" class="btn btn-text action" data-action="zoomOut" title="{{translate 'Zoom Out' scope='BpmnFlowchart'}}"><span class="fas fa-minus"></span></button>
            <button type="button" class="btn btn-text action" data-action="zoomIn" title="{{translate 'Zoom In' scope='BpmnFlowchart'}}"><span class="fas fa-plus"></span></button>
        </div>
    </div>

    <div class="flowchart-container" style="width: 100%; height: {{heightString}};"></div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/bpmn-flowchart/fields/flowchart/detail.tpl
<style>
    .flowchart-group-container.fullscreen {
        > .button-container {
            margin: var(--4px) var(--6px);
        }
    }
</style>

<div class="flowchart-group-container">
    <div class="button-container clearfix">
        <div class="btn-group pull-right">
            <button
                type="button"
                class="btn btn-text action"
                data-action="switchFullScreenMode"
                title="{{translate 'Full Screen' scope='BpmnFlowchart'}}"
            ><i class="fas fa-maximize"></i></button>
        </div>
        <div class="btn-group pull-right">
            <button type="button" class="btn btn-text action" data-action="zoomOut" title="{{translate 'Zoom Out' scope='BpmnFlowchart'}}"><span class="fas fa-minus"></span></button>
            <button type="button" class="btn btn-text action" data-action="zoomIn" title="{{translate 'Zoom In' scope='BpmnFlowchart'}}"><span class="fas fa-plus"></span></button>
        </div>
    </div>
    <div class="flowchart-container" style="width: 100%; height: {{heightString}};"></div>
</div>

_delimiter_mmxbd8peahd
custom/modules/advanced/res/templates/bpmn-flow-node/fields/element/detail.tpl
{{{value}}}