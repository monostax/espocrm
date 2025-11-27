/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2025 EspoCRM, Inc.
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

import VarcharFieldView from 'views/fields/varchar';

class NameWithIconFieldView extends VarcharFieldView {
    
    listTemplate = 'global:activities/fields/name-with-icon/list'
    listLinkTemplate = 'global:activities/fields/name-with-icon/list-link'
    
    data() {
        const data = super.data();
        
        return {
            ...data,
            iconClass: this.getIconClass(),
            iconStyle: this.getIconStyle(),
        };
    }
    
    getEntityType() {
        // In MultiCollection models, entityType or name property contains the entity type
        // NOT model.get('name') which is the name field value
        return this.model.entityType || this.model.name || 'Activities';
    }
    
    getIconClass() {
        const entityType = this.getEntityType();
        return this.getMetadata().get(['clientDefs', entityType, 'iconClass']) || 'fas fa-circle';
    }
    
    getIconStyle() {
        const entityType = this.getEntityType();
        const color = this.getMetadata().get(['clientDefs', entityType, 'color']);
        
        if (color) {
            return `color: ${color};`;
        }
        
        return '';
    }
}

export default NameWithIconFieldView;

