<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * Extends core Import to accept customFields.<valueKey> virtual columns
 * and merge them into the host jsonObject bag.
 ************************************************************************/

namespace Espo\Modules\Global\Tools\Import;

use Espo\Core\Acl\SystemRestriction;
use Espo\Core\Acl\Table;
use Espo\Core\AclManager;
use Espo\Core\Currency\ConfigDataProvider as CurrencyConfig;
use Espo\Core\FieldValidation\FieldValidationManager;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\PhoneNumber\Sanitizer as PhoneNumberSanitizer;
use Espo\Core\Record\ServiceContainer as RecordServiceContainer;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\CustomField\ImportValueWriter;
use Espo\ORM\EntityManager;
use Espo\Tools\Import\Import as BaseImport;
use Espo\Tools\Import\Params;
use LogicException;
use stdClass;

class Import extends BaseImport
{
    public function __construct(
        AclManager $aclManager,
        EntityManager $entityManager,
        Metadata $metadata,
        User $user,
        FileStorageManager $fileStorageManager,
        RecordServiceContainer $recordServiceContainer,
        JobSchedulerFactory $jobSchedulerFactory,
        Log $log,
        FieldValidationManager $fieldValidationManager,
        PhoneNumberSanitizer $phoneNumberSanitizer,
        InjectableFactory $injectableFactory,
        CurrencyConfig $currencyConfig,
        SystemRestriction $systemRestriction,
        private ImportValueWriter $customFieldImportWriter,
    ) {
        parent::__construct(
            $aclManager,
            $entityManager,
            $metadata,
            $user,
            $fileStorageManager,
            $recordServiceContainer,
            $jobSchedulerFactory,
            $log,
            $fieldValidationManager,
            $phoneNumberSanitizer,
            $injectableFactory,
            $currencyConfig,
            $systemRestriction,
        );
    }

    /**
     * @param string[] $updateByAttributeList
     */
    protected function processRowItem(
        CoreEntity $entity,
        string $attribute,
        string $value,
        stdClass $valueMap,
        array $updateByAttributeList,
    ): void {
        $entityType = $entity->getEntityType();

        if ($this->customFieldImportWriter->supports($entityType, $attribute)) {
            $action = $this->params->getAction() ?? Params::ACTION_CREATE;

            if (
                in_array($action, [Params::ACTION_CREATE_AND_UPDATE, Params::ACTION_UPDATE], true) &&
                in_array($attribute, $updateByAttributeList, true) &&
                !$entity->isNew()
            ) {
                return;
            }

            if (
                $this->customFieldImportWriter->isLeafAttribute($attribute) ||
                $this->customFieldImportWriter->isBagAttribute($attribute)
            ) {
                $this->customFieldImportWriter->apply(
                    $entity,
                    $attribute,
                    $value,
                    $this->params,
                );

                return;
            }
        }

        parent::processRowItem($entity, $attribute, $value, $valueMap, $updateByAttributeList);
    }

    /**
     * @param string[] $attributeList
     */
    protected function applyAcl(array &$attributeList): void
    {
        parent::applyAcl($attributeList);

        $entityType = $this->entityType ?? throw new LogicException();

        $forbiddenAttributeList = $this->aclManager
            ->getScopeForbiddenAttributeList($this->user, $entityType, Table::ACTION_EDIT);

        $this->customFieldImportWriter->applyAclFilter($attributeList, $forbiddenAttributeList);
    }
}
