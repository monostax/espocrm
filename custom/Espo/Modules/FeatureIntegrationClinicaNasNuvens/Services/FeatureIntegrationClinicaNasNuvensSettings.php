<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Job\QueueName;
use Espo\Core\Record\Service as RecordService;
use Espo\Modules\FeatureCredential\Tools\Credential\CredentialResolver;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Jobs\RebindClinicaNasNuvensAnchorsToCredential;
use Espo\ORM\Entity;

/**
 * @extends RecordService<Entity>
 */
class FeatureIntegrationClinicaNasNuvensSettings extends RecordService
{
    /** @var string[] */
    private const API_CREDENTIAL_TYPE_CODES = ['clinicaNasNuvens', 'cnn'];

    private const WEB_CREDENTIAL_TYPE_CODE = 'clinicaNasNuvens-web';

    /**
     * @return array{status: string, profileId: string, message: string}
     */
    public function replaceCredential(
        string $profileId,
        string $newApiCredentialId,
        string $newWebCredentialId,
    ): array {
        if (!$this->acl->checkScope('FeatureIntegrationClinicaNasNuvensSettings', Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to replace Clinica Nas Nuvens profile credentials.");
        }

        $profile = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensSettings', $profileId);

        if (!$profile) {
            throw new NotFound('Integration profile not found.');
        }

        if (!$this->acl->check($profile, Acl\Table::ACTION_EDIT)) {
            throw new Forbidden("You don't have access to edit this integration profile.");
        }

        $oldApiCredentialId = $this->normalizeNullableString($profile->get('apiCredentialId'));
        $oldWebCredentialId = $this->normalizeNullableString($profile->get('webCredentialId'));
        $currentMigrationStatus = (string) ($profile->get('migrationStatus') ?? 'idle');

        if (!$oldApiCredentialId || !$oldWebCredentialId) {
            throw new BadRequest('Profile is missing currently linked API or Web credential.');
        }

        if ($currentMigrationStatus === 'inProgress') {
            throw new BadRequest('This profile is already running a credential migration.');
        }

        if ($newApiCredentialId === $oldApiCredentialId) {
            throw new BadRequest('New API credential must differ from current profile API credential.');
        }

        if ($newWebCredentialId === $oldWebCredentialId) {
            throw new BadRequest('New Web credential must differ from current profile Web credential.');
        }

        $newApiCredential = $this->assertCredentialReadableAndActive($newApiCredentialId);
        $newWebCredential = $this->assertCredentialReadableAndActive($newWebCredentialId);

        $this->assertApiCredentialType($newApiCredential);
        $this->assertWebCredentialType($newWebCredential);
        $this->assertSameClinicByApiCredentials($oldApiCredentialId, $newApiCredentialId);

        $profile->set('migrationStatus', 'inProgress');
        $profile->set('apiCredentialId', $newApiCredentialId);
        $profile->set('webCredentialId', $newWebCredentialId);
        $this->entityManager->saveEntity($profile);

        $this->getJobSchedulerFactory()
            ->create()
            ->setClassName(RebindClinicaNasNuvensAnchorsToCredential::class)
            ->setQueue(QueueName::E0)
            ->setData([
                'profileId' => $profileId,
                'oldApiCredentialId' => $oldApiCredentialId,
                'newApiCredentialId' => $newApiCredentialId,
                'oldWebCredentialId' => $oldWebCredentialId,
                'newWebCredentialId' => $newWebCredentialId,
            ])
            ->schedule();

        return [
            'status' => 'queued',
            'profileId' => $profileId,
            'message' => 'Credential replacement has been queued and write operations are now blocked for this profile scope.',
        ];
    }

    private function assertSameClinicByApiCredentials(string $oldApiCredentialId, string $newApiCredentialId): void
    {
        $oldConfig = $this->getCredentialResolver()->resolve($oldApiCredentialId);
        $newConfig = $this->getCredentialResolver()->resolve($newApiCredentialId);

        $oldClinicCid = $this->normalizeClinicCid((string) (
            $oldConfig->clinicCid ??
            $oldConfig->clinicCID ??
            $oldConfig->cid ??
            $oldConfig->clinicToken ??
            ''
        ));

        $newClinicCid = $this->normalizeClinicCid((string) (
            $newConfig->clinicCid ??
            $newConfig->clinicCID ??
            $newConfig->cid ??
            $newConfig->clinicToken ??
            ''
        ));

        if ($oldClinicCid === '' || $newClinicCid === '') {
            throw new BadRequest('Unable to validate clinic identity. Both API credentials must provide a clinicCid value.');
        }

        if ($oldClinicCid !== $newClinicCid) {
            throw new BadRequest('Credential replacement is only allowed between API credentials from the same clinic.');
        }
    }

    private function assertCredentialReadableAndActive(string $credentialId): Entity
    {
        $credential = $this->entityManager->getEntityById('Credential', $credentialId);

        if (!$credential) {
            throw new NotFound("Credential '{$credentialId}' not found.");
        }

        if (!$this->acl->check($credential, Acl\Table::ACTION_READ)) {
            throw new Forbidden("You don't have access to the selected credential.");
        }

        if (!$credential->get('isActive')) {
            throw new BadRequest("Credential '{$credentialId}' is inactive.");
        }

        return $credential;
    }

    private function assertApiCredentialType(Entity $credential): void
    {
        $credentialTypeCode = $this->resolveCredentialTypeCode($credential);

        if (!in_array($credentialTypeCode, self::API_CREDENTIAL_TYPE_CODES, true)) {
            throw new BadRequest('Invalid API credential type for profile replacement.');
        }
    }

    private function assertWebCredentialType(Entity $credential): void
    {
        $credentialTypeCode = $this->resolveCredentialTypeCode($credential);

        if ($credentialTypeCode !== self::WEB_CREDENTIAL_TYPE_CODE) {
            throw new BadRequest('Invalid Web credential type for profile replacement.');
        }
    }

    private function resolveCredentialTypeCode(Entity $credential): string
    {
        $credentialTypeId = $this->normalizeNullableString($credential->get('credentialTypeId'));

        if (!$credentialTypeId) {
            throw new BadRequest('Credential has no credential type assigned.');
        }

        $credentialType = $this->entityManager->getEntityById('CredentialType', $credentialTypeId);
        $code = $this->normalizeNullableString($credentialType?->get('code'));

        if (!$code) {
            throw new BadRequest('Credential type is missing code metadata.');
        }

        return $code;
    }

    private function normalizeClinicCid(string $clinicCid): string
    {
        $clinicCid = strtolower(trim($clinicCid));

        return preg_replace('/[^a-z0-9]/', '', $clinicCid) ?? '';
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function getCredentialResolver(): CredentialResolver
    {
        return $this->injectableFactory->create(CredentialResolver::class);
    }

    private function getJobSchedulerFactory(): JobSchedulerFactory
    {
        return $this->injectableFactory->create(JobSchedulerFactory::class);
    }
}
