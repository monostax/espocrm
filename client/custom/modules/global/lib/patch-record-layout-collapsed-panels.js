(function () {
    "use strict";

    function applyPatch() {
        if (!window.Espo || !Espo.loader) {
            setTimeout(applyPatch, 50);

            return;
        }

        Espo.loader.require("views/record/detail", (detailRecordModule) => {
            const DetailRecordView =
                (detailRecordModule && detailRecordModule.default) ||
                detailRecordModule;

            const proto = DetailRecordView && DetailRecordView.prototype;

            if (!proto || proto.__collapsedPanelsLayoutDataPatched) {
                return;
            }

            const originalCreateMiddleView = proto.createMiddleView;

            proto.createMiddleView = function (callback) {
                const originalCreateView = this.createView;

                this.createView = (name, view, options, callbackInner) => {
                    if (name === "middle") {
                        const layoutData = {
                            ...(options?.layoutData || {}),
                        };

                        if (!("hiddenPanels" in layoutData)) {
                            layoutData.hiddenPanels =
                                this.recordHelper?.getHiddenPanels?.() || {};
                        }

                        if (!("collapsedPanels" in layoutData)) {
                            layoutData.collapsedPanels = {};
                        }

                        options = {
                            ...(options || {}),
                            layoutData,
                        };
                    }

                    return originalCreateView.call(
                        this,
                        name,
                        view,
                        options,
                        callbackInner,
                    );
                };

                try {
                    return originalCreateMiddleView.call(this, callback);
                } finally {
                    this.createView = originalCreateView;
                }
            };

            proto.__collapsedPanelsLayoutDataPatched = true;
        });

        Espo.loader.require("views/record/detail-middle", (detailMiddleModule) => {
            const DetailMiddleRecordView =
                (detailMiddleModule && detailMiddleModule.default) ||
                detailMiddleModule;

            const proto = DetailMiddleRecordView && DetailMiddleRecordView.prototype;

            if (!proto || proto.__collapsedPanelsLayoutDataPatched) {
                return;
            }

            const originalGetLayoutData = proto._getLayoutData;

            proto._getLayoutData = function () {
                const baseLayoutData = originalGetLayoutData ?
                    originalGetLayoutData.call(this) :
                    this.layoutData;

                const layoutData = {
                    ...(baseLayoutData || {}),
                };

                if (!("hiddenPanels" in layoutData)) {
                    layoutData.hiddenPanels =
                        this.recordHelper?.getHiddenPanels?.() || {};
                }

                if (!("collapsedPanels" in layoutData)) {
                    layoutData.collapsedPanels = this.getCollapsedPanels ?
                        this.getCollapsedPanels() :
                        {};
                }

                return layoutData;
            };

            proto.__collapsedPanelsLayoutDataPatched = true;
        });
    }

    applyPatch();
})();
