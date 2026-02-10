<?php

namespace Espo\Modules\FeatureCredential\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Record\DeleteParams;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\ORM\Entity;
use Espo\Services\Record;

class Credential extends Record
{
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

    public function create(\stdClass $data, ?CreateParams $params = null): Entity
    {
        // Validate config against schema before creation
        if (!empty($data->credentialTypeId) && !empty($data->config)) {
            $this->validateConfig($data->credentialTypeId, $data->config);
        }

        $entity = parent::create($data, $params);

        // Log creation
        $this->logHistory($entity, 'created');

        return $entity;
    }

    public function update(string $id, \stdClass $data, ?UpdateParams $params = null): Entity
    {
        $entity = $this->getEntity($id);
        if (!$entity) {
            throw new NotFound();
        }

        $previousConfig = $entity->get('config');

        // Validate config if it's being updated
        if (!empty($data->credentialTypeId) && !empty($data->config)) {
            $this->validateConfig($data->credentialTypeId, $data->config);
        }

        $entity = parent::update($id, $data, $params);

        // Log update
        $this->logHistory($entity, 'updated', $previousConfig, $entity->get('config'));

        return $entity;
    }

    public function delete(string $id, ?DeleteParams $params = null): void
    {
        $entity = $this->getEntity($id);
        if ($entity) {
            // Log deletion before actual delete
            $this->logHistory($entity, 'deleted', $entity->get('config'), null);
        }

        parent::delete($id, $params);
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
     * For OAuth-backed types, fields listed in tokenFieldMapping are excluded
     * from the required check since they are resolved at runtime from the
     * linked OAuthAccount.
     */
    protected function validateConfig(string $credentialTypeId, $config): void
    {
        $credentialType = $this->entityManager->getEntity('CredentialType', $credentialTypeId);
        if (!$credentialType) {
            throw new Error("Credential type not found");
        }

        $schema = $credentialType->get('schema');
        if (empty($schema)) {
            return;
        }

        $schemaData = json_decode($schema, true);
        if (!$schemaData) {
            return;
        }

        $configData = is_string($config) ? json_decode($config, true) : (array) $config;

        // Determine which fields are provided by OAuth (should be skipped in validation)
        $oAuthProvidedFields = [];
        $mappingRaw = $credentialType->get('tokenFieldMapping');

        if ($mappingRaw) {
            $mapping = is_string($mappingRaw) ? json_decode($mappingRaw, true) : (array) $mappingRaw;

            if (is_array($mapping)) {
                $oAuthProvidedFields = array_keys($mapping);
            }
        }

        // Basic validation - check required fields (skip OAuth-provided ones)
        if (!empty($schemaData['required'])) {
            foreach ($schemaData['required'] as $field) {
                if (in_array($field, $oAuthProvidedFields, true)) {
                    continue;
                }

                if (!isset($configData[$field]) || $configData[$field] === '') {
                    throw new Error("Required field '{$field}' is missing");
                }
            }
        }
    }

    /**
     * Log credential history
     */
    protected function logHistory(
        Entity $credential,
        string $action,
        ?string $previousValue = null,
        ?string $newValue = null,
        ?string $reason = null
    ): void {
        $history = $this->entityManager->getEntity('CredentialHistory');
        $history->set([
            'credentialId' => $credential->getId(),
            'action' => $action,
            'previousValue' => $previousValue,
            'newValue' => $newValue,
            'reason' => $reason,
            'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? null,
            'createdById' => $this->user->getId(),
            'createdAt' => date('Y-m-d H:i:s')
        ]);
        $this->entityManager->saveEntity($history);
    }
}
