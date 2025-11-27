define("global:views/fields/link-multiple-with-icons", ["exports", "views/fields/link-multiple"], function (_exports, _linkMultiple) {
  "use strict";

  Object.defineProperty(_exports, "__esModule", {
    value: true
  });
  _exports.default = void 0;
  _linkMultiple = _interopRequireDefault(_linkMultiple);
  function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }
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

  class LinkMultipleWithIconsFieldView extends _linkMultiple.default {
    /**
     * Get icon HTML for an entity
     * @param {string} id Entity ID
     * @returns {string} Icon HTML
     */
    getIconHtml(id) {
      const entityType = this.foreignScope;

      // For User entities, show avatar
      if (entityType === 'User') {
        const size = this.isListMode() ? 16 : 18;
        return this.getHelper().getAvatarHtml(id, 'small', size, 'avatar-link');
      }

      // For other entities, show entity icon
      const iconClass = this.getMetadata().get(['clientDefs', entityType, 'iconClass']);
      if (!iconClass) {
        return '';
      }
      const color = this.getMetadata().get(['clientDefs', entityType, 'color']);
      const style = color ? `color: ${color};` : '';
      return `<span class="${iconClass}" style="${style}"></span>`;
    }

    /**
     * Get detail link HTML with icon in both detail and list modes
     * @param {string} id Entity ID
     * @param {string} [name] Entity name
     * @returns {string} HTML string
     */
    getDetailLinkHtml(id, name) {
      name = name || this.nameHash[id] || id;
      if (!name && id) {
        name = this.translate(this.foreignScope, 'scopeNames');
      }

      // Show icons in both detail AND list mode
      const iconHtml = this.getIconHtml(id);
      const $a = $('<a>').attr('href', this.getUrl(id)).attr('data-id', id).text(name);
      if (this.mode === this.MODE_LIST) {
        $a.addClass('text-default');
      } else if (this.linkClass) {
        $a.addClass(this.linkClass);
      }

      // Wrap in flex container for proper alignment
      const $wrapper = $('<div>').css({
        'display': 'flex',
        'align-items': 'center',
        'gap': '8px'
      });
      if (iconHtml) {
        $wrapper.append(iconHtml);
      }
      $wrapper.append($a);
      return $wrapper.get(0).outerHTML;
    }
  }
  var _default = _exports.default = LinkMultipleWithIconsFieldView;
});
//# sourceMappingURL=link-multiple-with-icons.js.map ;