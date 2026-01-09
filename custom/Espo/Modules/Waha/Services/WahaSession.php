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

namespace Espo\Modules\Waha\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\Collection as RecordCollection;
use Espo\Core\Select\SearchParams;
use Espo\ORM\EntityManager;
use Espo\ORM\Collection as EntityCollection;
use Espo\Core\Utils\Log;
use Espo\Core\Acl;
use Espo\Core\InjectableFactory;
use stdClass;

/**
 * Virtual RecordService for WahaSession entity.
 * Fetches data from WAHA API instead of database.
 */
class WahaSession
{
    public const ENTITY_TYPE = 'WahaSession';

    public function __construct(
        private EntityManager $entityManager,
        private WahaApiClient $wahaApiClient,
        private Log $log,
        private Acl $acl,
        private InjectableFactory $injectableFactory
    ) {}

    /**
     * Find all sessions from a platform or all platforms.
     *
     * @param string|null $platformId The WahaPlatform ID (optional, if null fetches from all platforms)
     * @param SearchParams|null $searchParams Optional search params
     * @return RecordCollection
     * @throws Error
     * @throws Forbidden
     */
    public function find(?string $platformId = null, ?SearchParams $searchParams = null): RecordCollection
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'read')) {
            throw new Forbidden("No read access to WahaSession.");
        }

        $collection = $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE);
        $totalCount = 0;

        // If platformId is provided, fetch from that platform only
        if ($platformId) {
            $platforms = [$this->entityManager->getEntityById('WahaPlatform', $platformId)];
            if (!$platforms[0]) {
                throw new Error("WahaPlatform with ID '{$platformId}' not found.");
            }
        } else {
            // Fetch from all platforms
            $platforms = $this->entityManager
                ->getRDBRepository('WahaPlatform')
                ->find();
        }

        foreach ($platforms as $platform) {
            $platformUrl = $platform->get('url');
            $apiKey = $platform->get('apiKey');
            $platId = $platform->getId();

            if (!$platformUrl || !$apiKey) {
                $this->log->warning("WahaPlatform {$platId} missing URL or API Key, skipping.");
                continue;
            }

            try {
                // Fetch all sessions including stopped ones
                $sessions = $this->wahaApiClient->listSessions($platformUrl, $apiKey, true);

                foreach ($sessions as $sessionData) {
                    $entity = $this->mapSessionToEntity($sessionData, $platId, $platform->get('name'));
                    $collection->append($entity);
                    $totalCount++;
                }
            } catch (Error $e) {
                $this->log->error("Failed to fetch sessions from platform {$platId}: " . $e->getMessage());
            }
        }

        return RecordCollection::create($collection, $totalCount);
    }

    /**
     * Read a single session by name.
     *
     * @param string $platformId The WahaPlatform ID
     * @param string $sessionName The session name (used as ID)
     * @return stdClass
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function read(string $platformId, string $sessionName): stdClass
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'read')) {
            throw new Forbidden("No read access to WahaSession.");
        }

        $platform = $this->entityManager->getEntityById('WahaPlatform', $platformId);

        if (!$platform) {
            throw new NotFound("WahaPlatform with ID '{$platformId}' not found.");
        }

        $platformUrl = $platform->get('url');
        $apiKey = $platform->get('apiKey');

        if (!$platformUrl || !$apiKey) {
            throw new Error("WahaPlatform missing URL or API Key.");
        }

        try {
            $sessionData = $this->wahaApiClient->getSession($platformUrl, $apiKey, $sessionName);
        } catch (Error $e) {
            throw new NotFound("Session '{$sessionName}' not found.");
        }

        $entity = $this->mapSessionToEntity($sessionData, $platformId, $platform->get('name'));

        return $entity->getValueMap();
    }

    /**
     * Create a new session.
     *
     * @param string $platformId The WahaPlatform ID
     * @param stdClass $data Session data
     * @return stdClass
     * @throws Error
     * @throws Forbidden
     * @throws BadRequest
     */
    public function create(string $platformId, stdClass $data): stdClass
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'create')) {
            throw new Forbidden("No create access to WahaSession.");
        }

        if (empty($data->name)) {
            throw new BadRequest("Session name is required.");
        }

        $platform = $this->entityManager->getEntityById('WahaPlatform', $platformId);

        if (!$platform) {
            throw new Error("WahaPlatform with ID '{$platformId}' not found.");
        }

        $platformUrl = $platform->get('url');
        $apiKey = $platform->get('apiKey');

        if (!$platformUrl || !$apiKey) {
            throw new Error("WahaPlatform missing URL or API Key.");
        }

        $sessionPayload = [
            'name' => $data->name,
        ];

        // Add optional config if provided
        if (isset($data->config)) {
            $sessionPayload['config'] = (array) $data->config;
        }

        $sessionData = $this->wahaApiClient->createSession($platformUrl, $apiKey, $sessionPayload);

        $entity = $this->mapSessionToEntity($sessionData, $platformId);

        return $entity->getValueMap();
    }

    /**
     * Update an existing session.
     *
     * @param string $platformId The WahaPlatform ID
     * @param string $sessionName The session name
     * @param stdClass $data Session data to update
     * @return stdClass
     * @throws Error
     * @throws Forbidden
     */
    public function update(string $platformId, string $sessionName, stdClass $data): stdClass
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'edit')) {
            throw new Forbidden("No edit access to WahaSession.");
        }

        $platform = $this->entityManager->getEntityById('WahaPlatform', $platformId);

        if (!$platform) {
            throw new Error("WahaPlatform with ID '{$platformId}' not found.");
        }

        $platformUrl = $platform->get('url');
        $apiKey = $platform->get('apiKey');

        if (!$platformUrl || !$apiKey) {
            throw new Error("WahaPlatform missing URL or API Key.");
        }

        $sessionPayload = [];

        if (isset($data->config)) {
            $sessionPayload['config'] = (array) $data->config;
        }

        $sessionData = $this->wahaApiClient->updateSession($platformUrl, $apiKey, $sessionName, $sessionPayload);

        $entity = $this->mapSessionToEntity($sessionData, $platformId);

        return $entity->getValueMap();
    }

    /**
     * Delete a session.
     *
     * @param string $platformId The WahaPlatform ID
     * @param string $sessionName The session name
     * @return void
     * @throws Error
     * @throws Forbidden
     */
    public function delete(string $platformId, string $sessionName): void
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'delete')) {
            throw new Forbidden("No delete access to WahaSession.");
        }

        $platform = $this->entityManager->getEntityById('WahaPlatform', $platformId);

        if (!$platform) {
            throw new Error("WahaPlatform with ID '{$platformId}' not found.");
        }

        $platformUrl = $platform->get('url');
        $apiKey = $platform->get('apiKey');

        if (!$platformUrl || !$apiKey) {
            throw new Error("WahaPlatform missing URL or API Key.");
        }

        $this->wahaApiClient->deleteSession($platformUrl, $apiKey, $sessionName);
    }

    /**
     * Start a session.
     *
     * @param string $platformId The WahaPlatform ID
     * @param string $sessionName The session name
     * @return stdClass
     * @throws Error
     * @throws Forbidden
     */
    public function start(string $platformId, string $sessionName): stdClass
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'edit')) {
            throw new Forbidden("No edit access to WahaSession.");
        }

        $platform = $this->entityManager->getEntityById('WahaPlatform', $platformId);

        if (!$platform) {
            throw new Error("WahaPlatform with ID '{$platformId}' not found.");
        }

        $platformUrl = $platform->get('url');
        $apiKey = $platform->get('apiKey');

        if (!$platformUrl || !$apiKey) {
            throw new Error("WahaPlatform missing URL or API Key.");
        }

        $sessionData = $this->wahaApiClient->startSession($platformUrl, $apiKey, $sessionName);

        $entity = $this->mapSessionToEntity($sessionData, $platformId);

        return $entity->getValueMap();
    }

    /**
     * Stop a session.
     *
     * @param string $platformId The WahaPlatform ID
     * @param string $sessionName The session name
     * @return stdClass
     * @throws Error
     * @throws Forbidden
     */
    public function stop(string $platformId, string $sessionName): stdClass
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'edit')) {
            throw new Forbidden("No edit access to WahaSession.");
        }

        $platform = $this->entityManager->getEntityById('WahaPlatform', $platformId);

        if (!$platform) {
            throw new Error("WahaPlatform with ID '{$platformId}' not found.");
        }

        $platformUrl = $platform->get('url');
        $apiKey = $platform->get('apiKey');

        if (!$platformUrl || !$apiKey) {
            throw new Error("WahaPlatform missing URL or API Key.");
        }

        $sessionData = $this->wahaApiClient->stopSession($platformUrl, $apiKey, $sessionName);

        $entity = $this->mapSessionToEntity($sessionData, $platformId);

        return $entity->getValueMap();
    }

    /**
     * Restart a session.
     *
     * @param string $platformId The WahaPlatform ID
     * @param string $sessionName The session name
     * @return stdClass
     * @throws Error
     * @throws Forbidden
     */
    public function restart(string $platformId, string $sessionName): stdClass
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'edit')) {
            throw new Forbidden("No edit access to WahaSession.");
        }

        $platform = $this->entityManager->getEntityById('WahaPlatform', $platformId);

        if (!$platform) {
            throw new Error("WahaPlatform with ID '{$platformId}' not found.");
        }

        $platformUrl = $platform->get('url');
        $apiKey = $platform->get('apiKey');

        if (!$platformUrl || !$apiKey) {
            throw new Error("WahaPlatform missing URL or API Key.");
        }

        $sessionData = $this->wahaApiClient->restartSession($platformUrl, $apiKey, $sessionName);

        $entity = $this->mapSessionToEntity($sessionData, $platformId);

        return $entity->getValueMap();
    }

    /**
     * Logout from a session (disconnect WhatsApp account but keep session).
     *
     * @param string $platformId The WahaPlatform ID
     * @param string $sessionName The session name
     * @return stdClass
     * @throws Error
     * @throws Forbidden
     */
    public function logout(string $platformId, string $sessionName): stdClass
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'edit')) {
            throw new Forbidden("No edit access to WahaSession.");
        }

        $platform = $this->entityManager->getEntityById('WahaPlatform', $platformId);

        if (!$platform) {
            throw new Error("WahaPlatform with ID '{$platformId}' not found.");
        }

        $platformUrl = $platform->get('url');
        $apiKey = $platform->get('apiKey');

        if (!$platformUrl || !$apiKey) {
            throw new Error("WahaPlatform missing URL or API Key.");
        }

        $sessionData = $this->wahaApiClient->logoutSession($platformUrl, $apiKey, $sessionName);

        $entity = $this->mapSessionToEntity($sessionData, $platformId);

        return $entity->getValueMap();
    }

    /**
     * Get QR code for pairing WhatsApp.
     *
     * @param string $platformId The WahaPlatform ID
     * @param string $sessionName The session name
     * @return stdClass Contains mimetype and base64 data
     * @throws Error
     * @throws Forbidden
     */
    public function getQrCode(string $platformId, string $sessionName): stdClass
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'read')) {
            throw new Forbidden("No read access to WahaSession.");
        }

        $platform = $this->entityManager->getEntityById('WahaPlatform', $platformId);

        if (!$platform) {
            throw new Error("WahaPlatform with ID '{$platformId}' not found.");
        }

        $platformUrl = $platform->get('url');
        $apiKey = $platform->get('apiKey');

        if (!$platformUrl || !$apiKey) {
            throw new Error("WahaPlatform missing URL or API Key.");
        }

        $qrData = $this->wahaApiClient->getQrCode($platformUrl, $apiKey, $sessionName);

        return (object) [
            'mimetype' => $qrData['mimetype'],
            'data' => $qrData['data'],
            'dataUrl' => 'data:' . $qrData['mimetype'] . ';base64,' . $qrData['data']
        ];
    }

    /**
     * Map WAHA session data to an EspoCRM entity.
     *
     * @param array<string, mixed> $sessionData
     * @param string $platformId
     * @param string|null $platformName
     * @return \Espo\ORM\Entity
     */
    private function mapSessionToEntity(array $sessionData, string $platformId, ?string $platformName = null): \Espo\ORM\Entity
    {
        $entity = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        // Session name is the ID
        $name = $sessionData['name'] ?? 'default';
        $entity->set('id', $platformId . '_' . $name);
        $entity->set('name', $name);
        $entity->set('platformId', $platformId);
        $entity->set('platformName', $platformName);

        // Status
        $entity->set('status', $sessionData['status'] ?? 'UNKNOWN');

        // Me (WhatsApp account info)
        if (isset($sessionData['me'])) {
            $me = $sessionData['me'];
            $entity->set('waId', $me['id'] ?? null);
            $entity->set('pushName', $me['pushName'] ?? null);
        }

        // Config
        if (isset($sessionData['config'])) {
            $entity->set('config', json_encode($sessionData['config']));
        }

        // Assigned worker
        $entity->set('assignedWorker', $sessionData['assignedWorker'] ?? null);

        // Timestamps
        if (isset($sessionData['timestamps']['activity'])) {
            $timestamp = $sessionData['timestamps']['activity'];
            if ($timestamp > 0) {
                $entity->set('lastActivity', date('Y-m-d H:i:s', $timestamp));
            }
        }

        $entity->setAsFetched();

        return $entity;
    }
}

