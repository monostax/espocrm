/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('global:views/import/index', ['views/import/index'], function (Dep) {

    return class extends Dep {

        changeStep(num, result) {
            this.step = num;

            if (num > 1) {
                this.setConfirmLeaveOut(true);
            }

            const viewName = num === 2 ?
                'global:views/import/step2' :
                'views/import/step' + num.toString();

            this.createView('step', viewName, {
                selector: '> .import-container',
                entityType: this.entityType,
                formData: this.formData,
                result: result,
            }, view => {
                view.render();
            });

            let url = '#Import';

            if (this.options.fromAdmin && this.step === 1) {
                url = '#Admin/import';
            }

            if (this.step > 1) {
                url += '/index/step=' + this.step;
            }

            this.getRouter().navigate(url, {trigger: false});
        }
    };
});
