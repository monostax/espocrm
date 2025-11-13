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
