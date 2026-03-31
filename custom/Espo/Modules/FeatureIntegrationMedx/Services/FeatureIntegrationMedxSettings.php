<?php

namespace Espo\Modules\FeatureIntegrationMedx\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Job\QueueName;
use Espo\Core\Record\Service as RecordService;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureIntegrationMedx\Jobs\ImportClientesJob;
use Espo\Modules\FeatureIntegrationMedx\Jobs\RebindMedxAnchorsToCredential;
use Espo\ORM\Entity;

/**
 * @extends RecordService<Entity>
 */
class FeatureIntegrationMedxSettings extends RecordService
{
    private const WEB_CREDENTIAL_TYPE_CODE = 'medx-web';

    /**
     * @return array{status: string, profileId: string, message: string}
     */
    public function replaceCredential(
        string $profileId,
        string $newWebCredentialId,
        string $reason = null,
    ): array {
        $profile = $this->entityManager->getEntityById('FeatureIntegrationMedxSettings', $profileId);

        if (!$profile) {
            throw new NotFound('MEDX integration profile not found.');
        }

        $oldWebCredentialId = $this->normalizeNullableString($profile->get('webCredentialId'));

        if (!$oldWebCredentialId) {
            throw new BadRequest('Profile is missing currently linked Web credential.');
        }

        if ($newWebCredentialId === $oldWebCredentialId) {
            throw new BadRequest('New Web credential must differ from current profile Web credential.');
        }

        $migrationStatus = (string) ($profile->get('migrationStatus') ?? 'idle');

        if ($migrationStatus === 'inProgress') {
            throw new BadRequest(
                'Credential migration is already in progress for this profile.'
            );
        }

        $newWebCredential = $this->assertCredentialReadableAndActive($newWebCredentialId);
        $this->assertWebCredentialType($newWebCredential);

        $profile->set('migrationStatus', 'inProgress');
        $profile->set('webCredentialId', $newWebCredentialId);

        if ($reason !== null) {
            $notes = (string) ($profile->get('migrationReason') ?? '');

            if ($notes !== '') {
                $notes .= "\n";
            }

            $notes .= date('c') . ': ' . $reason;
            $profile->set('migrationReason', $notes);
        }

        $this->entityManager->saveEntity($profile);

        $this->getJobSchedulerFactory()
            ->create()
            ->setClassName(RebindMedxAnchorsToCredential::class)
            ->setQueue(QueueName::E0)
            ->setData([
                'profileId' => $profileId,
                'oldWebCredentialId' => $oldWebCredentialId,
                'newWebCredentialId' => $newWebCredentialId,
            ])
            ->setGroup('medx-pipeline-' . $profileId)
            ->schedule();

        return [
            'status'    => 'migration_started',
            'profileId' => $profileId,
            'message'   => 'Credential migration started.',
        ];
    }

    /**
     * Queue the import job for active profiles.
     *
     * @param array{top?: int|null, skip?: int|null} $options
     * @return array{status: string, message: string}
     */
    public function importClientes(string $profileId = null, array $options = []): array
    {
        if ($profileId) {
            $profiles = $this->entityManager
                ->getRDBRepository('FeatureIntegrationMedxSettings')
                ->where([
                    'id'       => $profileId,
                    'isActive' => true,
                ])
                ->find();
        } else {
            $profiles = $this->entityManager
                ->getRDBRepository('FeatureIntegrationMedxSettings')
                ->where([
                    'isActive' => true,
                ])
                ->find();
        }

        if ($profiles->count() === 0) {
            throw new BadRequest(
                $profileId
                    ? "Profile '{$profileId}' not found or is inactive."
                    : 'No active MEDX integration profiles.'
            );
        }

        $importStarted = 0;

        foreach ($profiles as $profile) {
            $importStatus = (string) ($profile->get('importStatus') ?? 'idle');

            if ($importStatus === 'inProgress') {
                continue;
            }

            $jobData = [
                'profileId' => $profile->getId(),
            ];

            if (isset($options['top']) && $options['top'] !== null) {
                $jobData['top'] = (int) $options['top'];
            }

            if (isset($options['skip']) && $options['skip'] !== null) {
                $jobData['skip'] = (int) $options['skip'];
            }

            $this->getJobSchedulerFactory()
                ->create()
                ->setClassName(ImportClientesJob::class)
                ->setData($jobData)
                ->setGroup('medx-pipeline-' . $profile->getId())
                ->schedule();

            $profile->set('importStatus', 'inProgress');
            $this->entityManager->saveEntity($profile, [SaveOption::SKIP_HOOKS => true]);

            $importStarted++;
        }

        if ($importStarted === 0) {
            throw new BadRequest(
                'Import already in progress for the selected profile(s).'
            );
        }

        return [
            'status'  => 'import_started',
            'message' => "Import job queued for {$importStarted} profile(s).",
        ];
    }

    /* ──────────────────────────────────────────────────────────────── */
    /*  Private helpers                                                  */
    /* ──────────────────────────────────────────────────────────────── */

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

    private function assertWebCredentialType(Entity $credential): void
    {
        $credentialTypeCode = $this->resolveCredentialTypeCode($credential);

        if ($credentialTypeCode !== self::WEB_CREDENTIAL_TYPE_CODE) {
            throw new BadRequest(
                'Web credential must use MEDX Web credential type (medx-web).'
            );
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

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function getJobSchedulerFactory(): JobSchedulerFactory
    {
        return $this->injectableFactory->create(JobSchedulerFactory::class);
    }
}
