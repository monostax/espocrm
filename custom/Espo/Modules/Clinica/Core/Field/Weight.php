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

namespace Espo\Modules\Clinica\Core\Field;

use RuntimeException;
use InvalidArgumentException;

/**
 * A weight value object. Immutable.
 */
class Weight
{
    /** @var numeric-string */
    private string $value;
    private string $unit;

    /**
     * @param numeric-string|float|int $value A value.
     * @param string $unit A weight unit.
     * @throws RuntimeException
     */
    public function __construct($value, string $unit)
    {
        if (!is_string($value) && !is_float($value) && !is_int($value)) {
            throw new InvalidArgumentException();
        }

        if (strlen($unit) === 0 || strlen($unit) > 10) {
            throw new RuntimeException("Bad weight unit.");
        }

        if (is_float($value) || is_int($value)) {
            $value = (string) $value;
        }

        $this->value = $value;
        $this->unit = $unit;
    }

    /**
     * Get a value as string.
     *
     * @return numeric-string
     */
    public function getValueAsString(): string
    {
        return $this->value;
    }

    /**
     * Get a value.
     */
    public function getValue(): float
    {
        return (float) $this->value;
    }

    /**
     * Get a weight unit.
     */
    public function getUnit(): string
    {
        return $this->unit;
    }

    /**
     * Create from a value and unit.
     *
     * @param numeric-string|float|int $value A value.
     * @param string $unit A weight unit.
     * @throws RuntimeException
     */
    public static function create($value, string $unit): self
    {
        return new self($value, $unit);
    }
}

