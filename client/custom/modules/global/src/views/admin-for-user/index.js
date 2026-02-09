/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

import View from "view";

class AdminForUserIndexView extends View {
    template = "global:admin-for-user/index";

    events = {
        /** @this AdminForUserIndexView */
        "click [data-action]": function (e) {
            Espo.Utils.handleAction(this, e.originalEvent, e.currentTarget);
        },
        /** @this AdminForUserIndexView */
        'keyup input[data-name="quick-search"]': function (e) {
            this.processQuickSearch(e.currentTarget.value);
        },
    };

    data() {
        return {
            panelDataList: this.panelDataList,
        };
    }

    afterRender() {
        const $quickSearch = this.$el.find('input[data-name="quick-search"]');

        if (this.quickSearchText) {
            $quickSearch.val(this.quickSearchText);
            this.processQuickSearch(this.quickSearchText);
        }

        // noinspection JSUnresolvedReference
        $quickSearch.get(0).focus({ preventScroll: true });
    }

    setup() {
        this.panelDataList = [];

        // Get the adminForUserPanel metadata
        const panels = this.getMetadata().get("app.adminForUserPanel") || {};

        for (const name in panels) {
            const panelItem = Espo.Utils.cloneDeep(panels[name]);

            panelItem.name = name;
            panelItem.itemList = panelItem.itemList || [];
            panelItem.label = this.translate(
                panelItem.label,
                "labels",
                "Configurations",
            );

            if (panelItem.itemList) {
                // Filter items by ACL - only show entities the user has access to
                panelItem.itemList = panelItem.itemList.filter((item) => {
                    // Extract entity type from URL like "#Configurations/ChatwootInboxIntegration"
                    const entityType = this.getEntityTypeFromUrl(item.url);

                    if (entityType) {
                        // Use ACL to check if user has read access to this entity
                        // This automatically handles roles, teams, and all permission logic
                        return this.getAcl().check(entityType, "read");
                    }

                    // If no entity type found, show the item (fallback)
                    return true;
                });

                panelItem.itemList.forEach((item) => {
                    item.label = this.translate(
                        item.label,
                        "labels",
                        "Configurations",
                    );

                    if (item.description) {
                        item.keywords = (
                            this.getLanguage().get(
                                "Configurations",
                                "keywords",
                                item.description,
                            ) || ""
                        ).split(",");

                        item.keywords = item.keywords.map((keyword) =>
                            keyword.trim().toLowerCase(),
                        );
                    } else {
                        item.keywords = [];
                    }
                });
            }

            // Only add panel if it has items after filtering
            if (panelItem.itemList && panelItem.itemList.length > 0) {
                this.panelDataList.push(panelItem);
            }
        }

        this.panelDataList.sort((v1, v2) => {
            if (!("order" in v1) && "order" in v2) {
                return 0;
            }

            if (!("order" in v2)) {
                return 0;
            }

            return v1.order - v2.order;
        });
    }

    processQuickSearch(text) {
        text = text.trim();

        this.quickSearchText = text;

        const $noData = this.$noData || this.$el.find(".no-data");

        $noData.addClass("hidden");

        if (!text) {
            this.$el.find(".admin-content-section").removeClass("hidden");
            this.$el.find(".admin-content-row").removeClass("hidden");

            return;
        }

        text = text.toLowerCase();

        this.$el.find(".admin-content-section").addClass("hidden");
        this.$el.find(".admin-content-row").addClass("hidden");

        let anythingMatched = false;

        this.panelDataList.forEach((panel, panelIndex) => {
            let panelMatched = false;
            let panelLabelMatched = false;

            if (panel.label && panel.label.toLowerCase().indexOf(text) === 0) {
                panelMatched = true;
                panelLabelMatched = true;
            }

            panel.itemList.forEach((row, rowIndex) => {
                if (!row.label) {
                    return;
                }

                let matched = false;

                if (panelLabelMatched) {
                    matched = true;
                }

                if (!matched) {
                    matched = row.label.toLowerCase().indexOf(text) === 0;
                }

                if (!matched) {
                    const wordList = row.label.split(" ");

                    wordList.forEach((word) => {
                        if (word.toLowerCase().indexOf(text) === 0) {
                            matched = true;
                        }
                    });

                    if (!matched) {
                        matched = ~row.keywords.indexOf(text);
                    }

                    if (!matched) {
                        if (text.length >= 3) {
                            row.keywords.forEach((word) => {
                                if (word.indexOf(text) === 0) {
                                    matched = true;
                                }
                            });
                        }
                    }
                }

                if (matched) {
                    panelMatched = true;

                    this.$el
                        .find(
                            '.admin-content-section[data-index="' +
                                panelIndex.toString() +
                                '"] ' +
                                '.admin-content-row[data-index="' +
                                rowIndex.toString() +
                                '"]',
                        )
                        .removeClass("hidden");

                    anythingMatched = true;
                }
            });

            if (panelMatched) {
                this.$el
                    .find(
                        '.admin-content-section[data-index="' +
                            panelIndex.toString() +
                            '"]',
                    )
                    .removeClass("hidden");

                anythingMatched = true;
            }
        });

        if (!anythingMatched) {
            $noData.removeClass("hidden");
        }
    }

    updatePageTitle() {
        this.setPageTitle(
            this.getLanguage().translate(
                "Configurations",
                "labels",
                "Configurations",
            ),
        );
    }

    /**
     * Extract entity type from URL like "#Configurations/ChatwootInboxIntegration"
     * @param {string} url - The URL to parse
     * @returns {string|null} - The entity type or null if not found
     */
    getEntityTypeFromUrl(url) {
        if (!url) {
            return null;
        }

        const match = url.match(/^#Configurations\/(.+)$/);
        if (match) {
            return match[1];
        }

        return null;
    }
}

export default AdminForUserIndexView;

