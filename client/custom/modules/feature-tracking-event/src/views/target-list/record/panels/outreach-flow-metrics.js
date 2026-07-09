/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

/**
 * "Outreach Flow" bottom panel for the TargetList detail view.
 *
 * Renders the outbound batch funnel computed from the TrackingEvent ledger
 * via GET TargetList/{id}/outreachMetrics (FeatureTrackingEvent module):
 * per stage — distinct opportunities that entered it, % of the batch, and
 * average time to reach it from opportunity creation — plus won/lost
 * outcomes and won value.
 *
 * Server-side the endpoint enforces TargetList read ACL and strict
 * Opportunity access control for the current user (team/tenant isolation);
 * this panel is additionally gated by aclScope Opportunity in clientDefs.
 *
 * STATE metrics (counts/amounts) are plain fields on the record (Global's
 * OpportunityMetricsLoader) — this panel only covers FLOW.
 */
import BottomPanelView from 'views/record/panels/bottom';

// noinspection JSUnusedGlobalSymbols
export default class extends BottomPanelView {

    name = 'outreachFlowMetrics'

    templateContent = `
        {{#if hasData}}
            <div class="row margin-bottom">
                <div class="cell col-sm-3 col-xs-6">
                    <label class="control-label">{{translate 'Opportunities' category='labels' scope='TargetList'}}</label>
                    <div class="field">{{opportunityCount}}</div>
                </div>
                <div class="cell col-sm-3 col-xs-6">
                    <label class="control-label">{{translate 'Won' category='labels' scope='TargetList'}}</label>
                    <div class="field">{{wonCount}}{{#if wonValueDisplay}} ({{wonValueDisplay}}){{/if}}</div>
                </div>
                <div class="cell col-sm-3 col-xs-6">
                    <label class="control-label">{{translate 'Lost' category='labels' scope='TargetList'}}</label>
                    <div class="field">{{lostCount}}</div>
                </div>
                <div class="cell col-sm-3 col-xs-6">
                    <label class="control-label">{{translate 'Avg Time to Win' category='labels' scope='TargetList'}}</label>
                    <div class="field">{{#if avgTimeToWonDisplay}}{{avgTimeToWonDisplay}}{{else}}—{{/if}}</div>
                </div>
            </div>
            {{#if stages.length}}
                <table class="table table-no-overflow">
                    <thead>
                        <tr>
                            <th>{{translate 'Stage' category='labels' scope='TargetList'}}</th>
                            <th style="width: 20%">{{translate 'Entered' category='labels' scope='TargetList'}}</th>
                            <th style="width: 20%">%</th>
                            <th style="width: 25%">{{translate 'Avg Time to Reach' category='labels' scope='TargetList'}}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{#each stages}}
                            <tr>
                                <td>{{stageName}}</td>
                                <td>{{entered}}</td>
                                <td>{{enteredPct}}%</td>
                                <td>{{#if avgTimeDisplay}}{{avgTimeDisplay}}{{else}}—{{/if}}</td>
                            </tr>
                        {{/each}}
                    </tbody>
                </table>
            {{else}}
                <div class="text-muted">{{translate 'noFlowEvents' category='messages' scope='TargetList'}}</div>
            {{/if}}
        {{else}}
            <div class="text-muted">{{translate 'noOutreachOpportunities' category='messages' scope='TargetList'}}</div>
        {{/if}}
    `

    setup() {
        super.setup();

        this.metrics = null;

        this.buttonList = [
            {
                action: 'refreshOutreachMetrics',
                title: 'Refresh',
                html: '<span class="fas fa-sync"></span>',
            },
        ];

        this.wait(this.loadMetrics());
    }

    loadMetrics() {
        if (!this.model.id) {
            return Promise.resolve();
        }

        return Espo.Ajax
            .getRequest(`TargetList/${this.model.id}/outreachMetrics`)
            .then(response => {
                this.metrics = response || null;
            })
            .catch(() => {
                this.metrics = null;
            });
    }

    // noinspection JSUnusedGlobalSymbols
    actionRefreshOutreachMetrics() {
        this.loadMetrics().then(() => this.reRender());
    }

    data() {
        const metrics = this.metrics;

        if (!metrics || !metrics.opportunityCount) {
            return {hasData: false};
        }

        const stages = (metrics.stages || []).map(item => ({
            ...item,
            avgTimeDisplay: this.formatHours(item.avgHoursToReach),
        }));

        return {
            hasData: true,
            opportunityCount: metrics.opportunityCount,
            wonCount: metrics.wonCount,
            wonValueDisplay: metrics.wonValue
                ? this.getHelper().numberUtil.formatFloat(metrics.wonValue)
                : null,
            lostCount: metrics.lostCount,
            avgTimeToWonDisplay: this.formatHours(metrics.avgHoursToWon),
            stages: stages,
        };
    }

    /**
     * 3.2h below two days, 2.4d above.
     *
     * @param {?number} hours
     * @return {?string}
     */
    formatHours(hours) {
        if (hours === null || hours === undefined) {
            return null;
        }

        if (hours >= 48) {
            return (Math.round(hours / 24 * 10) / 10) + 'd';
        }

        return (Math.round(hours * 10) / 10) + 'h';
    }
}
