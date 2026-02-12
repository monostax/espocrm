{{! Mobile-optimized search view template }}
<div class="mobile-search-container">
    {{! Main search row }}
    <div class="mobile-search-row">
        {{! Search input with icon }}
        <div class="mobile-search-input-wrapper">
            <span class="fas fa-search mobile-search-icon"></span>
            <input
                type="search"
                class="form-control mobile-text-filter"
                data-name="textFilter"
                value="{{textFilter}}"
                tabindex="0"
                autocomplete="espo-text-search"
                spellcheck="false"
                placeholder="{{translate 'Search' scope=entityType}}"
                {{#if textFilterDisabled}}disabled="disabled"{{/if}}
            >
            {{#if textFilter}}
            <button
                type="button"
                class="mobile-clear-btn"
                data-action="clearSearch"
                tabindex="0"
            >
                <span class="fas fa-times"></span>
            </button>
            {{/if}}
        </div>
        
        {{! Filter button with indicator }}
        <button
            type="button"
            class="btn btn-default mobile-filter-btn{{#if hasActiveFilters}} has-active-filters{{/if}}"
            data-action="showFilterModal"
            tabindex="0"
            title="{{translate 'Filter'}}"
        >
            <span class="fas fa-filter"></span>
            {{#if hasActiveFilters}}
            <span class="filter-badge">{{activeFilterCount}}</span>
            {{/if}}
        </button>
    </div>
    
    {{! View mode switcher (if available) }}
    {{#if hasViewModeSwitcher}}
    <div class="mobile-view-mode-switcher">
        {{#each viewModeDataList}}
        <button
            type="button"
            data-name="{{name}}"
            data-action="switchViewMode"
            class="btn btn-text btn-sm{{#ifEqual name ../viewMode}} active{{/ifEqual}}"
            tabindex="0"
            title="{{title}}"
        ><span class="{{iconClass}}"></span></button>
        {{/each}}
    </div>
    {{/if}}
</div>

{{! Hidden advanced filters container (for filter views) }}
<div class="advanced-filters hidden">
{{#each filterDataList}}
    <div class="filter filter-{{name}}" data-name="{{name}}">
        {{{var key ../this}}}
    </div>
{{/each}}
</div>
