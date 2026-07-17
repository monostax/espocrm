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

import type {SelectRelatedFilters, SelectRelatedHandler as SelectRelatedHandlerInterface} from 'contracts/relation';
import type ViewHelper from 'view-helper';
import type Model from 'model';

/**
 * Prepares filters for selecting records to relate.
 * Use the interface directly rather than extending this class.
 */
abstract class SelectRelatedHandler<M extends Model = Model> implements SelectRelatedHandlerInterface<M> {

    /**
     * @internal
     */
    protected readonly viewHelper: ViewHelper

    /**
     * @internal
     */
    constructor(viewHelper: ViewHelper) {
        this.viewHelper = viewHelper;
    }

    /**
     * @inheritDoc
     *
     * @param model A model.
     * @return {Promise<SelectRelatedFilters>} Filters.
     */
    async getFilters(model: M): Promise<SelectRelatedFilters> {
        // noinspection BadExpressionStatementJS
        model;

        return {};
    }
}

export default SelectRelatedHandler;
