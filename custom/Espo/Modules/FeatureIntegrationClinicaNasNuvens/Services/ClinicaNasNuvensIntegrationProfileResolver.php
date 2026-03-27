<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ClinicaNasNuvensIntegrationProfileResolver
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
    ) {}

    /**
     * @return array{profile: Entity, apiCredential: Entity, webCredential: Entity}
     */
    public function resolveForProfileId(string $profileId): array
    {
        $profileId = trim($profileId);

        if ($profileId === '') {
            throw new BadRequest('Cannot resolve Clinica Nas Nuvens integration profile: empty profile ID.');
        }

        $profile = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensSettings', $profileId);

        if (!$profile) {
            throw new BadRequest("Cannot use settings '{$profileId}': profile not found.");
        }

        if (!$profile->get('isActive')) {
            throw new BadRequest('Resolved profile is inactive.');
        }

        $migrationStatus = (string) ($profile->get('migrationStatus') ?? 'idle');

        if ($migrationStatus === 'inProgress') {
            throw new BadRequest(
                'Clinica Nas Nuvens profile migration is in progress for the scoped team. ' .
                'Write operations are temporarily blocked.'
            );
        }

        if (!$this->acl->check($profile, 'read')) {
            throw new BadRequest('You do not have access to the resolved integration profile.');
        }

        $apiCredentialId = $profile->get('apiCredentialId');
        $webCredentialId = $profile->get('webCredentialId');

        if (!is_string($apiCredentialId) || $apiCredentialId === '') {
            throw new BadRequest('Resolved profile is missing API credential.');
        }

        if (!is_string($webCredentialId) || $webCredentialId === '') {
            throw new BadRequest('Resolved profile is missing Web credential.');
        }

        $apiCredential = $this->entityManager->getEntityById('Credential', $apiCredentialId);
        $webCredential = $this->entityManager->getEntityById('Credential', $webCredentialId);

        if (!$apiCredential || !$apiCredential->get('isActive')) {
            throw new BadRequest('Resolved API credential is unavailable or inactive.');
        }

        if (!$webCredential || !$webCredential->get('isActive')) {
            throw new BadRequest('Resolved Web credential is unavailable or inactive.');
        }

        if (!$this->acl->check($apiCredential, 'read') || !$this->acl->check($webCredential, 'read')) {
            throw new BadRequest('You do not have access to one or more resolved credentials.');
        }

        $profile->set('teamsIds', $this->loadProfileTeamIds($profile));

        return [
            'profile' => $profile,
            'apiCredential' => $apiCredential,
            'webCredential' => $webCredential,
        ];
    }

    /**
     * @param string[] $teamIdList
     * @return array{profile: Entity, apiCredential: Entity, webCredential: Entity}|null
     */
    public function resolveForTeamIds(array $teamIdList): ?array
    {
        $teamIdList = array_values(array_unique(array_filter(
            $teamIdList,
            static fn ($id) => is_string($id) && $id !== ''
        )));

        if ($teamIdList === []) {
            return null;
        }

        $profiles = $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensSettings')
            ->distinct()
            ->join('teams', 'teams')
            ->where([
                'isActive' => true,
                'teams.id' => $teamIdList,
            ])
            ->find();

        $matchingProfileList = [];

        foreach ($profiles as $profile) {
            if (!$this->acl->check($profile, 'read')) {
                continue;
            }

            $matchingProfileList[] = $profile;
        }

        if ($matchingProfileList === []) {
            return null;
        }

        if (count($matchingProfileList) > 1) {
            throw new BadRequest(
                'Multiple active Clinica Nas Nuvens integration profiles match the provided team scope. ' .
                'Expected a single deterministic profile.'
            );
        }

        $profile = $matchingProfileList[0];
        $migrationStatus = (string) ($profile->get('migrationStatus') ?? 'idle');

        if ($migrationStatus === 'inProgress') {
            throw new BadRequest(
                'Clinica Nas Nuvens profile migration is in progress for the scoped team. ' .
                'Write operations are temporarily blocked.'
            );
        }

        $apiCredentialId = $profile->get('apiCredentialId');
        $webCredentialId = $profile->get('webCredentialId');

        if (!is_string($apiCredentialId) || $apiCredentialId === '') {
            throw new BadRequest('Resolved profile is missing API credential.');
        }

        if (!is_string($webCredentialId) || $webCredentialId === '') {
            throw new BadRequest('Resolved profile is missing Web credential.');
        }

        $apiCredential = $this->entityManager->getEntityById('Credential', $apiCredentialId);
        $webCredential = $this->entityManager->getEntityById('Credential', $webCredentialId);

        if (!$apiCredential || !$apiCredential->get('isActive')) {
            throw new BadRequest('Resolved API credential is unavailable or inactive.');
        }

        if (!$webCredential || !$webCredential->get('isActive')) {
            throw new BadRequest('Resolved Web credential is unavailable or inactive.');
        }

        if (!$this->acl->check($apiCredential, 'read') || !$this->acl->check($webCredential, 'read')) {
            throw new BadRequest('You do not have access to one or more resolved credentials.');
        }

        return [
            'profile' => $profile,
            'apiCredential' => $apiCredential,
            'webCredential' => $webCredential,
        ];
    }

    /**
     * @return string[]
     */
    private function loadProfileTeamIds(Entity $profile): array
    {
        $relation = $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensSettings')
            ->getRelation($profile, 'teams');

        $teamIdList = [];

        foreach ($relation->find() as $team) {
            $teamId = $team->getId();

            if (is_string($teamId) && $teamId !== '') {
                $teamIdList[] = $teamId;
            }
        }

        $teamIdList = array_values(array_unique($teamIdList));

        if ($teamIdList === []) {
            throw new BadRequest('Resolved profile is missing scoped team.');
        }

        return $teamIdList;
    }

}
