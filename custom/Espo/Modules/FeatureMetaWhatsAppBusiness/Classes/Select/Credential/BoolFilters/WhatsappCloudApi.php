<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Classes\Select\Credential\BoolFilters;

use Espo\Core\Select\Bool\Filter;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder as QueryBuilder;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\Part\Condition as Cond;

/**
 * Bool filter to show only Credential records of type whatsappCloudApi.
 * Used to pre-filter the credential selector in WhatsAppBusinessAccount views.
 *
 * @noinspection PhpUnused
 */
class WhatsappCloudApi implements Filter
{
    private const CREDENTIAL_TYPE_CODE = 'whatsappCloudApi';

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function apply(QueryBuilder $queryBuilder, OrGroupBuilder $orGroupBuilder): void
    {
        $credentialType = $this->entityManager
            ->getRDBRepository('CredentialType')
            ->where(['code' => self::CREDENTIAL_TYPE_CODE])
            ->findOne();

        if (!$credentialType) {
            // If the credential type doesn't exist, match nothing.
            $orGroupBuilder->add(
                Cond::equal(Cond::column('id'), null)
            );

            return;
        }

        $orGroupBuilder->add(
            Cond::and(
                Cond::equal(
                    Cond::column('credentialTypeId'),
                    $credentialType->getId()
                ),
                Cond::equal(
                    Cond::column('isActive'),
                    true
                )
            )
        );
    }
}
