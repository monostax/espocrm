/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax - Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

import EditRecordView from "views/record/edit";
import GlobalDetailRecordView from "global:views/record/detail";

class GlobalEditRecordView extends EditRecordView {
    middleView = "global:views/record/detail-middle";

    _gridRowMap = null;
    _bottomGridPanelNames = null;

    mobileTabBreakpoint = 767;
    tabDrawerOpen = false;
    drawerTabs = [];

    events = {
        "click .button-container .action": function (e) {
            const target = e.currentTarget;

            let actionItems = undefined;

            if (target.classList.contains("detail-action-item")) {
                actionItems = [...this.buttonList, ...this.dropdownItemList];
            } else if (target.classList.contains("edit-action-item")) {
                actionItems = [...this.buttonEditList, ...this.dropdownEditItemList];
            }

            Espo.Utils.handleAction(this, e.originalEvent, target, {
                actionItems: actionItems,
            });
        },
        'click [data-action="showMoreDetailPanels"]': function () {
            this.showMoreDetailPanels();
        },
        "click .middle-tabs > button": function (e) {
            const $btn = $(e.currentTarget);

            if ($btn.attr("data-role") === "tab-more-btn") {
                return;
            }

            const tab = parseInt($btn.attr("data-tab"));

            this.selectTab(tab);
        },
        'click [data-role="tab-more-btn"]': function () {
            this.toggleTabDrawer();
        },
        'click [data-role="tab-drawer-backdrop"]': function () {
            this.closeTabDrawer();
        },
        'click [data-role="tab-drawer-close"]': function () {
            this.closeTabDrawer();
        },
        'click [data-role="tab-drawer-item"]': function (e) {
            const tab = parseInt($(e.currentTarget).attr("data-tab"));

            this.closeTabDrawer();
            this.selectTab(tab);
        },
    };

    afterRender() {
        super.afterRender();
        this.initMobileTabGrouping();

        setTimeout(() => {
            this._readGridRowFromLayout();
            this._injectGridStyles();
            this.applyGridLayout();
            this._applyPanelIcons();
        }, 0);
    }
}

[
    "createMiddleView",
    "initMobileTabGrouping",
    "listenToResize",
    "calculateTabOverflow",
    "renderDrawerContent",
    "toggleTabDrawer",
    "openTabDrawer",
    "closeTabDrawer",
    "selectTab",
    "getMiddleTabDataList",
    "findLayoutItem",
    "getTabEntityType",
    "_applyPanelIcons",
    "_readGridRowFromLayout",
    "_injectGridStyles",
    "_waitForBottomGridPanels",
    "applyGridLayout",
    "_stripGridPanelClasses",
    "_syncGridRowVisibility",
    "adjustMiddlePanels",
].forEach((methodName) => {
    GlobalEditRecordView.prototype[methodName] = GlobalDetailRecordView.prototype[methodName];
});

export default GlobalEditRecordView;
