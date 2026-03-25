<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ClinicaNasNuvensWebCredentialHelper
{
    private const CREDENTIAL_TYPE_CODE = 'clinicaNasNuvens-web';

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

        if (!$credentialType || $code !== self::CREDENTIAL_TYPE_CODE) {
            throw new BadRequest(
                'Invalid credential type. Expected: ' . self::CREDENTIAL_TYPE_CODE . '.'
            );
        }

        return $credential;
    }

    /**
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
            ->where(['code' => self::CREDENTIAL_TYPE_CODE])
            ->find();

        $this->credentialTypeIdList = [];

        foreach ($credentialTypeCollection as $credentialType) {
            $this->credentialTypeIdList[] = $credentialType->getId();
        }

        return $this->credentialTypeIdList;
    }
}
