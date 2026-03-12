/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

define("feature-clinica:views/fields/procedimento-link-parent", [
    "views/fields/link-parent",
], function (Dep) {
    return Dep.extend({

        afterRender: function () {
            Dep.prototype.afterRender.call(this);

            if (!this.isEditMode() && !this.isSearchMode()) {
                return;
            }

            this._applyShortenedLabels();
        },

        _applyShortenedLabels: function () {
            var $type = this.$elementType;

            if (!$type || !$type.length || !$type[0] || !$type[0].selectize) {
                return;
            }

            var selectize = $type[0].selectize;
            var self = this;

            Object.keys(selectize.options).forEach(function (value) {
                var shortLabel = self.translate(value, 'scopeNamesShort');

                if (shortLabel === value) {
                    return;
                }

                selectize.updateOption(value, {value: value, text: shortLabel});
            });
        },
    });
});
