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

import Controller from "controller";
import AdminForUserIndexView from "global:views/admin-for-user/index";

class AdminForUserController extends Controller {
    /**
     * Check if user has access to the AdminForUser panel.
     * All authenticated users have access, but items are filtered by role.
     */
    checkAccessGlobal() {
        // Access is granted to all authenticated users
        // The router only loads controllers for authenticated users
        return true;
    }

    /**
     * Main index action - displays the AdminForUser panel
     */
    // noinspection JSUnusedGlobalSymbols
    actionIndex(options) {
        let isReturn = options.isReturn;
        const key = "index";

        if (this.getRouter().backProcessed) {
            isReturn = true;
        }

        if (!isReturn && this.getStoredMainView(key)) {
            this.clearStoredMainView(key);
        }

        const view = new AdminForUserIndexView();

        this.main(
            view,
            null,
            (view) => {
                view.render();
            },
            {
                useStored: isReturn,
                key: key,
            },
        );
    }

    /**
     * Handle page actions for specific entities.
     * Routes like #Configurations/ChatwootInboxIntegration will dispatch to the entity's list view.
     */
    // noinspection JSUnusedGlobalSymbols
    async actionPage(options) {
        const page = options.page;

        if (options.options) {
            options = {
                ...Espo.Utils.parseUrlOptionsParam(options.options),
                ...options,
            };

            delete options.options;
        }

        if (!page) {
            throw new Error();
        }

        // Dynamically build entity map from metadata
        const entityType = this.getEntityTypeFromPage(page);

        if (entityType) {
            // Dispatch to the entity's list view
            this.getRouter().dispatch(entityType, "list", {
                fromAdminForUser: true,
            });
            return;
        }

        // If no matching entity, throw not found
        throw new Espo.Exceptions.NotFound();
    }

    /**
     * Get entity type from page name by scanning adminForUserPanel metadata.
     * @param {string} page - The page name from the URL
     * @returns {string|null} - The entity type or null if not found
     */
    getEntityTypeFromPage(page) {
        const panels = this.getMetadata().get("app.adminForUserPanel") || {};

        for (const panelName in panels) {
            const panel = panels[panelName];
            if (panel.itemList) {
                for (const item of panel.itemList) {
                    // Extract entity type from URL like "#Configurations/ChatwootInboxIntegration"
                    if (item.url) {
                        const urlMatch = item.url.match(
                            /^#Configurations\/(.+)$/,
                        );
                        if (urlMatch && urlMatch[1] === page) {
                            // The page name IS the entity type
                            return page;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get the panel definitions from metadata, filtered by ACL.
     * @returns {Object}
     */
    getPanelDefs() {
        const metadata = this.getMetadata();
        const panelData = metadata.get("app.adminForUserPanel") || {};

        // Filter items by ACL - only show entities the user has access to
        const filteredPanelData = {};

        for (const [panelName, panelDefs] of Object.entries(panelData)) {
            if (panelDefs.itemList) {
                const filteredItems = panelDefs.itemList.filter((item) => {
                    // Extract entity type from URL
                    const entityType = this.getEntityTypeFromUrl(item.url);

                    if (entityType) {
                        // Use ACL to check if user has read access
                        // This automatically handles roles, teams, and all permission logic
                        return this.getAcl().check(entityType, "read");
                    }

                    // If no entity type found, show the item (fallback)
                    return true;
                });

                if (filteredItems.length > 0) {
                    filteredPanelData[panelName] = {
                        ...panelDefs,
                        itemList: filteredItems,
                    };
                }
            }
        }

        return filteredPanelData;
    }
}

export default AdminForUserController;

