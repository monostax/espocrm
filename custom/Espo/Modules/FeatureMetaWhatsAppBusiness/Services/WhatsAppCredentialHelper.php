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

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Shared credential validation logic for WhatsApp Business virtual entities.
 *
 * Used by WhatsAppBusinessAccount, WhatsAppBusinessAccountPhoneNumber,
 * and WhatsAppBusinessAccountMessageTemplate services to validate and
 * retrieve credentials of type `whatsappCloudApi`.
 */
class WhatsAppCredentialHelper
{
    private const CREDENTIAL_TYPE_CODE = 'whatsappCloudApi';

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
    ) {}

    /**
     * Validate that the current user has read access to a credential
     * and that it is of type whatsappCloudApi.
     *
     * @param string $credentialId
     * @return Entity The credential entity
     * @throws NotFound
     * @throws Forbidden
     * @throws BadRequest
     */
    public function validateCredentialAccess(string $credentialId): Entity
    {
        $credential = $this->entityManager->getEntityById('Credential', $credentialId);

        if (!$credential) {
            throw new NotFound("Credential not found.");
        }

        if (!$this->acl->check($credential, 'read')) {
            throw new Forbidden("You don't have access to use this credential.");
        }

        if (!$credential->get('isActive')) {
            throw new BadRequest("Credential is not active.");
        }

        // Verify the credential type is whatsappCloudApi.
        $credentialTypeId = $credential->get('credentialTypeId');

        if ($credentialTypeId) {
            $credentialType = $this->entityManager->getEntityById('CredentialType', $credentialTypeId);

            if (!$credentialType || $credentialType->get('code') !== self::CREDENTIAL_TYPE_CODE) {
                throw new BadRequest(
                    "Invalid credential type. Expected " . self::CREDENTIAL_TYPE_CODE . "."
                );
            }
        } else {
            throw new BadRequest("Credential has no type assigned.");
        }

        return $credential;
    }

    /**
     * Get all active whatsappCloudApi credentials accessible to the current user.
     *
     * @return Entity[]
     */
    public function getAccessibleCredentials(): array
    {
        $credentialTypeEntity = $this->entityManager
            ->getRDBRepository('CredentialType')
            ->where(['code' => self::CREDENTIAL_TYPE_CODE])
            ->findOne();

        if (!$credentialTypeEntity) {
            return [];
        }

        $credentials = $this->entityManager
            ->getRDBRepository('Credential')
            ->where([
                'credentialTypeId' => $credentialTypeEntity->getId(),
                'isActive' => true,
            ])
            ->find();

        $accessible = [];

        foreach ($credentials as $credential) {
            if ($this->acl->check($credential, 'read')) {
                $accessible[] = $credential;
            }
        }

        return $accessible;
    }
}
