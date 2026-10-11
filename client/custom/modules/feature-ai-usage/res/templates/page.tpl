<div class="au-page" aria-busy="{{#if loading}}true{{else}}false{{/if}}">
    <header class="au-header">
        <div><div class="au-eyebrow">{{translate 'workspaceUsage' scope='AiUsage'}}</div><h2>{{translate 'title' scope='AiUsage'}}</h2><p class="text-muted">{{translate 'subtitle' scope='AiUsage'}}</p></div>
        <div class="au-controls">
            {{#if creditsUrl}}<a class="btn btn-default" href="{{creditsUrl}}">{{creditsLabel}}</a>{{/if}}
            <label><span class="sr-only">{{translate 'tenant' scope='AiUsage'}}</span><select class="form-control" data-au-tenant {{#if loading}}disabled{{/if}}>{{#each tenants}}<option value="{{id}}" {{#if selected}}selected{{/if}}>{{name}}</option>{{/each}}</select></label>
            <label><span class="sr-only">{{translate 'period' scope='AiUsage'}}</span><input class="form-control" type="text" autocomplete="off" placeholder="YYYY-MM" value="{{month}}" data-date-min-view-mode="1" data-date-end-date="{{maxMonth}}" data-au-month {{#if loading}}disabled{{/if}}></label>
            <button class="btn btn-default" data-au-refresh title="{{translate 'refresh' scope='AiUsage'}}" aria-label="{{translate 'refresh' scope='AiUsage'}}" {{#if loading}}disabled{{/if}}><span class="fas fa-sync-alt" aria-hidden="true"></span></button>
        </div>
    </header>
    {{#if error}}<div class="alert alert-danger" role="alert">{{error}} <button class="btn btn-link" data-au-refresh>{{translate 'retry' scope='AiUsage'}}</button></div>{{/if}}
    {{#if noAccess}}<div class="au-empty"><span class="fas fa-lock" aria-hidden="true"></span><h3>{{translate 'forbidden' scope='AiUsage'}}</h3></div>{{/if}}
    {{#if loading}}<div class="au-loading" role="status"><span class="fas fa-circle-notch fa-spin" aria-hidden="true"></span> {{translate 'loading' scope='AiUsage'}}</div>{{/if}}
    {{#if hasData}}
    <div class="au-content" {{#if loading}}inert{{/if}}>
    <div class="au-contract-meta"><span class="au-pill">{{modelText}}</span><span>{{period}}</span><span>{{timeZone}}</span><span class="au-freshness">{{translate 'updated' scope='AiUsage'}} {{generatedAt}}</span></div>
    <nav class="au-tabs" aria-label="{{translate 'usageViews' scope='AiUsage'}}">
        <button data-au-view="overview" class="{{#if overview}}active{{/if}}" aria-current="{{#if overview}}page{{else}}false{{/if}}">{{translate 'overview' scope='AiUsage'}}</button>
        <button data-au-view="breakdown" class="{{#if breakdown}}active{{/if}}" aria-current="{{#if breakdown}}page{{else}}false{{/if}}">{{translate 'breakdown' scope='AiUsage'}}</button>
        <button data-au-view="activity" class="{{#if activity}}active{{/if}}" aria-current="{{#if activity}}page{{else}}false{{/if}}">{{translate 'activity' scope='AiUsage'}}</button>
        {{#if showTokenUsage}}<button data-au-view="analytics" class="{{#if analyticsView}}active{{/if}}" aria-current="{{#if analyticsView}}page{{else}}false{{/if}}">{{translate 'analyticsTab' scope='AiUsage'}}</button>{{/if}}
    </nav>
    {{#unless analyticsView}}
    {{#unless ready}}<div class="alert alert-warning" role="status"><strong>{{translate 'configurationRequired' scope='AiUsage'}}</strong><p>{{configurationText}}</p><small>{{translate 'usageStillVisible' scope='AiUsage'}}</small></div>{{/unless}}
    <section class="au-allowance panel panel-default" aria-label="{{translate 'includedAllowance' scope='AiUsage'}}">
        <div class="au-allowance-heading"><div><span class="au-eyebrow">{{translate 'includedAllowance' scope='AiUsage'}}</span><h3>{{allowanceText}} <small>{{unit}} / {{translate 'month' scope='AiUsage'}}</small></h3></div><div class="au-allowance-state"><span class="au-pill au-state-{{progress.state}}">{{stateText}}</span><small class="text-muted">{{translate 'resets' scope='AiUsage'}} {{resetDate}}</small></div></div>
        <div class="au-meter" role="img" aria-label="{{translate 'covered' scope='AiUsage'}}: {{coveredText}}; {{translate 'remaining' scope='AiUsage'}}: {{remainingText}}; {{translate 'overage' scope='AiUsage'}}: {{overageText}}"><span class="au-covered" style="width:{{progress.coveredWidth}}%"></span><span class="au-overage" style="width:{{progress.overageWidth}}%"></span></div>
        <div class="au-meter-labels"><span><i class="au-dot au-covered"></i> <strong>{{coveredText}}</strong> {{translate 'covered' scope='AiUsage'}}</span><span><strong>{{remainingText}}</strong> {{translate 'remainingShort' scope='AiUsage'}}</span><span><i class="au-dot au-overage"></i> <strong>{{overageText}}</strong> {{translate 'overage' scope='AiUsage'}}</span><span class="text-muted">{{percentageText}} {{translate 'consumedShort' scope='AiUsage'}}</span></div>
    </section>
    <div class="au-card-grid">
        {{#each cards}}<section class="panel panel-default au-card {{#if emphasis}}au-card-overage{{/if}}">
            <div class="panel-heading"><h4 class="panel-title">{{title}}</h4><details class="au-card-menu"><summary aria-label="{{translate 'cardActions' scope='AiUsage'}}"><span class="fas fa-ellipsis-h" aria-hidden="true"></span></summary><div><button data-au-card>{{translate 'viewDetails' scope='AiUsage'}}</button><button data-au-refresh>{{translate 'refresh' scope='AiUsage'}}</button></div></details></div>
            <div class="panel-body"><div class="au-primary numeric-text text-primary" title="{{exact}}" aria-label="{{exact}}">{{value}}</div><div class="au-card-footer text-muted"><span title="{{caption}}">{{caption}}</span><span title="{{period}}">{{period}}</span></div></div>
        </section>{{/each}}
    </div>
    {{#if waived}}<p class="au-footnote text-muted"><strong>{{waived}} {{translate 'waivedRuns' scope='AiUsage'}}</strong> — {{translate 'waivedExplanation' scope='AiUsage'}}</p>{{/if}}
    {{#if pending}}<p class="au-footnote text-muted"><strong>{{pending}} {{translate 'pendingRuns' scope='AiUsage'}}</strong> — {{translate 'pendingExplanation' scope='AiUsage'}}</p>{{/if}}
    <details class="au-explanation"><summary>{{translate 'howChargesWork' scope='AiUsage'}}</summary><p>{{translate 'billingExplanation' scope='AiUsage'}}</p>
        {{#if billingModelExplanation}}<p>{{billingModelExplanation}}</p>{{/if}}
        {{#each rates}}<div class="au-rate"><strong>{{fromText}} – {{toText}} · {{currency}}</strong>{{#if unitPriceText}}<span>{{translate 'unitPrice' scope='AiUsage'}}: {{unitPriceText}}</span>{{/if}}{{#if packSize}}<span>{{packSize}} {{translate 'engagementsPerPack' scope='AiUsage'}}</span>{{/if}}{{#if basePriceText}}<span>{{translate 'basePrice' scope='AiUsage'}}: {{basePriceText}} · {{translate 'extraPrice' scope='AiUsage'}}: {{extraPriceText}} · {{includedTurns}} {{translate 'includedCustomerTurns' scope='AiUsage'}}</span>{{/if}}</div>{{/each}}
        {{#each chargeLines}}<div class="au-rate">{{#if base}}<span>{{translate 'baseCharges' scope='AiUsage'}}: {{base}}</span><span>{{translate 'extraCharges' scope='AiUsage'}}: {{extras}}</span>{{/if}}<strong>{{translate 'charges' scope='AiUsage'}} ({{currency}}): {{total}}</strong></div>{{/each}}
    </details>
    {{/unless}}

    {{#if analyticsView}}{{#if showTokenUsage}}{{#if analytics}}
    <section class="panel panel-default au-internal" aria-label="{{translate 'internalAnalytics' scope='AiUsage'}}">
        <div class="au-section-heading"><h3>{{translate 'internalAnalytics' scope='AiUsage'}}</h3><span class="au-pill">{{translate 'adminOnly' scope='AiUsage'}}</span></div>
        <p class="text-muted au-analytics-intro">{{translate 'analyticsIntro' scope='AiUsage'}}</p>
        <div class="au-analytics-summary">
            <div><span>{{translate 'monthTokens' scope='AiUsage'}}</span><strong class="numeric-text">{{analytics.monthTotal}}</strong></div>
            {{#if analytics.previousTotal}}<div><span>{{translate 'previousTokens' scope='AiUsage'}}</span><strong class="numeric-text">{{analytics.previousTotal}}</strong></div>{{/if}}
            {{#if analytics.change}}<div><span>{{translate 'tokenChange' scope='AiUsage'}}</span><strong class="numeric-text">{{analytics.change}}</strong></div>{{/if}}
        </div>
        <div class="au-filterbar">
            <label>{{translate 'kind' scope='AiUsage'}}<select class="form-control" data-au-filter="kind"><option value="">{{translate 'all' scope='AiUsage'}}</option>{{#each kinds}}<option value="{{value}}" {{#if selected}}selected{{/if}}>{{label}}</option>{{/each}}</select></label>
            <label>{{translate 'from' scope='AiUsage'}}<input class="form-control" type="date" min="{{minDate}}" max="{{maxDate}}" value="{{filters.from}}" data-au-filter="from"></label>
            <label>{{translate 'to' scope='AiUsage'}}<input class="form-control" type="date" min="{{minDate}}" max="{{maxDate}}" value="{{filters.to}}" data-au-filter="to"></label>
        </div>
        <div class="au-filter-summary"><strong>{{filteredRunsText}} {{translate 'runs' scope='AiUsage'}}</strong><span class="text-muted">{{translate 'analyticsScope' scope='AiUsage'}}</span>{{#if hasFilters}}<button class="btn btn-link" data-au-clear>{{translate 'clearFilters' scope='AiUsage'}}</button>{{/if}}</div>
        {{#if hasFilters}}<div class="au-chips">{{#each chips}}<button class="au-chip" data-au-remove="{{key}}" aria-label="{{translate 'removeFilter' scope='AiUsage'}}: {{label}} {{value}}">{{label}}: {{value}} <span aria-hidden="true">×</span></button>{{/each}}</div>{{/if}}
        <div class="au-token-grid">{{#each analytics.cards}}<div><span>{{label}}</span><strong class="numeric-text">{{value}}</strong></div>{{/each}}</div>
        <details class="au-explanation"><summary>{{translate 'understandTokens' scope='AiUsage'}}</summary>
        <p class="au-footnote text-muted">{{translate 'tokenAccountingHint' scope='AiUsage'}}</p>
        <p class="au-footnote text-muted">{{translate 'cacheAccountingHint' scope='AiUsage'}}</p>
        </details>
        <details class="au-explanation"><summary>{{translate 'efficiencyAndCoverage' scope='AiUsage'}}</summary>
            <dl class="au-metrics-list">{{#each analytics.diagnostics}}<div><dt>{{label}}</dt><dd>{{value}}</dd></div>{{/each}}</dl>
            <p class="au-footnote text-muted">{{translate 'analyticsCoverageHint' scope='AiUsage'}}</p>
        </details>
        <details class="au-explanation"><summary>{{translate 'analyticsSources' scope='AiUsage'}}</summary>
            <p class="au-footnote text-muted">{{translate 'sourceAccountingHint' scope='AiUsage'}}</p>
            {{#each analytics.sources}}<h4>{{label}}</h4><dl class="au-metrics-list">{{#each metrics}}<div><dt>{{label}}</dt><dd>{{value}}</dd></div>{{/each}}</dl>{{/each}}
        </details>
        {{#each analytics.tables}}<details class="au-explanation"><summary>{{label}}</summary><div class="table-responsive"><table class="table"><thead><tr><th>{{translate 'name' scope='AiUsage'}}</th>{{#each headers}}<th>{{this}}</th>{{/each}}</tr></thead><tbody>{{#each rows}}<tr><td>{{#if day}}<button class="btn btn-link" data-au-day="{{day}}">{{name}}</button>{{else}}{{name}}{{/if}}</td>{{#each cells}}<td class="numeric-text au-nowrap">{{this}}</td>{{/each}}</tr>{{/each}}</tbody></table></div></details>{{/each}}
    </section>
    {{/if}}{{/if}}{{/if}}

    {{#unless analyticsView}}
    {{#if overview}}
    <section class="panel panel-default au-chart-panel"><div class="au-section-heading"><div><h3>{{translate 'dailyUsage' scope='AiUsage'}}</h3><p class="text-muted">{{comparisonText}}</p></div><span class="text-muted">{{#if ready}}{{unit}}{{else}}{{translate 'engagements' scope='AiUsage'}}{{/if}}</span></div>
        <div class="au-chart" aria-label="{{translate 'dailyUsage' scope='AiUsage'}}">{{#each daily}}<button class="au-day" data-au-day="{{day}}" title="{{description}}" aria-label="{{description}}"><span class="au-bar-track"><span class="au-bar" style="height:{{height}}%"><span class="au-overage" style="height:{{overageHeight}}%"></span><span class="au-covered" style="height:{{coveredHeight}}%"></span></span></span><span class="au-day-label">{{short}}</span></button>{{/each}}</div>
        <div class="au-chart-legend">{{#if ready}}<span><i class="au-dot au-covered"></i> {{translate 'covered' scope='AiUsage'}}</span><span><i class="au-dot au-overage"></i> {{translate 'overage' scope='AiUsage'}}</span>{{else}}<span>{{translate 'engagements' scope='AiUsage'}}</span>{{/if}}<span class="text-muted">{{translate 'clickDay' scope='AiUsage'}}</span></div>
        <details class="au-daily-table"><summary>{{translate 'dailyTable' scope='AiUsage'}}</summary><div class="table-responsive"><table class="table"><thead><tr><th>{{translate 'date' scope='AiUsage'}}</th><th>{{translate 'consumed' scope='AiUsage'}}</th><th>{{translate 'covered' scope='AiUsage'}}</th><th>{{translate 'overage' scope='AiUsage'}}</th><th>{{translate 'charges' scope='AiUsage'}}</th></tr></thead><tbody>{{#each daily}}<tr><td><button class="btn btn-link" data-au-day="{{day}}">{{date}}</button></td><td>{{consumedText}}</td><td>{{coveredText}}</td><td>{{overageText}}</td><td>{{chargesText}}</td></tr>{{/each}}</tbody></table></div></details>
    </section>
    <div class="au-impact-grid"><div><span class="au-impact-value">{{runsText}}</span><span>{{translate 'engagements' scope='AiUsage'}}</span></div><div><span class="au-impact-value">{{conversationDaysText}}</span><span>{{translate 'uniqueConversationDays' scope='AiUsage'}}</span></div><div><span class="au-impact-value">{{conversationText}}</span><span>{{translate 'distinctConversations' scope='AiUsage'}}</span></div><div><span class="au-impact-value">{{opportunityDaysText}}</span><span>{{translate 'uniqueOpportunityDays' scope='AiUsage'}}</span></div><div><span class="au-impact-value">{{opportunityText}}</span><span>{{translate 'distinctOpportunities' scope='AiUsage'}}</span></div></div>
    <p class="au-footnote text-muted">{{translate 'conversationDaysExplanation' scope='AiUsage'}} {{timeZone}}. {{translate 'conversationDaysEpisodeDistinction' scope='AiUsage'}}</p>
    <details class="au-explanation">
        <summary>{{translate 'technicalDiagnostics' scope='AiUsage'}}</summary>
        <p><strong>{{coverageText}}</strong> · {{translate 'telemetryCoverage' scope='AiUsage'}}</p>
        <p class="au-footnote text-muted">{{translate 'coverageExplanation' scope='AiUsage'}}</p>
    </details>
    {{else}}
    <section class="panel panel-default au-explorer">
        <div class="au-filterbar">
            {{#if breakdown}}<label>{{translate 'groupBy' scope='AiUsage'}}<select class="form-control" data-au-dimension>{{#each dimensions}}<option value="{{value}}" {{#if selected}}selected{{/if}}>{{label}}</option>{{/each}}</select></label>{{/if}}
            <label>{{translate 'kind' scope='AiUsage'}}<select class="form-control" data-au-filter="kind"><option value="">{{translate 'all' scope='AiUsage'}}</option>{{#each kinds}}<option value="{{value}}" {{#if selected}}selected{{/if}}>{{label}}</option>{{/each}}</select></label>
            <label>{{translate 'from' scope='AiUsage'}}<input class="form-control" type="date" min="{{minDate}}" max="{{maxDate}}" value="{{filters.from}}" data-au-filter="from"></label><label>{{translate 'to' scope='AiUsage'}}<input class="form-control" type="date" min="{{minDate}}" max="{{maxDate}}" value="{{filters.to}}" data-au-filter="to"></label>
        </div>
        <div class="au-filter-summary"><strong>{{filteredRunsText}} {{translate 'engagements' scope='AiUsage'}}</strong>{{#if hasFailedRuns}}<span class="au-pill" title="{{translate 'billingHint_failed' scope='AiUsage'}}">{{failedRunsText}} {{translate 'failedNotBilled' scope='AiUsage'}}</span>{{/if}}<span class="text-muted">{{translate 'tenantTotalsUnchanged' scope='AiUsage'}}</span>{{#if hasFilters}}<button class="btn btn-link" data-au-clear>{{translate 'clearFilters' scope='AiUsage'}}</button>{{/if}}</div>
        {{#if hasFilters}}<div class="au-chips">{{#each chips}}<button class="au-chip" data-au-remove="{{key}}" aria-label="{{translate 'removeFilter' scope='AiUsage'}}: {{label}} {{value}}">{{label}}: {{value}} <span aria-hidden="true">×</span></button>{{/each}}</div>{{/if}}
        {{#if breakdown}}
        <div class="table-responsive"><table class="table au-breakdown-table"><thead><tr>
            <th>{{translate 'name' scope='AiUsage'}}</th><th>{{translate 'engagements' scope='AiUsage'}}</th><th>{{translate 'failedNotBilled' scope='AiUsage'}}</th><th>{{translate 'share' scope='AiUsage'}}</th><th>{{translate 'activeDays' scope='AiUsage'}}</th>
            {{#if showTokenUsage}}<th>{{translate 'totalTokens' scope='AiUsage'}}</th><th>{{translate 'uncachedInputTokens' scope='AiUsage'}}</th><th>{{translate 'tokenCacheHitPct' scope='AiUsage'}}</th><th>{{translate 'requests' scope='AiUsage'}}</th>{{/if}}
            {{#if showBreakdownBilling}}<th>{{translate 'billingUnits' scope='AiUsage'}}</th><th>{{translate 'covered' scope='AiUsage'}}</th><th>{{translate 'overage' scope='AiUsage'}}</th><th>{{translate 'charges' scope='AiUsage'}}</th>{{/if}}
        </tr></thead><tbody>{{#each rows}}<tr><td><button class="btn btn-link au-row-name" data-au-row="{{@index}}" {{#unless key}}disabled{{/unless}}>{{name}}</button></td><td class="numeric-text">{{runsText}}</td><td class="numeric-text">{{failedRunsText}}</td><td><div class="au-share"><span style="width:{{width}}%"></span></div><small>{{share}}%</small></td><td>{{daysText}}</td>
            {{#if ../showTokenUsage}}<td>{{metrics.total}}</td><td>{{metrics.uncached}}</td><td>{{metrics.cacheHit}}</td><td>{{metrics.requests}}</td>{{/if}}
            {{#if ../showBreakdownBilling}}<td>{{consumedText}}</td><td>{{coveredText}}</td><td>{{overageText}}</td><td>{{chargesText}}</td>{{/if}}</tr>{{/each}}</tbody></table></div>
        {{#unless hasRows}}<div class="au-empty">{{translate 'noActivity' scope='AiUsage'}}</div>{{/unless}}
        <p class="au-footnote text-muted">{{translate 'failureExplanation' scope='AiUsage'}} {{#unless showBreakdownBilling}}{{translate 'billingGroupingHint' scope='AiUsage'}} {{/unless}}{{translate 'breakdownExplanation' scope='AiUsage'}}</p>
        {{else}}
        <div class="table-responsive"><table class="table au-activity-table"><thead><tr>
            <th>{{translate 'date' scope='AiUsage'}}</th><th>{{translate 'kind' scope='AiUsage'}}</th><th>{{translate 'agent' scope='AiUsage'}}</th><th>{{translate 'source' scope='AiUsage'}}</th><th>{{translate 'action' scope='AiUsage'}}</th>
            {{#if showTokenUsage}}<th>{{translate 'inputTokens' scope='AiUsage'}}</th><th>{{translate 'outputTokens' scope='AiUsage'}}</th><th>{{translate 'cachedTokens' scope='AiUsage'}}</th><th>{{translate 'uncachedInputTokens' scope='AiUsage'}}</th><th>{{translate 'tokenCacheHitPct' scope='AiUsage'}}</th><th>{{translate 'requests' scope='AiUsage'}}</th>{{/if}}
            <th>{{translate 'billingStatus' scope='AiUsage'}}</th><th><span class="sr-only">{{translate 'viewDetails' scope='AiUsage'}}</span></th></tr></thead><tbody>{{#each activities}}<tr><td class="au-nowrap">{{date}}</td><td>{{kindText}}</td><td>{{agentText}}</td><td>{{sourceText}}</td><td class="au-action-cell" title="{{actionsText}}">{{actionsText}}</td>
            {{#if ../showTokenUsage}}<td class="numeric-text au-nowrap">{{inputTokensText}}</td><td class="numeric-text au-nowrap">{{outputTokensText}}</td><td class="numeric-text au-nowrap">{{cachedTokensText}}</td><td>{{metrics.uncached}}</td><td>{{metrics.cacheHit}}</td><td>{{metrics.requests}}</td>{{/if}}
            <td class="au-nowrap"><span class="au-pill {{billingClass}}" title="{{billingHint}}">{{billingText}}</span></td><td><button class="btn btn-default btn-sm" data-au-detail="{{id}}">{{translate 'viewDetails' scope='AiUsage'}}</button></td></tr>{{/each}}</tbody></table></div>
        {{#unless hasActivities}}<div class="au-empty">{{translate 'noAccessibleActivity' scope='AiUsage'}}</div>{{/unless}}
        <p class="au-footnote text-muted">{{translate 'activityExplanation' scope='AiUsage'}}</p>
        {{/if}}
        <div class="au-pagination"><span class="text-muted">{{pagination}}</span><div><button class="btn btn-default btn-sm" data-au-page="-1" {{#if previousDisabled}}disabled{{/if}}>{{translate 'previous' scope='AiUsage'}}</button> <button class="btn btn-default btn-sm" data-au-page="1" {{#if nextDisabled}}disabled{{/if}}>{{translate 'next' scope='AiUsage'}}</button></div></div>
    </section>
    {{/if}}
    {{/unless}}
    </div>
    {{/if}}
</div>
