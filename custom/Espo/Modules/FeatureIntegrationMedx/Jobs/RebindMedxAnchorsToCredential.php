<?php

namespace Espo\Modules\FeatureIntegrationMedx\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

class RebindMedxAnchorsToCredential implements Job
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $profileId          = $this->requireString($data, 'profileId');
        $oldWebCredentialId = $this->requireString($data, 'oldWebCredentialId');
        $newWebCredentialId = $this->requireString($data, 'newWebCredentialId');

        $profile = $this->entityManager->getEntityById('FeatureIntegrationMedxSettings', $profileId);

        if (!$profile) {
            $this->log->error("RebindMedxAnchorsToCredential: profile '{$profileId}' not found.");

            return;
        }

        try {
            $newCredential = $this->entityManager
                ->getRDBRepository('Credential')
                ->where([
                    'id'       => $newWebCredentialId,
                    'isActive' => true,
                ])
                ->findOne();

            if (!$newCredential) {
                $this->log->error(
                    "RebindMedxAnchorsToCredential: credential '{$newWebCredentialId}' unavailable."
                );

                $profile->set('migrationStatus', 'failed');
                $this->entityManager->saveEntity($profile, [SaveOption::SKIP_HOOKS => true]);

                return;
            }

            $rebindCount = 0;

            $clienteCollection = $this->entityManager
                ->getRDBRepository('FeatureIntegrationMedxCliente')
                ->where([
                    'credentialId' => $oldWebCredentialId,
                    'deleted'      => false,
                ])
                ->find();

            foreach ($clienteCollection as $cliente) {
                $settingsId = $this->normalizeNullableString($cliente->get('settingsId'));

                if ($settingsId !== $profile->getId()) {
                    continue;
                }

                $cliente->set('credentialId', $newWebCredentialId);

                $this->entityManager->saveEntity($cliente, [
                    SaveOption::SILENT      => true,
                    SaveOption::SKIP_HOOKS  => true,
                    SaveOption::IMPORT      => false,
                ]);

                $rebindCount++;
            }

            $profile->set('migrationStatus', 'completed');
            $notes = (string) ($profile->get('migrationReason') ?? '');

            if ($notes !== '') {
                $notes .= "\n";
            }

            $notes .= date('c') . ": Migration completed ({$rebindCount} anchors rebound).";
            $profile->set('migrationReason', $notes);
            $this->entityManager->saveEntity($profile, [SaveOption::SKIP_HOOKS => true]);

            $this->log->info(
                "RebindMedxAnchorsToCredential: completed profile '{$profileId}', {$rebindCount} anchors rebound."
            );
        } catch (Throwable $e) {
            $this->log->error('RebindMedxAnchorsToCredential: ' . $e->getMessage());

            $freshProfile = $this->entityManager
                ->getEntityById('FeatureIntegrationMedxSettings', $profileId);

            if ($freshProfile) {
                $freshProfile->set('migrationStatus', 'failed');
                $notes = (string) ($freshProfile->get('migrationReason') ?? '');

                if ($notes !== '') {
                    $notes .= "\n";
                }

                $notes .= date('c') . ': Migration failed -- ' . $e->getMessage();
                $freshProfile->set('migrationReason', $notes);
                $this->entityManager->saveEntity($freshProfile, [SaveOption::SKIP_HOOKS => true]);
            }

            throw $e;
        }
    }

    private function requireString(Data $data, string $field): string
    {
        $value = $data->get($field);

        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException("Missing required job data field '{$field}'.");
        }

        return trim($value);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
