<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ClinicaNasNuvensCredentialHelper
{
    /**
     * Support both canonical and legacy credential type codes.
     *
     * - clinicaNasNuvens: scoped code introduced by this module
     * - cnn: existing code already used in production
     *
     * @var string[]
     */
    private const CREDENTIAL_TYPE_CODE_LIST = ['clinicaNasNuvens', 'cnn'];

    /**
     * @var array<string, Entity|null>
     */
    private array $teamCredentialCache = [];

    /**
     * @var string[]|null
     */
    private ?array $credentialTypeIdList = null;

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
    ) {}

    /**
     * @throws NotFound
     * @throws Forbidden
     * @throws BadRequest
     */
    public function validateCredentialAccess(string $credentialId): Entity
    {
        $credential = $this->entityManager->getEntityById('Credential', $credentialId);

        if (!$credential) {
            throw new NotFound('Credential not found.');
        }

        if (!$this->acl->check($credential, 'read')) {
            throw new Forbidden("You don't have access to use this credential.");
        }

        if (!$credential->get('isActive')) {
            throw new BadRequest('Credential is not active.');
        }

        $credentialTypeId = $credential->get('credentialTypeId');

        if (!$credentialTypeId) {
            throw new BadRequest('Credential has no type assigned.');
        }

        $credentialType = $this->entityManager->getEntityById('CredentialType', $credentialTypeId);

        $code = $credentialType?->get('code');

        if (!$credentialType || !in_array($code, self::CREDENTIAL_TYPE_CODE_LIST, true)) {
            throw new BadRequest(
                'Invalid credential type. Expected one of: ' . implode(', ', self::CREDENTIAL_TYPE_CODE_LIST) . '.'
            );
        }

        return $credential;
    }

    /**
     * @param string[] $credentialIdList
     * @return array<string, Entity>
     */
    public function getAccessibleCredentialMapByIds(array $credentialIdList): array
    {
        if ($credentialIdList === []) {
            return [];
        }

        $credentialTypeCollection = $this->entityManager
            ->getRDBRepository('CredentialType')
            ->where(['code' => self::CREDENTIAL_TYPE_CODE_LIST])
            ->find();

        $credentialTypeIdList = [];

        foreach ($credentialTypeCollection as $credentialType) {
            $credentialTypeIdList[] = $credentialType->getId();
        }

        if ($credentialTypeIdList === []) {
            return [];
        }

        $credentials = $this->entityManager
            ->getRDBRepository('Credential')
            ->where([
                'id' => array_values(array_unique($credentialIdList)),
                'credentialTypeId' => $credentialTypeIdList,
                'isActive' => true,
            ])
            ->find();

        $map = [];

        foreach ($credentials as $credential) {
            if ($this->acl->check($credential, 'read')) {
                $map[$credential->getId()] = $credential;
            }
        }

        return $map;
    }

    /**
     * Resolve the first accessible CNN credential from paciente team membership.
     *
     * Team order is preserved; first team with a valid credential wins.
     *
     * @param string[] $teamIdList
     */
    public function findAccessibleCredentialForTeamIds(array $teamIdList): ?Entity
    {
        foreach ($teamIdList as $teamId) {
            if (!is_string($teamId) || $teamId === '') {
                continue;
            }

            $credential = $this->findAccessibleCredentialForTeamId($teamId);

            if ($credential) {
                return $credential;
            }
        }

        return null;
    }

    private function findAccessibleCredentialForTeamId(string $teamId): ?Entity
    {
        if (array_key_exists($teamId, $this->teamCredentialCache)) {
            return $this->teamCredentialCache[$teamId];
        }

        $credentialTypeIdList = $this->getCredentialTypeIdList();

        if ($credentialTypeIdList === []) {
            $this->teamCredentialCache[$teamId] = null;

            return null;
        }

        $credentials = $this->entityManager
            ->getRDBRepository('Credential')
            ->select(['id', 'name'])
            ->distinct()
            ->join('teams', 'teams')
            ->where([
                'credentialTypeId' => $credentialTypeIdList,
                'isActive' => true,
                'teams.id' => $teamId,
            ])
            ->find();

        foreach ($credentials as $credential) {
            if ($this->acl->check($credential, 'read')) {
                $this->teamCredentialCache[$teamId] = $credential;

                return $credential;
            }
        }

        $this->teamCredentialCache[$teamId] = null;

        return null;
    }

    /**
     * @return string[]
     */
    private function getCredentialTypeIdList(): array
    {
        if ($this->credentialTypeIdList !== null) {
            return $this->credentialTypeIdList;
        }

        $credentialTypeCollection = $this->entityManager
            ->getRDBRepository('CredentialType')
            ->where(['code' => self::CREDENTIAL_TYPE_CODE_LIST])
            ->find();

        $this->credentialTypeIdList = [];

        foreach ($credentialTypeCollection as $credentialType) {
            $this->credentialTypeIdList[] = $credentialType->getId();
        }

        return $this->credentialTypeIdList;
    }
}
