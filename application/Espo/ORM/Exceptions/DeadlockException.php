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

namespace Espo\ORM\Exceptions;

use PDOException;
use RuntimeException;
use Throwable;

/**
 * @since 10.1.0
 */
class DeadlockException extends RuntimeException
{
    /**
     * Whether the database aborted the whole transaction to break a lock cycle, so the entire
     * transaction may run again: PostgreSQL 40P01, MySQL/MariaDB 1213, and MariaDB 1020 (a record
     * changed since it was read, under snapshot isolation). Raw PDO statements report these as a
     * plain PDOException; the ORM executor wraps them in this exception.
     */
    public static function isDeadlock(Throwable $e): bool
    {
        if ($e instanceof self) {
            return true;
        }

        if (!$e instanceof PDOException || !$e->errorInfo) {
            return false;
        }

        $state = $e->errorInfo[0] ?? null;
        $code = (int) ($e->errorInfo[1] ?? 0);

        return $state === '40P01' || ($state === 'HY000' && $code === 1020) || ($state === '40001' && $code === 1213);
    }
}
