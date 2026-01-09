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
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;
use Espo\Core\Acl;
use stdClass;

/**
 * Virtual RecordService for WahaSessionApp entity.
 * Fetches data from WAHA API instead of database.
 */
class WahaSessionApp
{
    public const ENTITY_TYPE = 'WahaSessionApp';

    public function __construct(
        private EntityManager $entityManager,
        private WahaApiClient $wahaApiClient,
        private Log $log,
        private Acl $acl
    ) {}

    /**
     * Find all apps for a session.
     *
     * @param string $platformId The WahaPlatform ID
     * @param string $sessionName The session name
     * @return RecordCollection
     * @throws Error
     * @throws Forbidden
     */
    public function findBySession(string $platformId, string $sessionName): RecordCollection
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'read')) {
            throw new Forbidden("No read access to WahaSessionApp.");
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

        $collection = $this->entityManager->getCollectionFactory()->create(self::ENTITY_TYPE);
        $totalCount = 0;

        try {
            $apps = $this->wahaApiClient->listApps($platformUrl, $apiKey, $sessionName);

            foreach ($apps as $appData) {
                $entity = $this->mapAppToEntity($appData, $platformId, $platform->get('name'));
                $collection->append($entity);
                $totalCount++;
            }
        } catch (Error $e) {
            $this->log->error("Failed to fetch apps for session {$sessionName}: " . $e->getMessage());
        }

        return RecordCollection::create($collection, $totalCount);
    }

    /**
     * Find all apps across all platforms and sessions (for listing).
     *
     * @param string|null $platformId Optional platform filter
     * @return RecordCollection
     * @throws Error
     * @throws Forbidden
     */
    public function find(?string $platformId = null): RecordCollection
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'read')) {
            throw new Forbidden("No read access to WahaSessionApp.");
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
                // First get all sessions
                $sessions = $this->wahaApiClient->listSessions($platformUrl, $apiKey, true);

                foreach ($sessions as $sessionData) {
                    $sessionName = $sessionData['name'] ?? 'default';

                    try {
                        $apps = $this->wahaApiClient->listApps($platformUrl, $apiKey, $sessionName);

                        foreach ($apps as $appData) {
                            $entity = $this->mapAppToEntity($appData, $platId, $platform->get('name'));
                            $collection->append($entity);
                            $totalCount++;
                        }
                    } catch (Error $e) {
                        $this->log->warning("Failed to fetch apps for session {$sessionName}: " . $e->getMessage());
                    }
                }
            } catch (Error $e) {
                $this->log->error("Failed to fetch sessions from platform {$platId}: " . $e->getMessage());
            }
        }

        return RecordCollection::create($collection, $totalCount);
    }

    /**
     * Read a single app by ID.
     *
     * @param string $platformId The WahaPlatform ID
     * @param string $appId The app ID from WAHA
     * @return stdClass
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function read(string $platformId, string $appId): stdClass
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'read')) {
            throw new Forbidden("No read access to WahaSessionApp.");
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
            $appData = $this->wahaApiClient->getApp($platformUrl, $apiKey, $appId);
        } catch (Error $e) {
            throw new NotFound("App '{$appId}' not found.");
        }

        $entity = $this->mapAppToEntity($appData, $platformId, $platform->get('name'));

        return $entity->getValueMap();
    }

    /**
     * Create a new app.
     *
     * @param string $platformId The WahaPlatform ID
     * @param stdClass $data App data
     * @return stdClass
     * @throws Error
     * @throws Forbidden
     * @throws BadRequest
     */
    public function create(string $platformId, stdClass $data): stdClass
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'create')) {
            throw new Forbidden("No create access to WahaSessionApp.");
        }

        if (empty($data->sessionName)) {
            throw new BadRequest("Session name is required.");
        }

        if (empty($data->appType)) {
            throw new BadRequest("App type is required.");
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

        $appType = $data->appType;

        // Generate app ID if not provided
        $appId = !empty($data->wahaAppId) ? $data->wahaAppId : $this->generateAppId($appType, $data->sessionName);

        $appPayload = [
            'id' => $appId,
            'session' => $data->sessionName,
            'app' => $appType,
            'enabled' => $data->enabled ?? true,
        ];

        // Get config - use provided config or default for app type
        $config = $this->resolveConfig($data, $appType);
        $appPayload['config'] = $config;

        $appData = $this->wahaApiClient->createApp($platformUrl, $apiKey, $appPayload);

        $entity = $this->mapAppToEntity($appData, $platformId, $platform->get('name'));

        return $entity->getValueMap();
    }

    /**
     * Generate an app ID based on app type.
     *
     * @param string $appType
     * @param string $sessionName
     * @return string
     */
    private function generateAppId(string $appType, string $sessionName): string
    {
        if ($appType === 'calls') {
            // For calls app, use session name as ID
            return $sessionName;
        }

        // For other apps (chatwoot, etc.), generate a UUID-like ID
        return 'app_' . bin2hex(random_bytes(16));
    }

    /**
     * Resolve config for app creation.
     * Merges user-provided config with defaults.
     *
     * @param stdClass $data
     * @param string $appType
     * @return array<string, mixed>
     */
    private function resolveConfig(stdClass $data, string $appType): array
    {
        $defaultConfig = $this->getDefaultConfig($appType, $data);

        // If no config provided, use defaults
        if (!isset($data->config)) {
            return $this->convertConfigForJson($defaultConfig);
        }

        // Convert provided config to array
        $providedConfig = is_object($data->config) ? (array) $data->config : $data->config;

        if (!is_array($providedConfig) || empty($providedConfig)) {
            return $this->convertConfigForJson($defaultConfig);
        }

        // Merge provided config with defaults (provided takes precedence)
        $mergedConfig = array_replace_recursive($defaultConfig, $providedConfig);

        return $this->convertConfigForJson($mergedConfig);
    }

    /**
     * Convert config array to ensure proper JSON encoding.
     * Converts empty arrays to stdClass for JSON object encoding.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function convertConfigForJson(array $config): array
    {
        foreach ($config as $key => $value) {
            if ($value instanceof \stdClass) {
                // Keep stdClass as is
                continue;
            }
            if (is_array($value) && empty($value)) {
                // Convert empty arrays to stdClass for {} in JSON
                $config[$key] = new \stdClass();
            } elseif (is_array($value) && !$this->isSequentialArray($value)) {
                // Recursively process associative arrays
                $config[$key] = $this->convertConfigForJson($value);
            }
        }
        return $config;
    }

    /**
     * Check if array is sequential (list) or associative.
     *
     * @param array<mixed> $arr
     * @return bool
     */
    private function isSequentialArray(array $arr): bool
    {
        if (empty($arr)) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * Get default configuration for an app type.
     *
     * @param string $appType
     * @param stdClass|null $data Optional data with chatwootAccountId, chatwootInboxId
     * @return array<string, mixed>
     */
    private function getDefaultConfig(string $appType, ?stdClass $data = null): array
    {
        switch ($appType) {
            case 'chatwoot':
                return $this->buildChatwootConfig($data);
            case 'calls':
                return [
                    'rejectCalls' => true,
                    'autoReplyMessage' => 'Sorry, I cannot take calls right now. Please send a message.',
                ];
            default:
                return [];
        }
    }

    /**
     * Build Chatwoot config from existing Chatwoot entities or defaults.
     *
     * @param stdClass|null $data
     * @return array<string, mixed>
     */
    private function buildChatwootConfig(?stdClass $data = null): array
    {
        $config = [
            'linkPreview' => 'OFF',
            'locale' => 'en-US',
            'url' => '',
            'accountId' => 0,
            'accountToken' => '',
            'inboxId' => 0,
            'inboxIdentifier' => '',
            'templates' => new \stdClass(),
            'commands' => [
                'server' => true,
                'queue' => true,
            ],
            'conversations' => [
                'sort' => 'created_newest',
                'status' => ['open', 'pending', 'snoozed'],
            ],
        ];

        // Try to get ChatwootAccount - either by ID or first available
        $chatwootAccount = null;
        $chatwootInbox = null;

        if ($data && !empty($data->chatwootAccountId)) {
            $chatwootAccount = $this->entityManager->getEntityById('ChatwootAccount', $data->chatwootAccountId);
        }

        if (!$chatwootAccount) {
            // Get first available ChatwootAccount
            $chatwootAccount = $this->entityManager
                ->getRDBRepository('ChatwootAccount')
                ->where(['status' => 'active'])
                ->findOne();
        }

        if ($chatwootAccount) {
            // Get platform URL
            $platform = $chatwootAccount->get('platform');
            if ($platform) {
                $config['url'] = rtrim($platform->get('url') ?? '', '/');
            }

            // Account details
            $config['accountId'] = (int) $chatwootAccount->get('chatwootAccountId');
            $config['accountToken'] = $chatwootAccount->get('apiKey') ?? '';

            // Get locale from account
            $locale = $chatwootAccount->get('locale');
            if ($locale) {
                // Map EspoCRM locale format to WAHA format (pt_BR -> pt-BR)
                $config['locale'] = str_replace('_', '-', $locale);
            }

            // Try to get inbox - either by ID or first available for this account
            if ($data && !empty($data->chatwootInboxId)) {
                $chatwootInbox = $this->entityManager->getEntityById('ChatwootInbox', $data->chatwootInboxId);
            }

            if (!$chatwootInbox) {
                // Get first inbox for this account with channelType 'Channel::Api' (WhatsApp)
                $chatwootInbox = $this->entityManager
                    ->getRDBRepository('ChatwootInbox')
                    ->where([
                        'chatwootAccountId' => $chatwootAccount->getId(),
                    ])
                    ->findOne();
            }

            if ($chatwootInbox) {
                $config['inboxId'] = (int) $chatwootInbox->get('chatwootInboxId');
                // Get inboxIdentifier from the synced inbox data
                $inboxIdentifier = $chatwootInbox->get('inboxIdentifier');
                $config['inboxIdentifier'] = $inboxIdentifier ?? '';
            }
        }

        return $config;
    }

    /**
     * Update an existing app.
     *
     * @param string $platformId The WahaPlatform ID
     * @param string $appId The app ID
     * @param stdClass $data App data to update
     * @return stdClass
     * @throws Error
     * @throws Forbidden
     */
    public function update(string $platformId, string $appId, stdClass $data): stdClass
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'edit')) {
            throw new Forbidden("No edit access to WahaSessionApp.");
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

        $appPayload = [];

        if (isset($data->enabled)) {
            $appPayload['enabled'] = (bool) $data->enabled;
        }

        if (isset($data->config)) {
            $appPayload['config'] = (array) $data->config;
        }

        $appData = $this->wahaApiClient->updateApp($platformUrl, $apiKey, $appId, $appPayload);

        $entity = $this->mapAppToEntity($appData, $platformId, $platform->get('name'));

        return $entity->getValueMap();
    }

    /**
     * Delete an app.
     *
     * @param string $platformId The WahaPlatform ID
     * @param string $appId The app ID
     * @return void
     * @throws Error
     * @throws Forbidden
     */
    public function delete(string $platformId, string $appId): void
    {
        if (!$this->acl->checkScope(self::ENTITY_TYPE, 'delete')) {
            throw new Forbidden("No delete access to WahaSessionApp.");
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

        $this->wahaApiClient->deleteApp($platformUrl, $apiKey, $appId);
    }

    /**
     * Map WAHA app data to an EspoCRM entity.
     *
     * @param array<string, mixed> $appData
     * @param string $platformId
     * @param string|null $platformName
     * @return \Espo\ORM\Entity
     */
    private function mapAppToEntity(array $appData, string $platformId, ?string $platformName = null): \Espo\ORM\Entity
    {
        $entity = $this->entityManager->getNewEntity(self::ENTITY_TYPE);

        $wahaAppId = $appData['id'] ?? '';
        $sessionName = $appData['session'] ?? '';
        $appType = $appData['app'] ?? '';

        // Composite ID: platformId_appId
        $entity->set('id', $platformId . '_' . $wahaAppId);
        $entity->set('wahaAppId', $wahaAppId);
        $entity->set('name', $appType . ' - ' . $sessionName);
        $entity->set('platformId', $platformId);
        $entity->set('platformName', $platformName);

        // Session info
        $entity->set('sessionName', $sessionName);

        // App type and enabled
        $entity->set('appType', $appType);
        $entity->set('enabled', $appData['enabled'] ?? false);

        // Config
        if (isset($appData['config'])) {
            $entity->set('config', $appData['config']);
        }

        $entity->setAsFetched();

        return $entity;
    }
}

