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

import type Model from 'model';
import type {AdvancedFilter} from 'search-manager';

export interface SelectRelatedFilters {
    /**
     * Advanced filters. A field name as a key.
     */
    advanced?: Record<string, AdvancedFilter>;
    /**
     * Bool filters.
     */
    bool?: string[];
    /**
     * A primary filter.
     */
    primary?: string;
    /**
     * A field to order by.
     */
    orderBy?: string;
    /**
     * An order direction.
     */
    order?: 'asc' | 'desc';
}

/**
 * Select related records. Prepares filters for selecting records to relate.
 */
export interface SelectRelatedHandler<M extends Model = Model> {

    /**
     * Filters.
     *
     * @param model A parent model.
     * @return {Promise<SelectRelatedFilters>} Filters.
     */
    getFilters(model: M): Promise<SelectRelatedFilters>;
}

/**
 * Prepares attributes for a related record that is being created.
 *
 * @param model A parent model.
 */
export interface CreateRelatedHandler<M extends Model = Model> {

    /**
     * Get attributes for a new record.
     *
     * @param model A model.
     * @param link A link name.
     */
    getAttributes(model: M, link: string): Promise<Record<string, unknown>>;
}

/**
 * Prepares attributes to set to the model when selecting and clearing a related record.
 *.
 * @template M A related model type.
 * @template P A parent model type.
 */
export interface SelectFieldHandler<M extends Model = Model> {

    /**
     * Get attributes to set to the model after selecting the related model.
     *
     * @param model A related model.
     * @return {Promise<Record<string, unknown>>} Attributes.
     */
    getAttributes: (model: M) => Promise<Record<string, unknown>>;

    /**
     * Get attributes to set to the model after clearing a related model. Usually, it's null values.
     *
     * @return {Promise<Record<string, unknown>>} Attributes.
     */
    getClearAttributes: () => Promise<Record<string, unknown>>;
}
