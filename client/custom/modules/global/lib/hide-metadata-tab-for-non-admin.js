/************************************************************************
 * Hide Metadata Tab for Non-Admin Users
 *
 * Applies globally to record detail views.
 ************************************************************************/

(function () {
    "use strict";

    Espo.loader
        .requirePromise("views/record/detail")
        .then(function (DetailViewModule) {
            const DetailView = DetailViewModule?.default || DetailViewModule;

            if (!DetailView || !DetailView.prototype) {
                return;
            }

            const originalAfterRender = DetailView.prototype.afterRender;

            DetailView.prototype.afterRender = function () {
                if (originalAfterRender) {
                    originalAfterRender.call(this);
                }

                if (this.getUser && !this.getUser().isAdmin()) {
                    this.hidePanel("metadata", true);
                }
            };
        })
        .catch(function () {
            // noop
        });
})();
