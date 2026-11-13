<?php
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

namespace Espo\Tools\LeadCapture;

use Espo\Core\Binding\Attributes\Qualify;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Theme\Direction;
use Espo\Entities\LeadCapture;

class ThemeDirectionDetector
{
    private const array RTL_LANGUAGE_CODE_LIST = [
        'ar',
        'fa',
        'he',
        'ur',
    ];

    public function __construct(
        #[Qualify(Language::QUALIFIER_DEFAULT)]
        private Language $language,
    ) {}

    public function detect(LeadCapture $leadCapture): Direction
    {
        $language = $leadCapture->getFormLanguage() ?? $this->language->getLanguage();

        $languageCode = strtolower(substr($language, 0, 2));

        return in_array($languageCode, self::RTL_LANGUAGE_CODE_LIST, true) ?
            Direction::Rtl :
            Direction::Ltr;
    }
}
