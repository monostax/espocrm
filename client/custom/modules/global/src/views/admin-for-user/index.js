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
        const panels = this.getMetadata().get("app.adminForUserPanel") || {};
        let sectionIndex = 0;
        let itemIndex = 0;

        for (const [name, definition] of this.sortedEntries(panels)) {
            const panel = {
                name,
                index: this.panelDataList.length,
                label: this.translate(definition.label, "labels", "Configurations"),
                sectionList: [],
            };
            // Flat panels from extensions remain supported.
            const sections = {...definition.sections};

            if (definition.itemList?.length) {
                sections[name] = {...definition, label: null};
            }

            for (const [sectionName, sectionDef] of this.sortedEntries(sections)) {
                const itemList = this.prepareItems(sectionDef.itemList || []);

                if (!itemList.length) {
                    continue;
                }

                itemList.forEach(item => { item.index = itemIndex++; });

                const lists = [
                    {itemList: itemList.filter(item => !item.secondary)},
                    {secondary: true, itemList: itemList.filter(item => item.secondary)},
                ].filter(list => list.itemList.length);

                panel.sectionList.push({
                    name: sectionName,
                    index: sectionIndex++,
                    label: sectionDef.label && this.translate(sectionDef.label, "labels", "Configurations"),
                    itemList,
                    lists,
                });
            }

            if (panel.sectionList.length) {
                this.panelDataList.push(panel);
            }
        }
    }

    sortedEntries(definitions) {
        return Object.entries(definitions).sort(([nameA, a], [nameB, b]) =>
            (a.order ?? 1000) - (b.order ?? 1000) || nameA.localeCompare(nameB)
        );
    }

    prepareItems(items) {
        const seen = new Set();

        return Espo.Utils.cloneDeep(items).filter(item => {
            if (item.url) {
                item.url = this.resolveItemUrl(item.url);

                if (!item.url || seen.has(item.url)) {
                    return false;
                }

                seen.add(item.url);
            }

            const entityType = this.getEntityTypeFromUrl(item.url);

            return !entityType || this.getAcl().check(entityType, "read");
        }).map(item => ({
            ...item,
            label: this.translate(item.label, "labels", "Configurations"),
            keywords: item.description
                ? (this.getLanguage().get("Configurations", "keywords", item.description) || "").split(",")
                : [],
        }));
    }

    normalizeSearch(text) {
        return text.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
    }

    matchesSearch(value, text) {
        const normalized = this.normalizeSearch(value || "");

        return normalized.startsWith(text) || normalized.split(/\s+/).some(word => word.startsWith(text));
    }

    getSearchMatches(text) {
        text = this.normalizeSearch(text.trim());
        const matches = new Map();

        for (const panel of this.panelDataList) {
            for (const section of panel.sectionList) {
                const priority = this.matchesSearch(panel.label, text) ? 2
                    : this.matchesSearch(section.label, text) ? 1 : 0;

                for (const item of section.itemList) {
                    if (!priority && !this.matchesSearch(item.label, text) &&
                        !item.keywords.some(keyword => this.matchesSearch(keyword.trim(), text))) {
                        continue;
                    }

                    const key = item.url || item.index;

                    // Prefer the searched context when a destination is cross-listed.
                    if (!matches.has(key) || matches.get(key).priority < priority) {
                        matches.set(key, {panel, section, item, priority});
                    }
                }
            }
        }

        return [...matches.values()];
    }

    processQuickSearch(text) {
        text = text.trim();

        this.quickSearchText = text;

        this.$el.find(".no-data").addClass("hidden");
        this.$el.find(".admin-content-group, .admin-content-section, .admin-content-row, .admin-history")
            .toggleClass("hidden", !!text);

        this.$el.find("details").each((index, element) => {
            if (text && !element.hasAttribute("data-search-open")) {
                element.setAttribute("data-search-open", String(element.open));
            } else if (!text && element.hasAttribute("data-search-open")) {
                element.open = element.getAttribute("data-search-open") === "true";
                element.removeAttribute("data-search-open");
            }
        });

        if (!text) {
            return;
        }

        const matches = this.getSearchMatches(text);

        for (const {panel, section, item} of matches) {
            this.$el.find(`.admin-content-group[data-index="${panel.index}"]`)
                .removeClass("hidden").prop("open", true);
            this.$el.find(`.admin-content-section[data-index="${section.index}"]`).removeClass("hidden");
            this.$el.find(`.admin-content-row[data-index="${item.index}"]`).removeClass("hidden")
                .closest(".admin-history").removeClass("hidden").prop("open", true);
        }

        this.$el.find(".no-data").toggleClass("hidden", matches.length > 0);
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
     * Resolve metadata URL placeholders using the authenticated user's app parameters.
     * Return null when a required parameter is unavailable.
     *
     * @param {string} url
     * @returns {string|null}
     */
    resolveItemUrl(url) {
        let missingParameter = false;
        const resolvedUrl = url.replace(/\{\{(\w+)\}\}/g, (match, name) => {
            const value = this.getHelper().getAppParam(name);

            if (value === null || value === undefined || value === "") {
                missingParameter = true;

                return match;
            }

            return String(value);
        });

        return missingParameter ? null : resolvedUrl;
    }

    /**
     * Extract entity type from admin-for-user item URLs.
     *
     * Supported shapes:
     *   #Configurations/ChatwootInboxIntegration  → ChatwootInboxIntegration
     *   #CustomFieldGroup                       → CustomFieldGroup
     *   #ChatwootAccountUserMembership/list/...  → ChatwootAccountUserMembership
     *   #KnowledgeBaseCategory/list/...         → KnowledgeBaseCategory
     *
     * Hash-only links that are not entity scopes (e.g. #Import) return null
     * so the ACL filter keeps them visible via the fallback.
     *
     * @param {string} url
     * @returns {string|null}
     */
    getEntityTypeFromUrl(url) {
        if (!url) {
            return null;
        }

        const configurationsMatch = url.match(/^#Configurations\/([A-Za-z][A-Za-z0-9_]*)$/);

        if (configurationsMatch) {
            return configurationsMatch[1];
        }

        // Entity hashes may include list actions and primary filters.
        const plainMatch = url.match(/^#([A-Z][A-Za-z0-9_]*)(?:[/?]|$)/);

        if (plainMatch) {
            const entityType = plainMatch[1];

            // Only treat as entity when the scope actually exists — keeps
            // non-entity admin links (if any start with uppercase) safe.
            if (this.getMetadata().get(['scopes', entityType])) {
                return entityType;
            }
        }

        return null;
    }
}

export default AdminForUserIndexView;

