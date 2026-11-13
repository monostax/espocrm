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

namespace Espo\Core\Utils\User;

use Espo\Entities\User;
use Espo\Entities\UserState;
use Espo\ORM\EntityManager;
use Espo\ORM\Name\Attribute;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\UpdateBuilder;

/**
 * @since 10.1.0
 * @internal
 */
class UserStateProvider
{
    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function getEmailFiltersVersionNumber(string $userId): int
    {
        $userState = $this->entityManager
            ->getRDBRepositoryByClass(UserState::class)
            ->where([UserState::ATTR_USER_ID => $userId])
            ->findOne();

        return $userState?->getEmailFiltersVersionNumber() ?? 0;
    }

    public function bumpEmailFiltersVersionNumber(string $userId): void
    {
        $this->prepare($userId);

        $column = UserState::FIELD_EMAIL_FILTERS_VERSION_NUMBER;

        $query = UpdateBuilder::create()
            ->in(UserState::ENTITY_TYPE)
            ->set([
                $column => Expr::add(Expr::column($column), 1),
            ])
            ->where([
                UserState::ATTR_USER_ID => $userId,
            ])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($query);
    }

    private function prepare(string $userId): void
    {
        $user = $this->entityManager->getRDBRepositoryByClass(User::class)->getById($userId);

        if (!$user) {
            return;
        }

        $userState = $this->entityManager
            ->getRDBRepositoryByClass(UserState::class)
            ->select(Attribute::ID)
            ->where([UserState::ATTR_USER_ID => $user->getId()])
            ->findOne();

        if ($userState) {
            return;
        }

        $userState = $this->entityManager->getRDBRepositoryByClass(UserState::class)->getNew();

        $userState->set(UserState::ATTR_USER_ID, $user->getId());

        $this->entityManager
            ->getMapper()
            ->insertOnDuplicateUpdate($userState, [UserState::ATTR_USER_ID]);

        $userState->setAsFetched();
    }
}
