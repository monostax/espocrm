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

namespace Espo\Modules\Chatwoot\Tools;

use Espo\ORM\EntityManager;

/**
 * A Meta (Cloud API / Coexistence) phone number can back only one inbox on the
 * whole platform: Chatwoot validates `channel_whatsapp.phone_number` as unique
 * across accounts, and Meta delivers a number's webhooks to a single inbox.
 *
 * The lookup is deliberately cross-tenant (raw repository, no ACL). Callers
 * may reveal only whether a number is taken, never which workspace holds it.
 */
class MetaPhoneNumberOwner
{
    public const CHANNEL_TYPES = ['whatsappCloudApi', 'whatsappCoexistence'];
    public const TAKEN_CODE = 'PHONE_ALREADY_CONNECTED';

    public function __construct(private EntityManager $entityManager) {}

    /**
     * @param string[] $phoneNumberIds Meta phone_number_id values.
     * @return array<string, string> phoneNumberId => CRM ChatwootAccount id of the live integration.
     */
    public function owners(array $phoneNumberIds): array
    {
        $phoneNumberIds = array_values(array_unique(array_filter(
            $phoneNumberIds,
            static fn ($id): bool => is_string($id) && $id !== ''
        )));

        if ($phoneNumberIds === []) {
            return [];
        }

        $integrations = $this->entityManager
            ->getRDBRepository('ChatwootInboxIntegration')
            ->select(['id', 'phoneNumberId', 'chatwootAccountId'])
            ->where([
                'phoneNumberId' => $phoneNumberIds,
                'channelType' => self::CHANNEL_TYPES,
            ])
            ->find();

        $owners = [];

        foreach ($integrations as $integration) {
            $owners[(string) $integration->get('phoneNumberId')] ??= (string) $integration->get('chatwootAccountId');
        }

        return $owners;
    }
}
