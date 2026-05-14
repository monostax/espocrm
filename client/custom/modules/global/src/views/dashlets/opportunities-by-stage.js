/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('global:views/dashlets/opportunities-by-stage', ['crm:views/dashlets/opportunities-by-stage'], function (Dep) {

    return Dep.extend({

        url: function () {
            let url = 'Opportunity/action/reportByOpportunityStage';
            let dateFilter = this.getDateFilter();

            if (dateFilter && dateFilter !== 'none') {
                url += '?dateFilter=' + dateFilter;

                if (dateFilter === 'between') {
                    url += '&dateFrom=' + this.getOption('dateFrom') + '&dateTo=' + this.getOption('dateTo');
                }
            }

            if (this.getOption('funnelId')) {
                let separator = url.indexOf('?') === -1 ? '?' : '&';
                url += separator + 'funnelId=' + this.getOption('funnelId');
            }

            return url;
        },

        prepareData: function (response) {
            let sourceList = response.dataList || [];

            this.stageList = [];
            this.isEmpty = true;

            let data = [];
            let max = 0;

            sourceList.forEach((item, i) => {
                if (item.value) {
                    this.isEmpty = false;
                }

                if (item.value && item.value > max) {
                    max = item.value;
                }

                let stageLabel = item.stageName || this.translate('None');
                let row = {
                    data: [[item.value, sourceList.length - i]],
                    label: stageLabel,
                };

                if (item.style === 'success') {
                    row.color = this.successColor;
                }

                data.push(row);
                this.stageList.push(stageLabel);
            });

            this.max = max;

            return data;
        },
    });
});
