{{! Mobile filter modal template }}
<div class="mobile-filter-modal">
    {{! Header }}
    <div class="filter-modal-header">
        <h4>{{translate 'Filters'}}</h4>
        <button type="button" class="btn btn-text close-btn action" data-action="closeModal">
            <span class="fas fa-times"></span>
        </button>
    </div>
    
    {{! Body }}
    <div class="filter-modal-body">
        {{! Preset filters section }}
        {{#unless primaryFiltersDisabled}}
        <div class="filter-section">
            <div class="filter-section-title">{{translate 'Presets'}}</div>
            <div class="filter-options">
                <div class="filter-option action{{#unless presetName}} active{{/unless}}" data-action="selectPreset" data-name="">
                    <span class="option-label">{{translate 'all' category='presetFilters' scope=entityType}}</span>
                    <span class="fas fa-check check-icon{{#unless presetName}} visible{{/unless}}"></span>
                </div>
                {{#each presetFilterList}}
                <div class="filter-option action{{#ifEqual name ../presetName}} active{{/ifEqual}}" data-action="selectPreset" data-name="{{name}}">
                    <span class="option-label">{{#if label}}{{label}}{{else}}{{translate name category='presetFilters' scope=../entityType}}{{/if}}</span>
                    <span class="fas fa-check check-icon{{#ifEqual name ../presetName}} visible{{/ifEqual}}"></span>
                </div>
                {{/each}}
            </div>
        </div>
        {{/unless}}
        
        {{! Bool filters section }}
        {{#if boolFilterList.length}}
        <div class="filter-section">
            <div class="filter-section-title">{{translate 'Quick Filters'}}</div>
            <div class="filter-options">
                {{#each boolFilterList}}
                <div class="filter-option toggle-option action" data-action="toggleBoolFilter" data-name="{{this}}">
                    <span class="option-label">{{translate this scope=../entityType category='boolFilters'}}</span>
                    <div class="toggle-switch{{#ifPropEquals ../bool this true}} active{{/ifPropEquals}}">
                        <div class="toggle-slider"></div>
                    </div>
                </div>
                {{/each}}
            </div>
        </div>
        {{/if}}
        
        {{! Add field filter section }}
        <div class="filter-section">
            <div class="filter-section-title">{{translate 'Add Field Filter'}}</div>
            
            {{! Quick search for fields }}
            <div class="field-search-wrapper">
                <input type="search" class="form-control field-quick-search" placeholder="{{translate 'Search fields...'}}" data-role="fieldQuickSearch">
            </div>
            
            <div class="filter-options field-list">
                {{#each fieldFilterDataList}}
                <div class="filter-option field-option action{{#if checked}} has-filter{{/if}}" data-action="addFieldFilter" data-name="{{name}}">
                    <span class="option-label">{{label}}</span>
                    <span class="fas fa-plus add-icon"></span>
                </div>
                {{/each}}
            </div>
        </div>
        
        <div class="filter-section active-filters-section {{#unless hasAdvancedFilters}}hidden{{/unless}}">
            <div class="filter-section-title">{{translate 'Active Filters'}}</div>
            <div class="active-filters-list">
                {{#each advancedFilterViews}}
                <div class="active-filter-item" data-name="{{name}}">
                    <div class="active-filter-body" data-name="{{name}}"></div>
                </div>
                {{/each}}
            </div>
        </div>
    </div>
    
    {{! Footer }}
    <div class="filter-modal-footer">
        <button type="button" class="btn btn-default action" data-action="resetFilters">
            <span class="fas fa-undo"></span>
            <span>{{translate 'Reset'}}</span>
        </button>
        <button type="button" class="btn btn-primary action" data-action="applyFilters">
            <span class="fas fa-check"></span>
            <span>{{translate 'Apply'}}</span>
        </button>
    </div>
</div>
