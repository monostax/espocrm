<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Config;

/**
 * Service for synchronizing EspoCRM Contacts with Chatwoot Contacts.
 */
class ContactSyncService
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log,
        private Config $config
    ) {}

    /**
     * Normalize phone number to E.164 format (basic implementation).
     *
     * @param string $phone
     * @return string
     */
    private function normalizePhoneNumber(string $phone): string
    {
        // Remove all non-digit characters
        $normalized = preg_replace('/[^0-9+]/', '', $phone);
        
        // Ensure it starts with +
        if (!str_starts_with($normalized, '+')) {
            // Default country code from config or BR
            $defaultCountryCode = $this->config->get('defaultPhoneCountryCode', '55');
            $normalized = '+' . $defaultCountryCode . $normalized;
        }
        
        return $normalized;
    }

    /**
     * Get phone numbers from a Contact entity.
     *
     * @param Entity $contact
     * @return array<string> Array of phone numbers
     */
    private function getContactPhoneNumbers(Entity $contact): array
    {
        $phones = [];
        
        // Get primary phone
        $phoneNumber = $contact->get('phoneNumber');
        if ($phoneNumber) {
            $phones[] = $phoneNumber;
        }
        
        // Get additional phone numbers from phoneNumberData
        $phoneData = $contact->get('phoneNumberData');
        if (is_array($phoneData)) {
            foreach ($phoneData as $data) {
                if (isset($data->phoneNumber) && !in_array($data->phoneNumber, $phones)) {
                    $phones[] = $data->phoneNumber;
                }
            }
        }
        
        return $phones;
    }

    /**
     * Get the appropriate ChatwootAccount for a Contact.
     * 
     * For security purposes, only Contact's Team's chatwootAccount link is used.
     * This ensures contacts can only be synced to Chatwoot accounts that are
     * explicitly mapped to their team.
     *
     * @param Entity $contact
     * @return Entity|null ChatwootAccount entity (first team with mapping)
     */
    private function getChatwootAccountForContact(Entity $contact): ?Entity
    {
        // Get Contact's Teams
        $teams = $this->entityManager
            ->getRDBRepository('Contact')
            ->getRelation($contact, 'teams')
            ->find();
        
        if (count($teams) === 0) {
            $this->log->debug('Contact has no teams assigned: ' . $contact->getId());
            return null;
        }

        $teamIds = [];
        foreach ($teams as $team) {
            $teamIds[] = $team->getId();
        }

        // Find active ChatwootAccounts that have any of these teams assigned
        $chatwootAccounts = $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->distinct()
            ->join('teams')
            ->where([
                'status' => 'active',
                'chatwootAccountId!=' => null,
                'teams.id' => $teamIds
            ])
            ->order('createdAt', 'ASC')
            ->find();

        foreach ($chatwootAccounts as $account) {
            // Get the teams for this account to log which one matched
            $accountTeams = $this->entityManager
                ->getRDBRepository('ChatwootAccount')
                ->getRelation($account, 'teams')
                ->where(['id' => $teamIds])
                ->find();
            
            if (count($accountTeams) > 0) {
                $teamNames = [];
                foreach ($accountTeams as $team) {
                    $teamNames[] = $team->get('name');
                }
                
                $this->log->debug(
                    'Using ChatwootAccount: ' . $account->getId() . ' ' . $account->get('name') . 
                    ' (via Teams: ' . implode(', ', $teamNames) . ')'
                );
                return $account;
            }
        }

        $this->log->debug(
            'No ChatwootAccount found for Contact\'s Teams. ' .
            'Contact: ' . $contact->getId() . ', ' .
            'Teams: ' . implode(', ', array_map(fn($t) => $t->get('name'), iterator_to_array($teams)))
        );
        
        return null;
    }

    /**
     * Find existing mapping for contact and phone.
     *
     * @param string $contactId
     * @param string $phoneNumber
     * @param string $chatwootAccountId
     * @return Entity|null
     */
    private function findMapping(string $contactId, string $phoneNumber, string $chatwootAccountId): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository('ChatwootContact')
            ->where([
                'contactId' => $contactId,
                'phoneNumber' => $phoneNumber,
                'chatwootAccountId' => $chatwootAccountId
            ])
            ->findOne();
    }

    /**
     * Find mapping by Chatwoot contact ID.
     *
     * @param int $chatwootContactId
     * @param string $chatwootAccountId
     * @return Entity|null
     */
    public function findMappingByChatwootId(int $chatwootContactId, string $chatwootAccountId): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository('ChatwootContact')
            ->where([
                'chatwootContactId' => $chatwootContactId,
                'chatwootAccountId' => $chatwootAccountId
            ])
            ->findOne();
    }

    /**
     * Sync a Contact entity to Chatwoot.
     *
     * @param Entity $contact
     * @return void
     * @throws Error
     */
    public function syncContactToChatwoot(Entity $contact): void
    {
        // Get the appropriate Chatwoot account for this contact
        $chatwootAccount = $this->getChatwootAccountForContact($contact);
        
        if (!$chatwootAccount) {
            $this->log->warning('No ChatwootAccount found for syncing Contact: ' . $contact->getId());
            return;
        }

        $chatwootAccountId = $chatwootAccount->get('chatwootAccountId');
        if (!$chatwootAccountId) {
            $this->log->warning('ChatwootAccount has no chatwootAccountId: ' . $chatwootAccount->getId());
            return;
        }

        // Get platform details
        $platformId = $chatwootAccount->get('platformId');
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        
        if (!$platform) {
            $this->log->error('ChatwootPlatform not found: ' . $platformId);
            return;
        }

        $platformUrl = $platform->get('url');
        
        // Get account-specific API key
        $accountApiKey = $chatwootAccount->get('apiKey');
        
        if (!$accountApiKey) {
            $this->log->warning(
                'ChatwootAccount has no API key for contact sync: ' . $chatwootAccount->getId() . '. ' .
                'Please add an account-level API token from Chatwoot (Settings → Integrations → API) ' .
                'to the apiKey field of this ChatwootAccount record.'
            );
            return;
        }

        // Get phone numbers
        $phoneNumbers = $this->getContactPhoneNumbers($contact);
        
        if (empty($phoneNumbers)) {
            $this->log->info('Contact has no phone numbers to sync: ' . $contact->getId());
            return;
        }

        // Sync each phone number as a separate Chatwoot contact
        foreach ($phoneNumbers as $phone) {
            try {
                $normalizedPhone = $this->normalizePhoneNumber($phone);
                $this->syncPhoneNumber($contact, $normalizedPhone, $chatwootAccount, $platformUrl, $accountApiKey, $chatwootAccountId);
            } catch (\Exception $e) {
                $this->log->error('Failed to sync phone ' . $phone . ' for contact ' . $contact->getId() . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Sync a specific phone number for a contact.
     *
     * @param Entity $contact
     * @param string $phoneNumber
     * @param Entity $chatwootAccount
     * @param string $platformUrl
     * @param string $accountApiKey
     * @param int $chatwootAccountId
     * @return void
     * @throws Error
     */
    private function syncPhoneNumber(
        Entity $contact,
        string $phoneNumber,
        Entity $chatwootAccount,
        string $platformUrl,
        string $accountApiKey,
        int $chatwootAccountId
    ): void {
        // Check if mapping exists
        $mapping = $this->findMapping($contact->getId(), $phoneNumber, $chatwootAccount->getId());
        
        $contactData = $this->prepareContactData($contact, $phoneNumber);

        if ($mapping && $mapping->get('chatwootContactId')) {
            // UPDATE existing Chatwoot contact
            $chatwootContactId = $mapping->get('chatwootContactId');
            
            try {
                $this->apiClient->updateContact(
                    $platformUrl,
                    $accountApiKey,
                    $chatwootAccountId,
                    $chatwootContactId,
                    $contactData
                );
                
                $mapping->set('lastSyncedAt', date('Y-m-d H:i:s'));
                $mapping->set('syncStatus', 'synced');
                $mapping->set('syncError', null);
                
                $this->entityManager->saveEntity($mapping, ['silent' => true]);
                
                $this->log->info("Updated Chatwoot contact {$chatwootContactId} for Contact {$contact->getId()}");
            } catch (\Exception $e) {
                $mapping->set('syncStatus', 'error');
                $mapping->set('syncError', $e->getMessage());
                $this->entityManager->saveEntity($mapping, ['silent' => true]);
                throw $e;
            }
        } else {
            // CREATE new Chatwoot contact (or find existing if phone already exists)
            try {
                $chatwootContact = $this->apiClient->createContact(
                    $platformUrl,
                    $accountApiKey,
                    $chatwootAccountId,
                    $contactData
                );
                
                $this->log->debug('Chatwoot createContact response: ' . json_encode($chatwootContact));
                
                // Chatwoot may return contact in different structures
                $contactId = null;
                if (isset($chatwootContact['id'])) {
                    $contactId = $chatwootContact['id'];
                } elseif (isset($chatwootContact['payload']['contact']['id'])) {
                    $contactId = $chatwootContact['payload']['contact']['id'];
                } elseif (isset($chatwootContact['contact']['id'])) {
                    $contactId = $chatwootContact['contact']['id'];
                }
                
                if (!$contactId) {
                    throw new Error('Chatwoot API response missing contact ID. Response: ' . json_encode($chatwootContact));
                }
                
                // Create mapping record
                if (!$mapping) {
                    $mapping = $this->entityManager->createEntity('ChatwootContact', [
                        'contactId' => $contact->getId(),
                        'phoneNumber' => $phoneNumber,
                        'chatwootAccountId' => $chatwootAccount->getId(),
                        'chatwootContactId' => $contactId,
                        'lastSyncedAt' => date('Y-m-d H:i:s'),
                        'syncStatus' => 'synced'
                    ]);
                } else {
                    $mapping->set('chatwootContactId', $contactId);
                    $mapping->set('lastSyncedAt', date('Y-m-d H:i:s'));
                    $mapping->set('syncStatus', 'synced');
                    $mapping->set('syncError', null);
                    $this->entityManager->saveEntity($mapping, ['silent' => true]);
                }
                
                $this->log->info("Created Chatwoot contact {$contactId} for Contact {$contact->getId()}");
            } catch (\Exception $e) {
                $this->log->debug('Exception during contact creation: ' . $e->getMessage());
                
                // Check if error is due to duplicate phone number
                if (strpos($e->getMessage(), 'Phone number has already been taken') !== false ||
                    strpos($e->getMessage(), 'already been taken') !== false) {
                    $this->log->info("Phone number {$phoneNumber} already exists in Chatwoot, searching for existing contact...");
                    
                    // Try to find the existing contact by phone number
                    try {
                        $existingContact = $this->apiClient->searchContactByPhone(
                            $platformUrl,
                            $accountApiKey,
                            $chatwootAccountId,
                            $phoneNumber
                        );
                        
                        $this->log->debug('Search result: ' . json_encode($existingContact));
                        
                        if ($existingContact && isset($existingContact['id'])) {
                            // Found existing contact, create mapping
                            $this->log->info("Found existing Chatwoot contact {$existingContact['id']} for phone {$phoneNumber}, creating mapping");
                            
                            if (!$mapping) {
                                $mapping = $this->entityManager->createEntity('ChatwootContact', [
                                    'contactId' => $contact->getId(),
                                    'phoneNumber' => $phoneNumber,
                                    'chatwootAccountId' => $chatwootAccount->getId(),
                                    'chatwootContactId' => $existingContact['id'],
                                    'lastSyncedAt' => date('Y-m-d H:i:s'),
                                    'syncStatus' => 'synced'
                                ]);
                            } else {
                                $mapping->set('chatwootContactId', $existingContact['id']);
                                $mapping->set('lastSyncedAt', date('Y-m-d H:i:s'));
                                $mapping->set('syncStatus', 'synced');
                                $mapping->set('syncError', null);
                                $this->entityManager->saveEntity($mapping, ['silent' => true]);
                            }
                            
                            $this->log->info("Successfully linked existing Chatwoot contact to EspoCRM Contact {$contact->getId()}");
                            return; // Successfully handled duplicate
                        } else {
                            $this->log->warning("Could not find existing contact by phone {$phoneNumber} even though it was reported as duplicate");
                        }
                    } catch (\Exception $searchException) {
                        // Log the error - search failed (possibly due to service loading issues)
                        $this->log->warning("Failed to search for existing contact by phone {$phoneNumber}: " . $searchException->getMessage());
                        $this->log->info("Manual intervention required: Contact with phone {$phoneNumber} already exists in Chatwoot account {$chatwootAccountId}. Please link manually or update the contact in Chatwoot.");
                        
                        // Try to record as pending manual review
                        if (!$mapping) {
                            try {
                                $this->entityManager->createEntity('ChatwootContact', [
                                    'contactId' => $contact->getId(),
                                    'phoneNumber' => $phoneNumber,
                                    'chatwootAccountId' => $chatwootAccount->getId(),
                                    'syncStatus' => 'pending',
                                    'syncError' => 'Phone number already exists in Chatwoot. Manual linking required.'
                                ]);
                                $this->log->info("Created pending ChatwootContact mapping for manual review");
                            } catch (\Exception $createEx) {
                                $this->log->error("Could not create pending mapping: " . $createEx->getMessage());
                            }
                        }
                        return; // Don't throw - handled as best as possible
                    }
                }
                
                // If we couldn't handle the duplicate, record the error
                if ($mapping) {
                    $mapping->set('syncStatus', 'error');
                    $mapping->set('syncError', $e->getMessage());
                    $this->entityManager->saveEntity($mapping, ['silent' => true]);
                }
                throw $e;
            }
        }
    }

    /**
     * Prepare contact data for Chatwoot API.
     *
     * @param Entity $contact
     * @param string $phoneNumber
     * @return array<string, mixed>
     */
    private function prepareContactData(Entity $contact, string $phoneNumber): array
    {
        $name = trim(($contact->get('firstName') ?? '') . ' ' . ($contact->get('lastName') ?? ''));
        if (empty($name)) {
            $name = $contact->get('name') ?? 'Unknown';
        }

        $data = [
            'name' => $name,
            'phone_number' => $phoneNumber,
            'custom_attributes' => [
                'espocrm_contact_id' => $contact->getId()
            ]
        ];

        // Add email if available
        $email = $contact->get('emailAddress');
        if ($email) {
            $data['email'] = $email;
        }

        return $data;
    }

    /**
     * Find EspoCRM contact by phone or email.
     *
     * @param string|null $phoneNumber
     * @param string|null $email
     * @return Entity|null
     */
    public function findContactByPhoneOrEmail(?string $phoneNumber, ?string $email): ?Entity
    {
        if ($phoneNumber) {
            $normalized = $this->normalizePhoneNumber($phoneNumber);
            
            // Search by phone number
            $contact = $this->entityManager
                ->getRDBRepository('Contact')
                ->distinct()
                ->join('phoneNumbers')
                ->where([
                    'phoneNumbers.name*' => '%' . $normalized . '%'
                ])
                ->findOne();
            
            if ($contact) {
                return $contact;
            }
        }

        if ($email) {
            // Search by email
            $contact = $this->entityManager
                ->getRDBRepository('Contact')
                ->distinct()
                ->join('emailAddresses')
                ->where([
                    'emailAddresses.lower=' => strtolower($email)
                ])
                ->findOne();
            
            if ($contact) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * Parse first name from full name.
     *
     * @param string $fullName
     * @return string
     */
    private function parseFirstName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? '';
    }

    /**
     * Parse last name from full name.
     *
     * @param string $fullName
     * @return string
     */
    private function parseLastName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        if (count($parts) > 1) {
            array_shift($parts); // Remove first name
            return implode(' ', $parts);
        }
        return '';
    }

    /**
     * Handle incoming Chatwoot webhook for contact creation/update.
     *
     * @param array<string, mixed> $payload
     * @return void
     * @throws Error
     */
    public function handleChatwootContactWebhook(array $payload): void
    {
        if (!isset($payload['contact'])) {
            throw new Error('Webhook payload missing contact data.');
        }

        $contactData = $payload['contact'];
        $chatwootContactId = $contactData['id'] ?? null;
        $phoneNumber = $contactData['phone_number'] ?? null;
        $email = $contactData['email'] ?? null;
        $name = $contactData['name'] ?? '';
        $accountId = $payload['account']['id'] ?? null;

        if (!$chatwootContactId || !$accountId) {
            throw new Error('Webhook payload missing required fields.');
        }

        // Find the ChatwootAccount
        $chatwootAccount = $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->where(['chatwootAccountId' => $accountId])
            ->findOne();

        if (!$chatwootAccount) {
            $this->log->warning("ChatwootAccount not found for chatwootAccountId: {$accountId}");
            return;
        }

        // Check if mapping already exists
        $mapping = $this->findMappingByChatwootId($chatwootContactId, $chatwootAccount->getId());

        if ($mapping) {
            // Already linked - update EspoCRM contact
            $contact = $this->entityManager->getEntityById('Contact', $mapping->get('contactId'));
            if ($contact) {
                $this->updateEspoCrmContact($contact, $contactData);
            }
            return;
        }

        // Try to find existing EspoCRM contact by phone or email
        $contact = $this->findContactByPhoneOrEmail($phoneNumber, $email);

        if (!$contact && ($phoneNumber || $email)) {
            // Create new Contact in EspoCRM
            $contact = $this->entityManager->createEntity('Contact', [
                'firstName' => $this->parseFirstName($name),
                'lastName' => $this->parseLastName($name),
                'phoneNumber' => $phoneNumber,
                'emailAddress' => $email
            ]);
        }

        if ($contact && $phoneNumber) {
            // Create mapping
            $normalizedPhone = $this->normalizePhoneNumber($phoneNumber);
            $this->entityManager->createEntity('ChatwootContact', [
                'contactId' => $contact->getId(),
                'phoneNumber' => $normalizedPhone,
                'chatwootAccountId' => $chatwootAccount->getId(),
                'chatwootContactId' => $chatwootContactId,
                'lastSyncedAt' => date('Y-m-d H:i:s'),
                'syncStatus' => 'synced'
            ]);

            $this->log->info("Linked Chatwoot contact {$chatwootContactId} to EspoCRM Contact {$contact->getId()}");
        }
    }

    /**
     * Update EspoCRM contact with data from Chatwoot.
     *
     * @param Entity $contact
     * @param array<string, mixed> $chatwootData
     * @return void
     */
    private function updateEspoCrmContact(Entity $contact, array $chatwootData): void
    {
        $updated = false;

        // Update name if changed
        if (isset($chatwootData['name'])) {
            $firstName = $this->parseFirstName($chatwootData['name']);
            $lastName = $this->parseLastName($chatwootData['name']);
            
            if ($contact->get('firstName') !== $firstName) {
                $contact->set('firstName', $firstName);
                $updated = true;
            }
            if ($contact->get('lastName') !== $lastName) {
                $contact->set('lastName', $lastName);
                $updated = true;
            }
        }

        // Update email if changed
        if (isset($chatwootData['email']) && $chatwootData['email'] !== $contact->get('emailAddress')) {
            $contact->set('emailAddress', $chatwootData['email']);
            $updated = true;
        }

        if ($updated) {
            $this->entityManager->saveEntity($contact, ['silent' => true]);
            $this->log->info("Updated EspoCRM Contact {$contact->getId()} from Chatwoot data");
        }
    }
}

