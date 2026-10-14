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

use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Mirrors address-bearing ContactChannelIdentity rows onto the
 * Contact's multi-value fields:
 *
 *   - whatsapp / sms  -> phoneNumber   (sourceId is an E.164 phone)
 *   - email           -> emailAddress  (sourceId is a lowercased email)
 *
 * A WhatsApp identity's sourceId is a verified phone and an email
 * identity's sourceId is a working address, so they belong on the
 * Contact's native fields too — that's what click-to-call, mailing,
 * duplicate checking, campaign filters and the reconciler's
 * phone/email match steps read.
 *
 * Strictly additive: never removes or reorders existing values, never
 * changes the primary. When the contact has no value at all, the
 * appended one becomes primary (populating Contact.phoneNumber /
 * Contact.emailAddress).
 *
 * Dedupe is normalization-aware: phones compare E.164-normalized
 * (a formatted "+55 (34) 99696-4707" is not duplicated), emails
 * compare case-insensitively.
 *
 * Callers: Hooks\ContactChannelIdentity\SyncFieldsToContact (live) and
 * Rebuild\BackfillContactFieldsFromIdentities (existing data). Tenant
 * and ACL enforcement live in the callers — this class only mutates.
 */
class ContactFieldSync
{
    /** Channel types whose sourceId is an E.164 phone number. */
    public const PHONE_CHANNELS = ['whatsapp', 'sms'];

    /** Channel types whose sourceId is an email address. */
    public const EMAIL_CHANNELS = ['email'];

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
    ) {}

    /**
     * The E.164 phone carried by a phone-keyed identity, or null when
     * the identity is not phone-keyed / not routable (e.g. an @lid
     * placeholder that has not rotated to a real number yet).
     */
    public function phoneFromIdentity(Entity $identity): ?string
    {
        if (!in_array($identity->get('channelType'), self::PHONE_CHANNELS, true)) {
            return null;
        }

        return PhoneNormalizer::normalize((string) $identity->get('sourceId'));
    }

    /**
     * The email address carried by an email identity, or null when the
     * identity is not email-keyed or the value is not a valid address.
     */
    public function emailFromIdentity(Entity $identity): ?string
    {
        if (!in_array($identity->get('channelType'), self::EMAIL_CHANNELS, true)) {
            return null;
        }

        $email = strtolower(trim((string) $identity->get('sourceId')));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    /**
     * Append the number to the contact's phoneNumber field when not
     * already present, or promote it to primary when requested.
     *
     * @return bool Whether the contact's phone numbers were modified.
     */
    public function appendPhone(Entity $contact, string $e164, bool $makePrimary = false): bool
    {
        /** @var \Espo\Repositories\PhoneNumber $repository */
        $repository = $this->entityManager->getRepository('PhoneNumber');

        /** @var \stdClass[] $dataList */
        $dataList = $repository->getPhoneNumberData($contact);

        foreach ($dataList as $index => $item) {
            $existing = (string) ($item->phoneNumber ?? '');

            if ($existing === $e164 || PhoneNormalizer::normalize($existing) === $e164) {
                if (!$makePrimary || !empty($item->primary)) {
                    return false;
                }

                foreach ($dataList as $idx => $entry) {
                    $entry->primary = $idx === $index;
                }

                $contact->set('phoneNumberData', $dataList);
                $this->saveContact($contact);

                return true;
            }
        }

        $defaultType = $this->metadata->get([
            'entityDefs', 'Contact', 'fields', 'phoneNumber', 'defaultType',
        ]) ?? 'Mobile';

        $primary = $makePrimary || count($dataList) === 0;

        if ($primary) {
            foreach ($dataList as $entry) {
                $entry->primary = false;
            }
        }

        $dataList[] = (object) [
            'phoneNumber' => $e164,
            'type' => $defaultType,
            'primary' => $primary,
            'optOut' => false,
            'invalid' => false,
        ];

        $contact->set('phoneNumberData', $dataList);

        $this->saveContact($contact);

        return true;
    }

    /**
     * Append the address to the contact's emailAddress field when not
     * already present (case-insensitive), or promote it to primary when requested.
     *
     * @return bool Whether the contact's email addresses were modified.
     */
    public function appendEmail(Entity $contact, string $email, bool $makePrimary = false): bool
    {
        /** @var \Espo\Repositories\EmailAddress $repository */
        $repository = $this->entityManager->getRepository('EmailAddress');

        /** @var \stdClass[] $dataList */
        $dataList = $repository->getEmailAddressData($contact);

        foreach ($dataList as $index => $item) {
            $existing = strtolower(trim((string) ($item->emailAddress ?? '')));

            if ($existing === $email) {
                if (!$makePrimary || !empty($item->primary)) {
                    return false;
                }

                foreach ($dataList as $idx => $entry) {
                    $entry->primary = $idx === $index;
                }

                $contact->set('emailAddressData', $dataList);
                $this->saveContact($contact);

                return true;
            }
        }

        $primary = $makePrimary || count($dataList) === 0;

        if ($primary) {
            foreach ($dataList as $entry) {
                $entry->primary = false;
            }
        }

        $dataList[] = (object) [
            'emailAddress' => $email,
            'primary' => $primary,
            'optOut' => false,
            'invalid' => false,
        ];

        $contact->set('emailAddressData', $dataList);

        $this->saveContact($contact);

        return true;
    }

    /**
     * Saves with ['silent' => true] (recursion guard of SyncToChatwoot
     * / SyncChatwootContactMerge) and 'skipChannelIdentitiesSave'
     * (guard of the ChannelIdentities hook) — the write cannot loop
     * back into identity writes.
     */
    private function saveContact(Entity $contact): void
    {
        $this->entityManager->saveEntity($contact, [
            'silent' => true,
            'skipChannelIdentitiesSave' => true,
        ]);
    }
}
