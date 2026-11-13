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

namespace Espo\Core\Utils\System;

use Espo\Core\ORM\EntityManagerProxy;
use Espo\Entities\SystemState as State;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\UpdateBuilder;

/**
 * @since 10.1.0
 */
class SystemState
{
    public function __construct(
        private EntityManagerProxy $entityManager,
    ) {}

    public function getVersionNumber(): int
    {
        $entity = $this->entityManager
            ->getRDBRepositoryByClass(State::class)
            ->getById(State::ID_VALUE);

        return $entity?->getVersionNumber() ?? 0;
    }

    public function bumpVersionNumber(): void
    {
        $query = UpdateBuilder::create()
            ->in(State::ENTITY_TYPE)
            ->set([
                State::FIELD_VERSION_NUMBER => Expr::add(Expr::column(State::FIELD_VERSION_NUMBER), 1),
            ])
            ->where([
                Attribute::ID => State::ID_VALUE,
            ])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($query);
    }
}
