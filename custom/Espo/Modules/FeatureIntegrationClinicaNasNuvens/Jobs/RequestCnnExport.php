<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Jobs;

use DateInterval;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensIntegrationProfileResolver;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensWebClient;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Parameterized Job that POSTs to CNN exportação/salvar to trigger
 * generation of a new data export for a specific profile.
 *
 * Implements exponential backoff retry via self-rescheduling.
 */
class RequestCnnExport implements Job
{
    /** Backoff delays in seconds: 5m → 15m → 45m → 2h → 6h → 24h */
    private const BACKOFF_DELAYS = [300, 900, 2700, 7200, 21600, 86400];
    private const MAX_ATTEMPTS = 6;

    public function __construct(
        private ClinicaNasNuvensWebClient $webClient,
        private ClinicaNasNuvensIntegrationProfileResolver $profileResolver,
        private JobSchedulerFactory $jobSchedulerFactory,
        private FileManager $fileManager,
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $profileId = $this->requireString($data, 'profileId');
        $attempt = (int) ($data->get('attempt') ?? 1);

        $this->log->info(
            "RequestCnnExport: Starting for profile '{$profileId}', attempt {$attempt}."
        );

        try {
            $resolved = $this->profileResolver->resolveForProfileId($profileId);
        } catch (Throwable $e) {
            $this->log->error(
                "RequestCnnExport: Failed to resolve profile '{$profileId}': " . $e->getMessage()
            );

            return;
        }

        $webCredential = $resolved['webCredential'];
        $profile = $resolved['profile'];

        // Set status to 'requesting'.
        $profile->set('exportStatus', 'requesting');
        $this->entityManager->saveEntity($profile, [SaveOption::SKIP_ALL => true]);

        try {
            $result = $this->webClient->requestNewExport($webCredential);

            if ($result['success']) {
                $this->log->info(
                    "RequestCnnExport: Successfully requested export for profile '{$profileId}'."
                );

                // Update manifest with lastRequestedAt.
                $manifestPath = "data/cnn-exports/{$profileId}/manifest.json";
                $manifest = $this->readManifest($manifestPath);

                if ($manifest === null) {
                    $manifest = [
                        'lastDownloadedAt' => null,
                        'lastRequestedAt' => null,
                        'filesDownloaded' => [],
                        'csvFiles' => [],
                        'profileId' => $profileId,
                    ];
                }

                $manifest['lastRequestedAt'] = date('c');

                $this->fileManager->putContents(
                    $manifestPath,
                    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    LOCK_EX,
                );

                // Leave exportStatus as 'requesting' — the download job will advance it.
                return;
            }

            $this->log->warning(
                "RequestCnnExport: Export request failed for profile '{$profileId}': " .
                "{$result['message']} (HTTP {$result['status']})"
            );

            $this->rescheduleWithBackoff($profileId, $attempt);

        } catch (Throwable $e) {
            $this->log->error(
                "RequestCnnExport: Error for profile '{$profileId}' on attempt {$attempt}: " . $e->getMessage()
            );

            $this->rescheduleWithBackoff($profileId, $attempt);
        }
    }

    private function rescheduleWithBackoff(string $profileId, int $attempt): void
    {
        if ($attempt >= self::MAX_ATTEMPTS) {
            $this->log->error(
                "RequestCnnExport: All " . self::MAX_ATTEMPTS . " attempts exhausted for profile '{$profileId}'. Giving up."
            );

            $freshProfile = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensSettings', $profileId);

            if ($freshProfile) {
                $freshProfile->set('exportStatus', 'failed');
                $this->entityManager->saveEntity($freshProfile, [SaveOption::SKIP_ALL => true]);
            }

            return;
        }

        $delaySeconds = self::BACKOFF_DELAYS[$attempt - 1] ?? self::BACKOFF_DELAYS[count(self::BACKOFF_DELAYS) - 1];

        $this->log->info(
            "RequestCnnExport: Rescheduling profile '{$profileId}' attempt " . ($attempt + 1) .
            " with delay of {$delaySeconds}s."
        );

        $this->jobSchedulerFactory
            ->create()
            ->setClassName(self::class)
            ->setData([
                'profileId' => $profileId,
                'attempt' => $attempt + 1,
            ])
            ->setDelay(new DateInterval('PT' . $delaySeconds . 'S'))
            ->setGroup('cnn-pipeline-' . $profileId)
            ->schedule();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readManifest(string $path): ?array
    {
        if (!$this->fileManager->exists($path)) {
            return null;
        }

        try {
            $contents = $this->fileManager->getContents($path);
        } catch (Throwable $e) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function requireString(Data $data, string $field): string
    {
        $value = $data->get($field);

        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException("Missing required job data field '{$field}'.");
        }

        return trim($value);
    }
}
