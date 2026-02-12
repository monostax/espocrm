<div class="detail" id="{{id}}" data-scope="{{scope}}" tabindex="-1">
    {{#unless buttonsDisabled}}
    <div class="detail-button-container button-container record-buttons">
        <div class="sub-container clearfix">
            <div class="btn-group actions-btn-group" role="group">
                {{#each buttonList}}
                    {{button name
                             scope=../entityType
                             label=label
                             labelTranslation=labelTranslation
                             style=style
                             hidden=hidden
                             html=html
                             title=title
                             text=text
                             className='btn-xs-wide detail-action-item'
                             disabled=disabled
                    }}
                {{/each}}
                {{#if dropdownItemList}}
                    <button
                        type="button"
                        class="btn btn-default dropdown-toggle dropdown-item-list-button{{#if dropdownItemListEmpty}} hidden{{/if}}"
                        data-toggle="dropdown"
                    ><span class="fas fa-ellipsis-h"></span></button>
                    <ul class="dropdown-menu pull-left">
                        {{#each dropdownItemList}}
                            {{#if this}}
                                {{dropdownItem
                                    name
                                    scope=../entityType
                                    label=label
                                    labelTranslation=labelTranslation
                                    html=html
                                    title=title
                                    text=text
                                    hidden=hidden
                                    disabled=disabled
                                    data=data
                                    className='detail-action-item'
                                }}
                            {{else}}
                                {{#unless @first}}
                                    {{#unless @last}}
                                        <li class="divider"></li>
                                    {{/unless}}
                                {{/unless}}
                            {{/if}}
                        {{/each}}
                    </ul>
                {{/if}}
            </div>
            {{#if navigateButtonsEnabled}}
                <div class="pull-right">
                    <div class="btn-group" role="group">
                        <button
                            type="button"
                            class="btn btn-text btn-icon action {{#unless previousButtonEnabled}} disabled{{/unless}}"
                            data-action="previous"
                            title="{{translate 'Previous Entry'}}"
                            {{#unless previousButtonEnabled}}disabled="disabled"{{/unless}}
                        >
                            <span class="fas fa-chevron-left"></span>
                        </button>
                        <button
                            type="button"
                            class="btn btn-text btn-icon action {{#unless nextButtonEnabled}} disabled{{/unless}}"
                            data-action="next"
                            title="{{translate 'Next Entry'}}"
                            {{#unless nextButtonEnabled}}disabled="disabled"{{/unless}}
                        >
                            <span class="fas fa-chevron-right"></span>
                        </button>
                    </div>
                </div>
            {{/if}}
        </div>
    </div>
    <div class="detail-button-container button-container edit-buttons hidden">
        <div class="sub-container clearfix">
            <div class="btn-group actions-btn-group" role="group">
                {{#each buttonEditList}}
                    {{button name
                             scope=../entityType
                             label=label
                             labelTranslation=labelTranslation
                             style=style
                             hidden=hidden
                             html=html
                             title=title
                             text=text
                             className='btn-xs-wide edit-action-item'
                             disabled=disabled
                    }}
                {{/each}}
                {{#if dropdownEditItemList}}
                    <button
                        type="button"
                        class="btn btn-default dropdown-toggle dropdown-edit-item-list-button{{#if dropdownEditItemListEmpty}} hidden{{/if}}"
                        data-toggle="dropdown"
                    ><span class="fas fa-ellipsis-h"></span></button>
                    <ul class="dropdown-menu pull-left">
                        {{#each dropdownEditItemList}}
                            {{#if this}}
                                {{dropdownItem
                                    name
                                    scope=../entityType
                                    label=label
                                    labelTranslation=labelTranslation
                                    html=html
                                    title=title
                                    text=text
                                    hidden=hidden
                                    disabled=disabled
                                    data=data
                                    className='edit-action-item'
                                }}
                            {{else}}
                                {{#unless @first}}
                                    {{#unless @last}}
                                        <li class="divider"></li>
                                    {{/unless}}
                                {{/unless}}
                            {{/if}}
                        {{/each}}
                    </ul>
                {{/if}}
            </div>
        </div>
    </div>
    {{/unless}}

    <div class="record-grid{{#if isWide}} record-grid-wide{{/if}}{{#if isSmall}} record-grid-small{{/if}}">
        <div class="left">
            {{#if hasMiddleTabs}}
            <div class="tabs middle-tabs btn-group" data-role="middle-tabs">
                {{#each middleTabDataList}}
                <button
                    class="btn btn-text btn-wide{{#if isActive}} active{{/if}}{{#if hidden}} hidden{{/if}}"
                    data-tab="{{@key}}"
                    data-role="middle-tab"
                    data-label="{{label}}"
                    {{#if icon}}data-icon="{{icon}}"{{/if}}
                    {{#if iconColor}}data-icon-color="{{iconColor}}"{{/if}}
                >{{#if icon}}<span class="{{icon}}" style="font-size: 14px; width: 14px; height: 14px;{{#if iconColor}} color: {{iconColor}};{{/if}}"></span> {{/if}}{{label}}</button>
                {{/each}}
                <button
                    class="btn btn-text btn-wide tab-more-btn hidden"
                    data-role="tab-more-btn"
                    title="{{translate 'More'}}"
                >{{translate 'More'}}</button>
            </div>
            <!-- Tab Drawer -->
            <div class="tab-drawer-backdrop" data-role="tab-drawer-backdrop"></div>
            <div class="tab-drawer" data-role="tab-drawer">
                <div class="tab-drawer-header">
                    <span class="tab-drawer-title">{{translate 'More'}}</span>
                    <button class="tab-drawer-close" data-role="tab-drawer-close">
                        <span class="fas fa-times"></span>
                    </button>
                </div>
                <div class="tab-drawer-content" data-role="tab-drawer-content"></div>
            </div>
            {{/if}}
            <div class="middle">{{{middle}}}</div>
            <div class="extra">{{{extra}}}</div>
            <div class="bottom">{{{bottom}}}</div>
        </div>
        <div class="side{{#if hasMiddleTabs}} tabs-margin{{/if}}">
        {{{side}}}
        </div>
    </div>
</div>

