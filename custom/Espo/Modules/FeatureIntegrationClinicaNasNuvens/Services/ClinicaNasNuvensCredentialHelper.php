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
     * @var string[]|null
     */
    private ?array $credentialTypeIdList = null;

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private ClinicaNasNuvensIntegrationProfileResolver $integrationProfileResolver,
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

    /** @param string[] $teamIdList */
    public function findAccessibleCredentialForTeamIds(array $teamIdList): ?Entity
    {
        $resolved = $this->integrationProfileResolver->resolveForTeamIds($teamIdList);

        if ($resolved === null) {
            return null;
        }

        $credential = $resolved['apiCredential'];
        $credentialTypeId = $credential->get('credentialTypeId');

        if (!is_string($credentialTypeId) || $credentialTypeId === '') {
            throw new BadRequest('Resolved API credential has no type assigned.');
        }

        if (!in_array($credentialTypeId, $this->getCredentialTypeIdList(), true)) {
            throw new BadRequest('Resolved profile API credential has incompatible type.');
        }

        return $credential;
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
