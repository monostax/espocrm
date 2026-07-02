/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('global:views/dashlets/sales-pipeline', [
    'crm:views/dashlets/sales-pipeline',
    'global:helpers/dashboard-funnel',
], function (Dep, DashboardFunnel) {

    return Dep.extend({

        url: function () {
            let url = 'Opportunity/action/reportSalesPipelineByOpportunityStage';
            let dateFilter = this.getDateFilter();

            if (dateFilter && dateFilter !== 'none') {
                url += '?dateFilter=' + dateFilter;

                if (dateFilter === 'between') {
                    url += '&dateFrom=' + this.getOption('dateFrom') + '&dateTo=' + this.getOption('dateTo');
                }
            }

            let separator = url.indexOf('?') === -1 ? '?' : '&';

            if (this.getOption('teamId')) {
                url += separator + 'teamId=' + this.getOption('teamId');
                separator = '&';
            }

            if (this.getFunnelId()) {
                url += separator + 'funnelId=' + this.getFunnelId();
            }

            return url;
        },

        /**
         * The effective funnel id: the dashboard-wide funnel filter (when
         * one is selected in the dashboard header) wins over the dashlet's
         * own `funnel` option; otherwise falls back to the option.
         *
         * @return {string|null}
         */
        getFunnelId: function () {
            const dashboardFunnel = DashboardFunnel.getFunnel(this);

            if (dashboardFunnel) {
                return dashboardFunnel.id;
            }

            return this.getOption('funnelId') || null;
        },

        afterRender: function () {
            DashboardFunnel.listen(this, () => this.actionRefresh());

            Dep.prototype.afterRender.call(this);
        },

        prepareData: function (response) {
            let list = [];

            this.isEmpty = true;

            (response.dataList || []).forEach(item => {
                if (item.value) {
                    this.isEmpty = false;
                }

                list.push({
                    stageId: item.stageId,
                    stage: item.stageName,
                    stageTranslated: item.stageName,
                    style: item.style || 'default',
                    probability: item.probability,
                    value: item.value,
                    valueWeighted: item.valueWeighted,
                    count: item.count,
                });
            });

            return list;
        },

        draw: function () {
            let colors = Espo.Utils.clone(this.colorList);

            this.chartData.forEach((item, i) => {
                if (i + 1 > colors.length) {
                    colors.push('#164');
                }

                if (item.style === 'success' || item.probability === 100) {
                    colors[i] = this.successColor;
                }

                this.chartData[i].color = colors[i];
            });

            this.$container.empty();

            let tooltipStyleString =
                'opacity:0.7;background-color:#000;color:#fff;position:absolute;' +
                'padding:2px 8px;-moz-border-radius:4px;border-radius:4px;white-space:nowrap;';

            new EspoFunnel.Funnel(
                this.$container.get(0),
                {
                    colors: colors,
                    outlineColor: this.hoverColor,
                    callbacks: {
                        tooltipHtml: i => {
                            let value = this.chartData[i].value;

                            return this.chartData[i].stageTranslated +
                                '<br>' + this.currencySymbol +
                                '<span class="numeric-text">' +
                                this.formatNumber(value, true) +
                                '</span>';
                        },
                    },
                    tooltipClassName: 'flotr-mouse-value',
                    tooltipStyleString: tooltipStyleString,
                },
                this.chartData
            );

            this.drawLegend();
            this.adjustLegend();
        },
    });
});
