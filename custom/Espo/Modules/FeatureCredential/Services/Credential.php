<?php

namespace Espo\Modules\FeatureCredential\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\CreateResult;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Record\UpdateResult;
use Espo\Core\Record\DeleteParams;
use Espo\Core\Record\DeleteResult;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialConfigCipher;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\ORM\Entity;
use Espo\Services\Record;

class Credential extends Record
{
    private const ENABLE_OPTIONAL_SCHEMA_VALUE_VALIDATION = false;

    public function loadAdditionalFields(Entity $entity): void
    {
        parent::loadAdditionalFields($entity);

        // Set isExpired virtual field
        $expiresAt = $entity->get('expiresAt');
        if ($expiresAt) {
            $isExpired = new \DateTime($expiresAt) < new \DateTime();
            $entity->set('isExpired', $isExpired);
        } else {
            $entity->set('isExpired', false);
        }
    }

    public function create(\stdClass $data, CreateParams $params = new CreateParams()): CreateResult
    {
        // Validate config against schema before creation.
        if (isset($data->credentialTypeId) && $data->credentialTypeId !== '') {
            $this->validateConfig(
                (string) $data->credentialTypeId,
                $data->config ?? null,
                isset($data->oAuthAccountId) ? (string) $data->oAuthAccountId : null
            );
        }

        $result = parent::create($data, $params);

        // Log creation
        $this->logHistory($result->getEntity(), 'created');

        return $result;
    }

    public function update(
        string $id,
        \stdClass $data,
        UpdateParams $params = new UpdateParams()
    ): UpdateResult {
        $entity = $this->getEntity($id);
        if (!$entity) {
            throw new NotFound();
        }

        $previousConfig = $entity->get('config');

        // Validate when relevant inputs change (config, credential type, OAuth account linkage).
        if (
            property_exists($data, 'config') ||
            property_exists($data, 'credentialTypeId') ||
            property_exists($data, 'oAuthAccountId')
        ) {
            $credentialTypeId = isset($data->credentialTypeId) && $data->credentialTypeId !== ''
                ? (string) $data->credentialTypeId
                : (string) $entity->get('credentialTypeId');

            if ($credentialTypeId !== '') {
                $this->validateConfig(
                    $credentialTypeId,
                    property_exists($data, 'config') ? $data->config : $entity->get('config'),
                    property_exists($data, 'oAuthAccountId')
                        ? ($data->oAuthAccountId ? (string) $data->oAuthAccountId : null)
                        : ($entity->get('oAuthAccountId') ? (string) $entity->get('oAuthAccountId') : null)
                );
            }
        }

        $result = parent::update($id, $data, $params);
        $entity = $result->getEntity();

        // Log update
        $this->logHistory($entity, 'updated', $previousConfig, $entity->get('config'));

        return $result;
    }

    public function delete(string $id, DeleteParams $params = new DeleteParams()): DeleteResult
    {
        $entity = $this->getEntity($id);
        if ($entity) {
            // Log deletion before actual delete
            $this->logHistory($entity, 'deleted', $entity->get('config'), null);
        }

        return parent::delete($id, $params);
    }

    /**
     * Get the resolved credential value, merging static config with live OAuth
     * tokens when applicable. This is the primary method consumers should use.
     *
     * @throws NotFound
     * @throws Forbidden
     * @throws Error
     */
    public function getResolvedValue(string $id): \stdClass
    {
        $entity = $this->getEntity($id);
        if (!$entity) {
            throw new NotFound();
        }

        if (!$this->acl->check($entity, 'read')) {
            throw new Forbidden();
        }

        /** @var CredentialResolver $resolver */
        $resolver = $this->injectableFactory
            ->create(CredentialResolver::class);

        $data = $resolver->resolve($id);

        // Log access
        $this->logHistory($entity, 'viewed');

        // Update last used timestamp
        $entity->set('lastUsedAt', date('Y-m-d H:i:s'));
        $this->entityManager->saveEntity($entity);

        return $data;
    }

    /**
     * Get decrypted credential value (static config only, no OAuth merge).
     *
     * @deprecated Use getResolvedValue() instead for full OAuth support.
     */
    public function getDecryptedValue(string $id): array
    {
        $entity = $this->getEntity($id);
        if (!$entity) {
            throw new NotFound();
        }

        if (!$this->acl->check($entity, 'read')) {
            throw new Forbidden();
        }

        $config = $entity->get('config');
        $data = json_decode($config, true) ?? [];

        // Log access
        $this->logHistory($entity, 'viewed');

        return $data;
    }

    /**
     * Mark credential as used
     */
    public function markAsUsed(string $id): void
    {
        $entity = $this->getEntity($id);
        if (!$entity) {
            throw new NotFound();
        }

        $entity->set('lastUsedAt', date('Y-m-d H:i:s'));
        $this->entityManager->saveEntity($entity);

        // Log usage
        $this->logHistory($entity, 'used');
    }

    /**
     * Rotate credential
     */
    public function rotate(string $id, \stdClass $newConfig, ?string $reason = null): Entity
    {
        $entity = $this->getEntity($id);
        if (!$entity) {
            throw new NotFound();
        }

        if (!$this->acl->check($entity, 'edit')) {
            throw new Forbidden();
        }

        $previousConfig = $entity->get('config');

        // Validate new config
        $credentialTypeId = $entity->get('credentialTypeId');
        $this->validateConfig($credentialTypeId, $newConfig);

        // Update entity
        $entity->set([
            'config' => json_encode($newConfig),
            'lastRotatedAt' => date('Y-m-d H:i:s')
        ]);

        $this->entityManager->saveEntity($entity);

        // Log rotation
        $this->logHistory($entity, 'rotated', $previousConfig, $entity->get('config'), $reason);

        return $entity;
    }

    /**
     * Validate config against credential type schema.
     *
     * For OAuth-backed types, required fields marked with source=oauth are
     * excluded from manual config checks and validated through linked
     * OAuth account resolution.
     */
    protected function validateConfig(string $credentialTypeId, $config, ?string $oAuthAccountId = null): void
    {
        $credentialType = $this->entityManager->getEntity('CredentialType', $credentialTypeId);
        if (!$credentialType) {
            throw new Error("Credential type not found");
        }

        $schema = $credentialType->get('schema');
        if (empty($schema)) {
            return;
        }

        $schemaData = is_string($schema) ? json_decode($schema, true) : (array) $schema;
        if (!$schemaData || !is_array($schemaData)) {
            return;
        }

        if (!is_array($schemaData['properties'] ?? null)) {
            $schemaData['properties'] = [];
        }

        $configData = $this->normalizeConfigData($config);
        $oauthRequiredFields = [];

        // Authoritative required validation from schema.
        if (!empty($schemaData['required'])) {
            foreach ($schemaData['required'] as $field) {
                if (!is_string($field) || $field === '') {
                    continue;
                }

                $propertySchema = $schemaData['properties'][$field] ?? null;

                if (is_array($propertySchema) && ($propertySchema['source'] ?? null) === 'oauth') {
                    $oauthRequiredFields[] = $field;
                    continue;
                }

                if (!isset($configData[$field]) || $configData[$field] === '') {
                    throw new Error("Required field '{$field}' is missing");
                }
            }
        }

        if (!empty($oauthRequiredFields)) {
            $this->assertOAuthSourceCanBeResolved($oAuthAccountId, $oauthRequiredFields);
        }

        if (self::ENABLE_OPTIONAL_SCHEMA_VALUE_VALIDATION) {
            $this->validateOptionalSchemaConstraints($schemaData, $configData);
        }
    }

    private function normalizeConfigData($config): array
    {
        if ($config === null || $config === '') {
            return [];
        }

        if (is_string($config)) {
            $decoded = json_decode($config, true);

            return is_array($decoded) ? $decoded : [];
        }

        if (is_array($config)) {
            return $config;
        }

        if ($config instanceof \stdClass) {
            return (array) $config;
        }

        return [];
    }

    private function assertOAuthSourceCanBeResolved(?string $oAuthAccountId, array $oauthRequiredFields): void
    {
        if (!$oAuthAccountId) {
            throw new Error(
                "OAuth account is required to resolve OAuth-sourced required fields: '" .
                implode("', '", $oauthRequiredFields) .
                "'"
            );
        }

        $oAuthAccount = $this->entityManager->getEntityById('OAuthAccount', $oAuthAccountId);

        if (!$oAuthAccount) {
            throw new Error("OAuth account '{$oAuthAccountId}' not found for OAuth-sourced required fields");
        }
    }

    private function validateOptionalSchemaConstraints(array $schemaData, array $configData): void
    {
        foreach ($schemaData['properties'] as $field => $propertySchema) {
            if (!is_array($propertySchema) || !array_key_exists($field, $configData)) {
                continue;
            }

            $value = $configData[$field];

            if ($value === null || $value === '') {
                continue;
            }

            if (isset($propertySchema['enum']) && is_array($propertySchema['enum'])) {
                if (!in_array($value, $propertySchema['enum'], true)) {
                    throw new Error("Field '{$field}' has an invalid value");
                }
            }

            if (!isset($propertySchema['type']) || !is_string($propertySchema['type'])) {
                continue;
            }

            $isTypeValid = match ($propertySchema['type']) {
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value),
                'object' => is_array($value) || $value instanceof \stdClass,
                default => true,
            };

            if (!$isTypeValid) {
                throw new Error("Field '{$field}' has an invalid type");
            }
        }
    }

    /**
     * Log credential history.
     *
     * `previousValue` and `newValue` carry the Credential's `config` JSON;
     * any field listed in `CredentialType.encryptionFields` is REDACTED to
     * `'***'` before persistence. The history table is for audit of *what
     * changed* — exact secret values do not belong there, and storing them
     * would compound the blast radius if a row leaked.
     */
    protected function logHistory(
        Entity $credential,
        string $action,
        ?string $previousValue = null,
        ?string $newValue = null,
        ?string $reason = null
    ): void {
        $credentialType = $this->resolveCredentialTypeForHistory($credential);

        /** @var CredentialConfigCipher $cipher */
        $cipher = $this->injectableFactory->create(CredentialConfigCipher::class);

        $sanitizedPrevious = $cipher->redactConfig($previousValue, $credentialType);
        $sanitizedNew = $cipher->redactConfig($newValue, $credentialType);

        $history = $this->entityManager->getEntity('CredentialHistory');
        $history->set([
            'credentialId' => $credential->getId(),
            'action' => $action,
            'previousValue' => $sanitizedPrevious,
            'newValue' => $sanitizedNew,
            'reason' => $reason,
            'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? null,
            'createdById' => $this->user->getId(),
            'createdAt' => date('Y-m-d H:i:s')
        ]);
        $this->entityManager->saveEntity($history);
    }

    private function resolveCredentialTypeForHistory(Entity $credential): ?Entity
    {
        $credentialTypeId = $credential->get('credentialTypeId');

        if (!$credentialTypeId) {
            return null;
        }

        return $this->entityManager->getEntityById('CredentialType', (string) $credentialTypeId);
    }
}
