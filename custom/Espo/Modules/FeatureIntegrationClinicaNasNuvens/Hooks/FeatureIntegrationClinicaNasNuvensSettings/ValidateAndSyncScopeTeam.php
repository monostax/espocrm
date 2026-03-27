<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Hooks\FeatureIntegrationClinicaNasNuvensSettings;

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ValidateAndSyncScopeTeam
{
    public static int $order = 9;

    /** @var string[] */
    private const API_CREDENTIAL_TYPE_CODES = ['clinicaNasNuvens', 'cnn'];

    private const WEB_CREDENTIAL_TYPE_CODE = 'clinicaNasNuvens-web';

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        $this->syncDefaultScopeTeam($entity);
        $scopeTeamId = $this->assertExactlyOneScopeTeam($entity);
        $this->assertCredentialTypes($entity);
        $this->assertNoOtherActiveProfileForScopeTeam($entity, $scopeTeamId);
        $this->syncDefaultName($entity);
    }

    private function syncDefaultScopeTeam(Entity $entity): void
    {
        $teamsIds = $entity->get('teamsIds');

        if (is_array($teamsIds) && $this->normalizeTeamIds($teamsIds) !== []) {
            return;
        }

        $tenantId = $this->normalizeNullableString($entity->get('tenantId'))
            ?? $this->normalizeNullableString($entity->getFetched('tenantId'));

        if (!$tenantId) {
            return;
        }

        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
        $baseUserTeamId = $this->normalizeNullableString($tenant?->get('baseUserTeamId'));

        if (!$baseUserTeamId) {
            throw new BadRequest('Profile team scope is required and could not be derived from Tenant base team.');
        }

        $entity->set('teamsIds', [$baseUserTeamId]);
    }

    private function assertExactlyOneScopeTeam(Entity $entity): string
    {
        $teamsIds = $this->normalizeTeamIds(is_array($entity->get('teamsIds')) ? $entity->get('teamsIds') : []);

        if (count($teamsIds) !== 1) {
            throw new BadRequest('Each Clinica Nas Nuvens integration profile must have exactly one scope team.');
        }

        $entity->set('teamsIds', $teamsIds);

        return $teamsIds[0];
    }

    private function assertCredentialTypes(Entity $entity): void
    {
        $apiCredentialId = $this->normalizeNullableString($entity->get('apiCredentialId'))
            ?? $this->normalizeNullableString($entity->getFetched('apiCredentialId'));

        $webCredentialId = $this->normalizeNullableString($entity->get('webCredentialId'))
            ?? $this->normalizeNullableString($entity->getFetched('webCredentialId'));

        if (!$apiCredentialId || !$webCredentialId) {
            throw new BadRequest('Both API and Web credentials are required for integration profiles.');
        }

        $apiCredential = $this->entityManager->getEntityById('Credential', $apiCredentialId);
        $webCredential = $this->entityManager->getEntityById('Credential', $webCredentialId);

        if (!$apiCredential || !$webCredential) {
            throw new BadRequest('Both API and Web credentials must exist.');
        }

        $apiCode = $this->resolveCredentialTypeCode($apiCredential);
        $webCode = $this->resolveCredentialTypeCode($webCredential);

        if (!in_array($apiCode, self::API_CREDENTIAL_TYPE_CODES, true)) {
            throw new BadRequest('API credential must use Clinica Nas Nuvens API credential type.');
        }

        if ($webCode !== self::WEB_CREDENTIAL_TYPE_CODE) {
            throw new BadRequest('Web credential must use Clinica Nas Nuvens Web credential type.');
        }
    }

    private function assertNoOtherActiveProfileForScopeTeam(Entity $entity, string $scopeTeamId): void
    {
        $isActive = $entity->has('isActive')
            ? (bool) $entity->get('isActive')
            : (bool) ($entity->getFetched('isActive') ?? true);

        if (!$isActive) {
            return;
        }

        $repository = $this->entityManager->getRDBRepository('FeatureIntegrationClinicaNasNuvensSettings');

        $where = [
            'isActive' => true,
            'teams.id' => $scopeTeamId,
            'deleted' => false,
        ];

        if (!$entity->isNew()) {
            $where['id!='] = $entity->getId();
        }

        $query = $this->entityManager
            ->getQueryBuilder()
            ->select(['id'])
            ->from('FeatureIntegrationClinicaNasNuvensSettings')
            ->join('teams', 'teams')
            ->where($where);

        $existing = $repository->clone($query->build())->findOne();

        if ($existing) {
            throw new BadRequest(
                'There is already an active Clinica Nas Nuvens integration profile for this scope team. '
                . 'Only one active profile per team is allowed.'
            );
        }
    }

    private function syncDefaultName(Entity $entity): void
    {
        $name = $this->normalizeNullableString($entity->get('name'));

        if ($name !== null) {
            return;
        }

        $tenantId = $this->normalizeNullableString($entity->get('tenantId'))
            ?? $this->normalizeNullableString($entity->getFetched('tenantId'));

        $tenantName = null;

        if ($tenantId) {
            $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
            $tenantName = $this->normalizeNullableString($tenant?->get('name'));
        }

        $entity->set('name', $tenantName ? "{$tenantName} - CNN Profile" : 'Clinica Nas Nuvens Profile');
    }

    private function resolveCredentialTypeCode(Entity $credential): string
    {
        $credentialTypeId = $this->normalizeNullableString($credential->get('credentialTypeId'));

        if (!$credentialTypeId) {
            throw new BadRequest('Credential type is missing.');
        }

        $credentialType = $this->entityManager->getEntityById('CredentialType', $credentialTypeId);
        $code = $this->normalizeNullableString($credentialType?->get('code'));

        if (!$code) {
            throw new BadRequest('Credential type code is missing.');
        }

        return $code;
    }

    /**
     * @param array<int, mixed> $teamsIds
     * @return string[]
     */
    private function normalizeTeamIds(array $teamsIds): array
    {
        $normalized = [];

        foreach ($teamsIds as $teamId) {
            $teamId = $this->normalizeNullableString($teamId);

            if ($teamId !== null) {
                $normalized[] = $teamId;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
