/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define('global:controllers/import', ['controllers/import'], function (Dep) {

    return class extends Dep {

        /**
         * @param {{
         *     step?: int|string,
         *     fromAdmin?: boolean,
         *     formData?: Object
         * }} o
         */
        actionIndex(o) {
            o = o || {};

            let step = null;

            if (o.step) {
                step = parseInt(o.step);
            }

            let formData = null;
            let fileContents = null;

            if (o.formData) {
                this.storedData = undefined;
            }

            if (this.storedData) {
                formData = this.storedData.formData;
                fileContents = this.storedData.fileContents;
            }

            if (!formData) {
                step = null;
            }

            formData = formData || o.formData;

            this.main('global:views/import/index', {
                step: step,
                formData: formData,
                fileContents: fileContents,
                fromAdmin: o.fromAdmin,
            }, view => {
                this.listenTo(view, 'change', () => {
                    this.storedData = {
                        formData: view.formData,
                        fileContents: view.fileContents,
                    };
                });

                this.listenTo(view, 'done', () => {
                    this.storedData = undefined;
                });

                view.render();
            });
        }
    };
});
