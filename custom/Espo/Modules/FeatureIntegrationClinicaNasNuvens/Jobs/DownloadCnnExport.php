<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Jobs;

use DateInterval;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\File\ZipArchive;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensIntegrationProfileResolver;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensWebClient;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Parameterized Job that downloads CNN export files for a specific profile
 * and extracts CSVs to data/cnn-exports/{profileId}/latest/.
 *
 * Implements exponential backoff retry via self-rescheduling.
 */
class DownloadCnnExport implements Job
{
    /** Backoff delays in seconds: 5m → 15m → 45m → 2h → 6h → 24h */
    private const BACKOFF_DELAYS = [300, 900, 2700, 7200, 21600, 86400];
    private const MAX_ATTEMPTS = 6;

    private const IMPORT_CSV_DATA_CLASS = 'Espo\\Modules\\FeatureIntegrationClinicaNasNuvens\\Jobs\\ImportCsvData';

    private const AUTO_IMPORT_ROLLING_DAYS = [
        'rolling30d' => 30,
        'rolling90d' => 90,
        'rolling365d' => 365,
    ];

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
            "DownloadCnnExport: Starting for profile '{$profileId}', attempt {$attempt}."
        );

        try {
            $resolved = $this->profileResolver->resolveForProfileId($profileId);
        } catch (Throwable $e) {
            $this->log->error(
                "DownloadCnnExport: Failed to resolve profile '{$profileId}': " . $e->getMessage()
            );

            return;
        }

        $webCredential = $resolved['webCredential'];
        $profile = $resolved['profile'];

        // Set status to 'downloading'.
        $profile->set('exportStatus', 'downloading');
        $this->entityManager->saveEntity($profile, [SaveOption::SKIP_ALL => true]);

        try {
            $fileList = $this->webClient->getExportFileList($webCredential);

            if ($fileList === []) {
                $this->log->warning(
                    "DownloadCnnExport: No export files found on lista page for profile '{$profileId}'. " .
                    "Resetting status to idle. Run 'Request Export' first to generate files on CNN."
                );

                $this->resetProfileStatus($profileId, 'idle');

                return;
            }

            // Staleness check: compare most recent dataHoraCriacao against lastRequestedAt in manifest.
            $manifestPath = "data/cnn-exports/{$profileId}/manifest.json";
            $manifest = $this->readManifest($manifestPath);

            if ($manifest !== null && isset($manifest['lastRequestedAt'])) {
                $mostRecentDataHora = $this->getMostRecentDataHoraCriacao($fileList);

                if ($mostRecentDataHora !== null && $manifest['lastRequestedAt'] !== null) {
                    $fileTimestamp = strtotime($mostRecentDataHora);
                    $requestedTimestamp = strtotime($manifest['lastRequestedAt']);

                    if ($fileTimestamp !== false && $requestedTimestamp !== false && $fileTimestamp < $requestedTimestamp) {
                        $this->log->info(
                            "DownloadCnnExport: Export files are stale for profile '{$profileId}' " .
                            "(file: {$mostRecentDataHora}, requested: {$manifest['lastRequestedAt']}). " .
                            "Triggering new export request and rescheduling."
                        );

                        try {
                            $this->webClient->requestNewExport($webCredential);
                        } catch (Throwable $e) {
                            $this->log->warning(
                                "DownloadCnnExport: Failed to trigger new export for profile '{$profileId}': " .
                                $e->getMessage()
                            );
                        }

                        $this->rescheduleWithBackoff($profileId, $attempt, $profile);

                        return;
                    }
                }
            }

            // Download phase: download all zip files to temp location.
            $latestDir = "data/cnn-exports/{$profileId}/latest";
            $tempFiles = [];

            foreach ($fileList as $fileInfo) {
                $codigo = $fileInfo['codigo'];
                $tempPath = "data/tmp/cnn-export-{$profileId}-{$codigo}.zip";
                $tempFiles[] = $tempPath;

                $this->log->info(
                    "DownloadCnnExport: Downloading codArquivo {$codigo} for profile '{$profileId}'."
                );

                $this->webClient->downloadExportFile($webCredential, $codigo, $tempPath);
            }

            // Extract phase: clean latest/ and extract all zips.
            if ($this->fileManager->isDir($latestDir)) {
                $this->fileManager->removeInDir($latestDir);
            } else {
                $this->fileManager->mkdir($latestDir);
            }

            $zipArchive = new ZipArchive($this->fileManager);

            foreach ($tempFiles as $tempPath) {
                $this->log->info(
                    "DownloadCnnExport: Extracting '{$tempPath}' to '{$latestDir}'."
                );

                $success = $zipArchive->unzip($tempPath, $latestDir);

                if (!$success) {
                    throw new \RuntimeException("Failed to extract zip file: {$tempPath}");
                }
            }

            // Clean up temp files.
            foreach ($tempFiles as $tempPath) {
                if ($this->fileManager->exists($tempPath)) {
                    $this->fileManager->unlink($tempPath);
                }
            }

            // Scan extracted CSV files.
            $csvFiles = $this->scanCsvFiles($latestDir);

            // Write manifest.
            $codigos = array_map(fn (array $f) => $f['codigo'], $fileList);

            $newManifest = [
                'lastDownloadedAt' => date('c'),
                'lastRequestedAt' => $manifest['lastRequestedAt'] ?? null,
                'filesDownloaded' => $codigos,
                'csvFiles' => $csvFiles,
                'profileId' => $profileId,
            ];

            $this->fileManager->putContents($manifestPath, json_encode($newManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

            // Set status to 'completed'.
            $profile = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensSettings', $profileId);

            if ($profile) {
                $profile->set('exportStatus', 'completed');
                $this->entityManager->saveEntity($profile, [SaveOption::SKIP_ALL => true]);
            }

            $this->log->info(
                "DownloadCnnExport: Completed for profile '{$profileId}'. " .
                "Downloaded " . count($codigos) . " zips, extracted " . count($csvFiles) . " CSV files."
            );

            // Auto-chain to ImportCsvData if autoImportDateRange is configured.
            $this->maybeQueueImport($profileId, $profile);

        } catch (Throwable $e) {
            $this->log->error(
                "DownloadCnnExport: Error for profile '{$profileId}' on attempt {$attempt}: " . $e->getMessage()
            );

            // Clean up orphaned temp zip files.
            $this->cleanupTempFiles($profileId, $fileList ?? []);

            $this->rescheduleWithBackoff($profileId, $attempt, $profile);
        }
    }

    private function rescheduleWithBackoff(string $profileId, int $attempt, mixed $profile): void
    {
        if ($attempt >= self::MAX_ATTEMPTS) {
            $this->log->error(
                "DownloadCnnExport: All " . self::MAX_ATTEMPTS . " attempts exhausted for profile '{$profileId}'. Giving up."
            );

            // Reload profile to avoid stale entity.
            $freshProfile = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensSettings', $profileId);

            if ($freshProfile) {
                $freshProfile->set('exportStatus', 'failed');
                $this->entityManager->saveEntity($freshProfile, [SaveOption::SKIP_ALL => true]);
            }

            return;
        }

        $delaySeconds = self::BACKOFF_DELAYS[$attempt - 1] ?? self::BACKOFF_DELAYS[count(self::BACKOFF_DELAYS) - 1];

        $this->log->info(
            "DownloadCnnExport: Rescheduling profile '{$profileId}' attempt " . ($attempt + 1) .
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

    private function maybeQueueImport(string $profileId, ?object $profile): void
    {
        if (!$profile) {
            return;
        }

        $autoImportDateRange = $profile->get('autoImportDateRange');

        if (!is_string($autoImportDateRange) || $autoImportDateRange === '' || $autoImportDateRange === 'none') {
            return;
        }

        $rollingDays = self::AUTO_IMPORT_ROLLING_DAYS[$autoImportDateRange] ?? null;

        if ($rollingDays === null) {
            return;
        }

        if (!class_exists(self::IMPORT_CSV_DATA_CLASS)) {
            $this->log->warning(
                "DownloadCnnExport: ImportCsvData class not found. Skipping auto-import for profile '{$profileId}'."
            );

            return;
        }

        $dateTo = date('Y-m-d');
        $dateFrom = date('Y-m-d', strtotime("-{$rollingDays} days"));

        $this->log->info(
            "DownloadCnnExport: Auto-queuing ImportCsvData for profile '{$profileId}' " .
            "with range {$dateFrom} to {$dateTo}."
        );

        $this->jobSchedulerFactory
            ->create()
            ->setClassName(self::IMPORT_CSV_DATA_CLASS)
            ->setData([
                'profileId' => $profileId,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ])
            ->setGroup('cnn-pipeline-' . $profileId)
            ->schedule();
    }

    /**
     * @param array<int, array{codigo: int, dataHoraCriacao: string}> $fileList
     */
    private function getMostRecentDataHoraCriacao(array $fileList): ?string
    {
        $mostRecent = null;

        foreach ($fileList as $fileInfo) {
            $dataHora = $fileInfo['dataHoraCriacao'];

            if ($mostRecent === null || strcmp($dataHora, $mostRecent) > 0) {
                $mostRecent = $dataHora;
            }
        }

        return $mostRecent;
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

    /**
     * @return string[]
     */
    private function scanCsvFiles(string $directory): array
    {
        $files = [];

        if (!$this->fileManager->isDir($directory)) {
            return $files;
        }

        $items = @scandir($directory);

        if (!is_array($items)) {
            return $files;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            if (strtolower(pathinfo($item, PATHINFO_EXTENSION)) === 'csv') {
                $files[] = $item;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param array<int, array{codigo: int, dataHoraCriacao: string}> $fileList
     */
    private function cleanupTempFiles(string $profileId, array $fileList): void
    {
        foreach ($fileList as $fileInfo) {
            $tempPath = "data/tmp/cnn-export-{$profileId}-{$fileInfo['codigo']}.zip";

            if ($this->fileManager->exists($tempPath)) {
                try {
                    $this->fileManager->unlink($tempPath);
                } catch (Throwable $e) {
                    // Ignore cleanup errors.
                }
            }
        }
    }

    private function resetProfileStatus(string $profileId, string $status): void
    {
        $freshProfile = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensSettings', $profileId);

        if ($freshProfile) {
            $freshProfile->set('exportStatus', $status);
            $this->entityManager->saveEntity($freshProfile, [SaveOption::SKIP_ALL => true]);
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
}
