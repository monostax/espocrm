<?php
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

namespace Espo\Modules\Clinica\Core\Field\Weight;

use Espo\ORM\Defs;
use Espo\ORM\Entity;
use Espo\ORM\Value\AttributeExtractor;

use Espo\Modules\Clinica\Core\Field\Weight;

use stdClass;
use InvalidArgumentException;

/**
 * @implements AttributeExtractor<Weight>
 */
class WeightAttributeExtractor implements AttributeExtractor
{
    public function __construct(
        private string $entityType,
        private Defs $ormDefs
    ) {}

    public function extract(object $value, string $field): stdClass
    {
        if (!$value instanceof Weight) {
            throw new InvalidArgumentException();
        }

        $useString = $this->ormDefs
            ->getEntity($this->entityType)
            ->getField($field)
            ->getType() === Entity::VARCHAR;

        $amount = $useString ?
            $value->getValueAsString() :
            $value->getValue();

        return (object) [
            $field => $amount,
            $field . 'Unit' => $value->getUnit(),
        ];
    }

    public function extractFromNull(string $field): stdClass
    {
        return (object) [
            $field => null,
            $field . 'Unit' => null,
        ];
    }
}

