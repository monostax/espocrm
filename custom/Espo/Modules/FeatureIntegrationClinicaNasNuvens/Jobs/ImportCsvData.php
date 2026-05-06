<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * Job that imports CNN CSV export data into MySQL via DuckDB ETL.
 *
 * Pipeline: DuckDB reads raw CSVs → joins/transforms/generates deterministic IDs
 *           → outputs per-entity CSVs → PHP reads output CSVs → batch
 *           INSERT ... ON DUPLICATE KEY UPDATE into MySQL.
 *
 * All entity IDs and FK references are deterministic (derived from
 * credentialId + remoteId via MD5), so the DuckDB output CSVs contain
 * correct FK values that can be inserted directly — no post-ETL fixup needed.
 *
 * Uses setGroup('cnn-pipeline-{profileId}') to serialize with download pipeline.
 */
class ImportCsvData implements Job
{
    private const BATCH_SIZE = 500;

    private const STALE_THRESHOLD_HOURS = 48;

    /**
     * Entity tables and their columns for import (order respects FK dependencies).
     * Key = output CSV filename (without .csv), Value = [table => MySQL table, columns => ordered column list].
     *
     * @var array<string, array{table: string, columns: string[]}>
     */
    private const ENTITY_MAP = [
        'consulta_tipo' => [
            'table' => 'feature_integration_clinica_nas_nuvens_consulta_tipo',
            'columns' => [
                'id', 'name', 'deleted', 'consulta_tipo_id', 'sync_status',
                'ativo', 'reconsulta', 'created_at', 'modified_at',
                'credential_id', 'created_by_id', 'modified_by_id', 'settings_id',
            ],
        ],
        'convenio_tipo' => [
            'table' => 'feature_integration_clinica_nas_nuvens_convenio_tipo',
            'columns' => [
                'id', 'name', 'deleted', 'convenio_tipo_id', 'sync_status',
                'ativo', 'beneficio', 'particular', 'created_at', 'modified_at',
                'credential_id', 'created_by_id', 'modified_by_id', 'settings_id',
            ],
        ],
        'procedimento_tipo' => [
            'table' => 'feature_integration_clinica_nas_nuvens_procedimento_tipo',
            'columns' => [
                'id', 'name', 'deleted', 'procedimento_tipo_id', 'sync_status',
                'ativo', 'especialidades', 'created_at', 'modified_at',
                'credential_id', 'created_by_id', 'modified_by_id', 'settings_id',
            ],
        ],
        'procedimento_convenio' => [
            'table' => 'feature_integration_clinica_nas_nuvens_procedimento_convenio',
            'columns' => [
                'id', 'name', 'deleted', 'codigo_tipo_procedimento_convenio',
                'is_active', 'convenio_name', 'preco_paciente', 'preco_convenio',
                'created_at', 'modified_at', 'preco_paciente_currency', 'preco_convenio_currency',
                'procedimento_tipo_id', 'convenio_tipo_id', 'created_by_id', 'modified_by_id',
            ],
        ],
        'profissional' => [
            'table' => 'feature_integration_clinica_nas_nuvens_profissional',
            'columns' => [
                'id', 'name', 'deleted', 'profissional_id', 'id_pessoa',
                'sync_status', 'ativo', 'tipo_executor', 'cpfcnpj',
                'profissional', 'profissional_codigo', 'cbo', 'registro_profissional',
                'especialidades', 'especialidades_texto', 'clinicas',
                'created_at', 'modified_at', 'credential_id',
                'created_by_id', 'modified_by_id', 'settings_id',
            ],
        ],
        'paciente' => [
            'table' => 'feature_integration_clinica_nas_nuvens_paciente',
            'columns' => [
                'id', 'name', 'deleted', 'paciente_id', 'sync_status',
                'created_at', 'modified_at', 'contact_id', 'credential_id',
                'created_by_id', 'modified_by_id', 'sexo',
                'ativo', 'cpfcnpj', 'data_nascimento',
                'nome_mae', 'nome_pai', 'estado_civil', 'profissao',
                'endereco', 'numero', 'complemento', 'bairro', 'cidade',
                'estado', 'cep', 'observacao', 'convenio', 'numero_convenio',
                'validade_convenio', 'settings_id',
            ],
        ],
        'agendamento' => [
            'table' => 'feature_integration_clinica_nas_nuvens_agendamento',
            'columns' => [
                'id', 'name', 'deleted', 'agendamento_id', 'sync_status',
                'id_paciente', 'id_profissional', 'id_convenio', 'id_especialidade',
                'id_unidade', 'id_sala', 'data', 'hora_inicio', 'hora_fim',
                'status', 'tipo_atendimento', 'profissional', 'convenio',
                'especialidade', 'sala', 'unidade', 'observacao', 'procedimentos',
                'created_at', 'modified_at', 'paciente_id', 'credential_id',
                'created_by_id', 'modified_by_id',
                'status_faturamento', 'id_pessoa_executor', 'profissional_anchor_id',
                'id_tipo_convenio', 'convenio_tipo_anchor_id',
                'valor_procedimentos', 'valor_procedimentos_currency',
                'valor_faturamentos', 'valor_faturamentos_currency',
                'valor_financeiro', 'valor_financeiro_currency',
                'id_tipo_consulta', 'consulta_tipo_anchor_id', 'settings_id',
            ],
        ],
        'agendamento_procedimento' => [
            'table' => 'feature_integration_clinica_nas_nuvens_agendamento_procedimento',
            'columns' => [
                'id', 'name', 'deleted', 'quantidade', 'procedimento_nome',
                'created_at', 'modified_at', 'agendamento_id', 'procedimento_tipo_id',
                'created_by_id', 'modified_by_id', 'preco_paciente', 'preco_convenio',
                'valor_total', 'preco_paciente_currency', 'preco_convenio_currency',
                'valor_total_currency',
            ],
        ],
        'faturamento' => [
            'table' => 'feature_integration_clinica_nas_nuvens_faturamento',
            'columns' => [
                'id', 'name', 'deleted', 'faturamento_id', 'sync_status',
                'documento', 'data_faturamento', 'profissional_nome', 'conta',
                'valor', 'parcela', 'data_vencimento', 'description',
                'created_at', 'modified_at', 'valor_currency', 'agendamento_id',
                'credential_id', 'created_by_id', 'modified_by_id',
                'paciente_id', 'profissional_anchor_id', 'settings_id',
            ],
        ],
    ];

    /**
     * Columns to update on duplicate key.
     * Includes 'id' so deterministic IDs replace time-based IDs on re-import.
     * Also includes FK columns so child references stay consistent, and
     * 'deleted' so a record that was soft-deleted in the CRM is resurrected
     * on the next import (the CSV always emits deleted=0 for live rows).
     *
     * @var array<string, string[]>
     */
    private const UPDATE_COLUMNS = [
        'consulta_tipo' => [
            'id', 'name', 'sync_status', 'ativo', 'reconsulta', 'modified_at', 'settings_id', 'deleted',
        ],
        'convenio_tipo' => [
            'id', 'name', 'sync_status', 'ativo', 'beneficio', 'particular', 'modified_at', 'settings_id', 'deleted',
        ],
        'procedimento_tipo' => [
            'id', 'name', 'sync_status', 'ativo', 'especialidades', 'modified_at', 'settings_id', 'deleted',
        ],
        'procedimento_convenio' => [
            'id', 'name', 'is_active', 'convenio_name', 'preco_paciente', 'preco_convenio',
            'modified_at', 'preco_paciente_currency', 'preco_convenio_currency',
            'procedimento_tipo_id', 'convenio_tipo_id', 'deleted',
        ],
        'profissional' => [
            'id', 'name', 'id_pessoa', 'sync_status', 'ativo', 'tipo_executor', 'cpfcnpj',
            'profissional', 'profissional_codigo', 'cbo', 'registro_profissional',
            'especialidades', 'especialidades_texto', 'clinicas', 'modified_at', 'settings_id', 'deleted',
        ],
        'paciente' => [
            'id', 'name', 'sync_status', 'ativo', 'cpfcnpj', 'data_nascimento',
            'sexo', 'estado_civil', 'profissao', 'endereco', 'numero',
            'complemento', 'bairro', 'cidade', 'estado', 'cep', 'convenio',
            'numero_convenio', 'validade_convenio', 'modified_at', 'settings_id', 'deleted',
        ],
        'agendamento' => [
            'id', 'name', 'sync_status', 'id_paciente', 'id_profissional', 'id_convenio',
            'id_especialidade', 'id_unidade', 'id_sala', 'data', 'hora_inicio', 'hora_fim',
            'status', 'profissional', 'convenio', 'especialidade', 'sala', 'observacao',
            'paciente_id', 'status_faturamento', 'id_pessoa_executor', 'profissional_anchor_id',
            'id_tipo_convenio', 'convenio_tipo_anchor_id', 'id_tipo_consulta',
            'consulta_tipo_anchor_id', 'modified_at', 'settings_id',
            'valor_procedimentos', 'valor_procedimentos_currency',
            'valor_faturamentos', 'valor_faturamentos_currency',
            'valor_financeiro', 'valor_financeiro_currency', 'deleted',
        ],
        'agendamento_procedimento' => [
            'id', 'name', 'quantidade', 'procedimento_nome', 'preco_paciente', 'preco_convenio',
            'valor_total', 'modified_at', 'preco_paciente_currency', 'preco_convenio_currency',
            'valor_total_currency', 'agendamento_id', 'procedimento_tipo_id', 'deleted',
        ],
        'faturamento' => [
            'id', 'name', 'sync_status', 'documento', 'data_faturamento', 'profissional_nome',
            'valor', 'parcela', 'data_vencimento', 'description', 'valor_currency',
            'agendamento_id', 'paciente_id', 'profissional_anchor_id', 'modified_at', 'settings_id', 'deleted',
        ],
    ];

    public function __construct(
        private EntityManager $entityManager,
        private FileManager $fileManager,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $profileId = $this->requireString($data, 'profileId');
        $dateFrom = $this->requireString($data, 'dateFrom');
        $dateTo = $this->requireString($data, 'dateTo');

        $this->log->info(
            "ImportCsvData: Starting for profile '{$profileId}', range {$dateFrom} to {$dateTo}."
        );

        $profile = $this->entityManager->getEntityById(
            'FeatureIntegrationClinicaNasNuvensSettings',
            $profileId
        );

        if (!$profile) {
            $this->log->error("ImportCsvData: Profile '{$profileId}' not found.");

            return;
        }

        // Set import status to inProgress.
        $profile->set('importStatus', 'inProgress');
        $this->entityManager->saveEntity($profile, [SaveOption::SKIP_ALL => true]);

        try {
            // Resolve profile credentials and team.
            $resolved = $this->resolveProfile($profileId);

            // Freshness guard: check manifest.
            $this->checkFreshness($profileId);

            // Verify CSV input directory exists and has files.
            $csvInputPath = "data/cnn-exports/{$profileId}/latest";

            if (!$this->fileManager->isDir($csvInputPath)) {
                throw new \RuntimeException(
                    "CSV input directory not found: {$csvInputPath}. Run the download pipeline first."
                );
            }

            // Execute DuckDB ETL.
            $csvOutputPath = $this->executeDuckDbEtl(
                $csvInputPath,
                $profileId,
                $dateFrom,
                $dateTo,
                $resolved['apiCredentialId'],
                $resolved['webCredentialId'],
                $resolved['settingsId'],
                $resolved['teamId'],
            );

            // Import all entity CSVs into MySQL.
            // DuckDB generates deterministic IDs, so all FK references in the
            // output CSVs are already correct — no post-ETL fixup needed.
            // We import in ENTITY_MAP order which respects FK dependencies.
            $totalRows = 0;
            $pdo = $this->getPdo();

            foreach (self::ENTITY_MAP as $entityKey => $entityDef) {
                $csvFile = $csvOutputPath . '/' . $entityKey . '.csv';

                if (!file_exists($csvFile)) {
                    $this->log->warning("ImportCsvData: Output CSV not found: {$csvFile}. Skipping {$entityKey}.");

                    continue;
                }

                $rowCount = $this->importEntityCsv(
                    $pdo,
                    $csvFile,
                    $entityDef['table'],
                    $entityDef['columns'],
                    self::UPDATE_COLUMNS[$entityKey] ?? [],
                );

                $this->log->info(
                    "ImportCsvData: Imported {$rowCount} rows into {$entityDef['table']}."
                );

                $totalRows += $rowCount;
            }

            // Import entity_team separately (BIGINT auto-increment id).
            $entityTeamCsv = $csvOutputPath . '/entity_team.csv';

            if (file_exists($entityTeamCsv)) {
                $teamRows = $this->importEntityTeamCsv($pdo, $entityTeamCsv);
                $this->log->info("ImportCsvData: Imported {$teamRows} entity_team rows.");
                $totalRows += $teamRows;
            }

            // Repair any contacts with broken tenant_id from prior emergency fixes.
            $this->repairBrokenTenantContacts($pdo, $resolved['apiCredentialId'], $resolved['teamId'], $resolved['tenantId']);

            // Bulk create Contact entities and link phone/email via direct SQL.
            // Replaces the slow ORM-based one-by-one approach that triggered
            // PhoneNumber\Saver / EmailAddress\Saver hooks per entity.
            $contactCsv = $csvOutputPath . '/paciente_contact.csv';

            $this->bulkCreateContactsAndLink(
                $pdo,
                $contactCsv,
                $resolved['apiCredentialId'],
                $resolved['teamId'],
                $resolved['tenantId'],
            );

            // Cleanup output directory.
            $this->cleanupOutputDir($csvOutputPath);

            // Mark import as completed.
            $profile = $this->entityManager->getEntityById(
                'FeatureIntegrationClinicaNasNuvensSettings',
                $profileId
            );

            if ($profile) {
                $profile->set('importStatus', 'completed');
                $this->entityManager->saveEntity($profile, [SaveOption::SKIP_ALL => true]);
            }

            $this->log->info(
                "ImportCsvData: Completed for profile '{$profileId}'. Total rows: {$totalRows}."
            );
        } catch (Throwable $e) {
            $this->log->error(
                "ImportCsvData: Failed for profile '{$profileId}': " . $e->getMessage()
            );

            // Mark import as failed.
            $freshProfile = $this->entityManager->getEntityById(
                'FeatureIntegrationClinicaNasNuvensSettings',
                $profileId
            );

            if ($freshProfile) {
                $freshProfile->set('importStatus', 'failed');
                $this->entityManager->saveEntity($freshProfile, [SaveOption::SKIP_ALL => true]);
            }

            throw $e;
        }
    }

    /**
     * @return array{apiCredentialId: string, webCredentialId: string, settingsId: string, teamId: string, tenantId: string}
     */
    private function resolveProfile(string $profileId): array
    {
        $profile = $this->entityManager->getEntityById(
            'FeatureIntegrationClinicaNasNuvensSettings',
            $profileId
        );

        if (!$profile) {
            throw new \RuntimeException("Profile '{$profileId}' not found.");
        }

        $apiCredentialId = $this->normalizeNullableString($profile->get('apiCredentialId'));
        $webCredentialId = $this->normalizeNullableString($profile->get('webCredentialId'));

        if (!$apiCredentialId || !$webCredentialId) {
            throw new \RuntimeException("Profile '{$profileId}' is missing API or Web credential.");
        }

        $tenantId = $this->normalizeNullableString($profile->get('tenantId'));

        if (!$tenantId) {
            throw new \RuntimeException("Profile '{$profileId}' has no tenant assigned.");
        }

        // Get team ID from the profile's teams link.
        $teamIds = $profile->get('teamsIds');
        $teamId = null;

        if (is_array($teamIds) && count($teamIds) > 0) {
            $teamId = $teamIds[0];
        }

        if (!$teamId) {
            // Try loading teams via the entity manager.
            $teams = $this->entityManager
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensSettings')
                ->getRelation($profile, 'teams')
                ->find();

            foreach ($teams as $team) {
                $teamId = $team->getId();

                break;
            }
        }

        if (!$teamId) {
            throw new \RuntimeException("Profile '{$profileId}' has no team assigned.");
        }

        return [
            'apiCredentialId' => $apiCredentialId,
            'webCredentialId' => $webCredentialId,
            'settingsId' => $profileId,
            'teamId' => $teamId,
            'tenantId' => $tenantId,
        ];
    }

    private function checkFreshness(string $profileId): void
    {
        $manifestPath = "data/cnn-exports/{$profileId}/manifest.json";

        if (!$this->fileManager->exists($manifestPath)) {
            throw new \RuntimeException(
                "Manifest not found at {$manifestPath}. Run the download pipeline first."
            );
        }

        try {
            $contents = $this->fileManager->getContents($manifestPath);
        } catch (Throwable $e) {
            throw new \RuntimeException("Failed to read manifest: " . $e->getMessage());
        }

        $manifest = json_decode($contents, true);

        if (!is_array($manifest)) {
            throw new \RuntimeException("Invalid manifest JSON at {$manifestPath}.");
        }

        $lastDownloadedAt = $manifest['lastDownloadedAt'] ?? null;

        if (!$lastDownloadedAt) {
            $this->log->warning("ImportCsvData: Manifest has no lastDownloadedAt for profile '{$profileId}'.");

            return;
        }

        $downloadTimestamp = strtotime($lastDownloadedAt);

        if ($downloadTimestamp === false) {
            return;
        }

        $hoursAgo = (time() - $downloadTimestamp) / 3600;

        if ($hoursAgo > self::STALE_THRESHOLD_HOURS) {
            $this->log->warning(
                "ImportCsvData: CSV data for profile '{$profileId}' is " . round($hoursAgo, 1) . "h old " .
                "(threshold: " . self::STALE_THRESHOLD_HOURS . "h). Proceeding anyway."
            );
        }
    }

    private function executeDuckDbEtl(
        string $csvInputPath,
        string $profileId,
        string $dateFrom,
        string $dateTo,
        string $credentialId,
        string $webCredentialId,
        string $settingsId,
        string $teamId,
    ): string {
        $csvOutputPath = "data/tmp/cnn-import-{$profileId}";

        // Ensure output directory exists.
        if (!$this->fileManager->isDir($csvOutputPath)) {
            $this->fileManager->mkdir($csvOutputPath);
        }

        // Build the DuckDB SQL script path.
        $sqlScriptPath = 'custom/Espo/Modules/FeatureIntegrationClinicaNasNuvens/Resources/sql/import-csv-etl.sql';

        if (!file_exists($sqlScriptPath)) {
            throw new \RuntimeException("DuckDB ETL script not found: {$sqlScriptPath}");
        }

        // Resolve absolute paths for DuckDB.
        $absCsvInputPath = realpath($csvInputPath);
        $absCsvOutputPath = realpath($csvOutputPath);

        if (!$absCsvInputPath) {
            throw new \RuntimeException("Cannot resolve absolute path for: {$csvInputPath}");
        }

        if (!$absCsvOutputPath) {
            throw new \RuntimeException("Cannot resolve absolute path for: {$csvOutputPath}");
        }

        // Sanitize critical CSVs before calling DuckDB.
        $criticalCsvs = ['AGENDA.csv', 'PACIENTE.csv', 'FATURAMENTO.csv'];

        foreach ($criticalCsvs as $csv) {
            $filePath = $absCsvInputPath . '/' . $csv;

            if (file_exists($filePath)) {
                $this->sanitizeCsv($filePath);
            }
        }

        $absSqlScriptPath = realpath($sqlScriptPath);

        if (!$absSqlScriptPath) {
            throw new \RuntimeException("Cannot resolve absolute path for: {$sqlScriptPath}");
        }

        // Read SQL script and substitute the output path placeholder.
        // DuckDB v1.2.2 does not support expressions in COPY TO path,
        // so we replace __CSV_OUTPUT_PATH__ with the literal absolute path.
        $sqlScript = file_get_contents($absSqlScriptPath);

        if ($sqlScript === false) {
            throw new \RuntimeException("Failed to read SQL script: {$absSqlScriptPath}");
        }

        $sqlScript = str_replace('__CSV_OUTPUT_PATH__', $this->escapeSqlString($absCsvOutputPath), $sqlScript);

        // Build DuckDB command: SET VARIABLEs first, then the full SQL via stdin.
        $setVars = implode(' ', [
            sprintf("SET VARIABLE csvInputPath = '%s';", $this->escapeSqlString($absCsvInputPath)),
            sprintf("SET VARIABLE csvOutputPath = '%s';", $this->escapeSqlString($absCsvOutputPath)),
            sprintf("SET VARIABLE dateFrom = '%s';", $this->escapeSqlString($dateFrom)),
            sprintf("SET VARIABLE dateTo = '%s';", $this->escapeSqlString($dateTo)),
            sprintf("SET VARIABLE credentialId = '%s';", $this->escapeSqlString($credentialId)),
            sprintf("SET VARIABLE webCredentialId = '%s';", $this->escapeSqlString($webCredentialId)),
            sprintf("SET VARIABLE settingsId = '%s';", $this->escapeSqlString($settingsId)),
            sprintf("SET VARIABLE teamId = '%s';", $this->escapeSqlString($teamId)),
        ]);

        // Feed SET VARIABLEs + SQL script via stdin to DuckDB.
        $fullSql = $setVars . "\n" . $sqlScript;

        $command = 'duckdb';

        // Prefer absolute path if available (common in k8s/pods).
        if (file_exists('/usr/local/bin/duckdb')) {
            $command = '/usr/local/bin/duckdb';
        }

        $this->log->info("ImportCsvData: Executing DuckDB ETL for profile '{$profileId}'.");

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to start DuckDB process.");
        }

        fwrite($pipes[0], $fullSql);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->log->error("ImportCsvData: DuckDB ETL failed (exit {$exitCode}). stderr: {$stderr}");

            throw new \RuntimeException(
                "DuckDB ETL failed with exit code {$exitCode}: " . trim($stderr)
            );
        }

        $this->log->info("ImportCsvData: DuckDB ETL completed. stdout: " . trim($stdout));

        return $csvOutputPath;
    }

    /**
     * Repair contacts that were created with a broken tenant_id (e.g. '{}') by an
     * earlier emergency SQL fix. This method:
     *  1. NULLs CPFs on broken-tenant contacts that conflict with correct-tenant contacts
     *  2. Updates tenant_id to the correct value
     *  3. Inserts missing entity_team rows
     *
     * Safe to run on every import — no-ops if nothing is broken.
     */
    private function repairBrokenTenantContacts(
        PDO $pdo,
        string $credentialId,
        string $teamId,
        string $tenantId,
    ): void {
        // Find contacts linked to this credential's pacientes that have wrong tenant_id.
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM contact c
            JOIN `feature_integration_clinica_nas_nuvens_paciente` p ON p.contact_id = c.id
            WHERE p.credential_id = ?
              AND c.tenant_id != ?
              AND c.deleted = 0
        ");
        $stmt->execute([$credentialId, $tenantId]);
        $brokenCount = (int) $stmt->fetchColumn();

        if ($brokenCount === 0) {
            return;
        }

        $this->log->info("ImportCsvData: Repairing {$brokenCount} contacts with wrong tenant_id.");

        // Phase 1: NULL out CPFs that would conflict with the UNIQUE index (cpf, tenant_id, deleted).
        $pdo->exec("
            UPDATE `contact` c1
            SET c1.`cpf` = NULL
            WHERE c1.`tenant_id` != '{$this->escapeSqlString($tenantId)}'
              AND c1.`deleted` = 0
              AND c1.`cpf` IS NOT NULL
              AND c1.`cpf` != ''
              AND c1.`id` IN (
                  SELECT p.`contact_id` FROM `feature_integration_clinica_nas_nuvens_paciente` p
                  WHERE p.`credential_id` = '{$this->escapeSqlString($credentialId)}'
                    AND p.`contact_id` IS NOT NULL
              )
              AND EXISTS (
                  SELECT 1 FROM `contact` c2
                  WHERE c2.`cpf` = c1.`cpf`
                    AND c2.`tenant_id` = '{$this->escapeSqlString($tenantId)}'
                    AND c2.`deleted` = 0
                    AND c2.`id` != c1.`id`
              )
        ");

        // Phase 2: Fix tenant_id.
        $stmt = $pdo->prepare("
            UPDATE `contact` c
            JOIN `feature_integration_clinica_nas_nuvens_paciente` p ON p.contact_id = c.id
            SET c.`tenant_id` = ?
            WHERE p.`credential_id` = ?
              AND c.`tenant_id` != ?
              AND c.`deleted` = 0
        ");
        $stmt->execute([$tenantId, $credentialId, $tenantId]);
        $fixed = $stmt->rowCount();

        // Phase 3: Insert missing entity_team rows.
        $pdo->exec("
            INSERT IGNORE INTO `entity_team` (`entity_id`, `team_id`, `entity_type`, `deleted`)
            SELECT c.`id`, '{$this->escapeSqlString($teamId)}', 'Contact', 0
            FROM `contact` c
            JOIN `feature_integration_clinica_nas_nuvens_paciente` p ON p.`contact_id` = c.`id`
            LEFT JOIN `entity_team` et ON et.`entity_id` = c.`id` AND et.`entity_type` = 'Contact'
            WHERE p.`credential_id` = '{$this->escapeSqlString($credentialId)}'
              AND c.`tenant_id` = '{$this->escapeSqlString($tenantId)}'
              AND et.`entity_id` IS NULL
        ");

        $this->log->info("ImportCsvData: Repaired {$fixed} contacts — tenant_id fixed and entity_team rows added.");
    }

    /**
     * Delete orphan entity_phone_number / entity_email_address rows that point
     * at the *ghost* contact id produced by the previous (buggy) hash formula
     * for this credential's pacientes.
     *
     * Old (buggy) formula: substr(md5('contact::{credentialId}::{paciente.paciente_id}'), 1, 17)
     *                      where paciente_id is the CNN codpessoa.
     * New (correct)      : substr(md5('contact::{credentialId}::{paciente.id}'), 1, 17)
     *                      where id is the deterministic Espo id.
     *
     * Anything matching the old formula is, by construction, an orphan because
     * no contact row was ever inserted with that id. Replaying the formula on
     * paciente rows for this credential lets us delete only the bad rows we
     * created — never anyone else's data.
     */
    private function cleanupGhostContactLinks(PDO $pdo, string $credentialId): void
    {
        $stmt = $pdo->prepare("
            DELETE epn FROM `entity_phone_number` epn
            JOIN `feature_integration_clinica_nas_nuvens_paciente` p
              ON epn.`entity_id` = SUBSTR(MD5(CONCAT('contact::', p.`credential_id`, '::', p.`paciente_id`)), 1, 17)
            WHERE p.`credential_id` = ?
              AND epn.`entity_type` = 'Contact'
        ");
        $stmt->execute([$credentialId]);
        $phoneOrphans = $stmt->rowCount();

        $stmt = $pdo->prepare("
            DELETE eea FROM `entity_email_address` eea
            JOIN `feature_integration_clinica_nas_nuvens_paciente` p
              ON eea.`entity_id` = SUBSTR(MD5(CONCAT('contact::', p.`credential_id`, '::', p.`paciente_id`)), 1, 17)
            WHERE p.`credential_id` = ?
              AND eea.`entity_type` = 'Contact'
        ");
        $stmt->execute([$credentialId]);
        $emailOrphans = $stmt->rowCount();

        if ($phoneOrphans > 0 || $emailOrphans > 0) {
            $this->log->info(
                "ImportCsvData: Phase 0 — Removed {$phoneOrphans} ghost phone links " .
                "and {$emailOrphans} ghost email links left by previous import."
            );
        }
    }

    /**
     * Bulk create Contact entities and link phone/email via direct SQL.
     *
     * Replaces the ORM-based one-by-one approach (importPacienteContactViaOrm +
     * createContactsForUnlinkedPacientes) that triggered PhoneNumber\Saver /
     * EmailAddress\Saver hooks per entity save — taking 25+ minutes for 45K rows.
     *
     * This method runs 4 SQL-only phases in seconds:
     *  1. UPSERT contacts for all pacientes (deterministic IDs, updates name on re-import)
     *  2. UPDATE paciente.contact_id to link the new contacts
     *  3. INSERT phone_number + entity_phone_number from paciente_contact.csv
     *  4. INSERT email_address + entity_email_address from paciente_contact.csv
     */
    private function bulkCreateContactsAndLink(
        PDO $pdo,
        string $contactCsvPath,
        string $credentialId,
        string $teamId,
        string $tenantId,
    ): void {
        $now = date('Y-m-d H:i:s');

        // ── Phase 0: Clean up orphan entity_phone_number / entity_email_address rows ──
        // Older builds of this job hashed the CNN remote codpessoa (the value in
        // paciente.paciente_id) instead of the Espo paciente id when computing the
        // contact_id for phone/email links — producing rows pointing at a
        // contact that never existed. The cleanup is scoped to this credential's
        // pacientes by replaying the exact ghost-id formula, so it cannot
        // touch unrelated entity_phone_number / entity_email_address rows.
        $this->cleanupGhostContactLinks($pdo, $credentialId);

        // ── Phase 1: Bulk UPSERT contacts for all pacientes ──
        // Deterministic ID: substr(md5('contact::' || credentialId || '::' || pacienteId), 1, 17)
        // Matches the formula used by DuckDB ETL for paciente IDs (same seed).
        // ON DUPLICATE KEY UPDATE refreshes first_name/last_name on re-import.
        $this->log->info("ImportCsvData: Phase 1 — Bulk upserting contacts.");

        $stmt1 = $pdo->prepare("
            INSERT INTO `contact`
                (`id`, `first_name`, `last_name`, `cpf`, `tenant_id`, `created_at`, `modified_at`, `deleted`)
            SELECT
                SUBSTR(MD5(CONCAT('contact::', p.`credential_id`, '::', p.`id`)), 1, 17),
                SUBSTRING_INDEX(p.`name`, ' ', 1),
                CASE
                    WHEN LOCATE(' ', p.`name`) > 0
                    THEN TRIM(SUBSTRING(p.`name`, LOCATE(' ', p.`name`)))
                    ELSE ''
                END,
                NULLIF(TRIM(p.`cpfcnpj`), ''),
                ?,
                ?,
                ?,
                0
            FROM `feature_integration_clinica_nas_nuvens_paciente` p
            WHERE p.`credential_id` = ?
              AND p.`name` IS NOT NULL
              AND p.`name` != ''
            ON DUPLICATE KEY UPDATE
                `first_name`  = VALUES(`first_name`),
                `last_name`   = VALUES(`last_name`),
                `modified_at` = VALUES(`modified_at`),
                `deleted`     = VALUES(`deleted`)
        ");
        $stmt1->execute([$tenantId, $now, $now, $credentialId]);
        $contactsAffected = $stmt1->rowCount();
        $this->log->info("ImportCsvData: Phase 1 — Upserted {$contactsAffected} contacts.");

        // ── Phase 2: Bulk UPDATE paciente.contact_id ──
        $this->log->info("ImportCsvData: Phase 2 — Linking contact_id on pacientes.");

        $stmt2 = $pdo->prepare("
            UPDATE `feature_integration_clinica_nas_nuvens_paciente` p
            SET p.`contact_id` = SUBSTR(MD5(CONCAT('contact::', p.`credential_id`, '::', p.`id`)), 1, 17)
            WHERE p.`contact_id` IS NULL
              AND p.`credential_id` = ?
        ");
        $stmt2->execute([$credentialId]);
        $linked = $stmt2->rowCount();

        $this->log->info("ImportCsvData: Phase 2 — Linked {$linked} pacientes.");

        // ── Phase 3 & 4: Bulk insert phone/email from paciente_contact.csv ──
        if (!file_exists($contactCsvPath)) {
            $this->log->info("ImportCsvData: No paciente_contact.csv found — skipping phone/email.");

            return;
        }

        $handle = fopen($contactCsvPath, 'r');

        if (!$handle) {
            $this->log->warning("ImportCsvData: Cannot open paciente_contact.csv: {$contactCsvPath}");

            return;
        }

        // Read header: contact_id, phoneNumber, emailAddress
        $header = fgetcsv($handle);

        if (!$header) {
            fclose($handle);

            return;
        }

        $phoneBatch = [];
        $emailBatch = [];
        $phonesInserted = 0;
        $emailsInserted = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== 3) {
                continue;
            }

            // Column 0 is the deterministic Espo Contact id, computed in
            // the DuckDB ETL with the SAME formula used by the Phase 1
            // contact upsert. We trust the CSV value directly so phone /
            // email links never drift away from the actual contact row.
            $contactId = trim((string) ($row[0] ?? ''));
            $rawPhone = $this->normalizePhoneNumber($row[1] ?? '');
            $rawEmail = trim((string) ($row[2] ?? ''));

            if ($contactId === '') {
                continue;
            }

            if ($rawPhone !== null && $rawPhone !== '') {
                // Deterministic phone_number ID from normalized number.
                $phoneId = substr(md5('pn::' . $rawPhone), 0, 17);
                $numeric = preg_replace('/\D+/', '', $rawPhone);

                $phoneBatch[] = [$phoneId, $rawPhone, $numeric, $contactId];
            }

            if ($rawEmail !== '' && $rawEmail !== 'NULL') {
                $emailId = substr(md5('ea::' . strtolower($rawEmail)), 0, 17);

                $emailBatch[] = [$emailId, $rawEmail, strtolower($rawEmail), $contactId];
            }

            // Flush batches.
            if (count($phoneBatch) >= self::BATCH_SIZE) {
                $phonesInserted += $this->executeBatchPhoneInsert($pdo, $phoneBatch);
                $phoneBatch = [];
            }

            if (count($emailBatch) >= self::BATCH_SIZE) {
                $emailsInserted += $this->executeBatchEmailInsert($pdo, $emailBatch);
                $emailBatch = [];
            }
        }

        fclose($handle);

        // Flush remaining.
        if ($phoneBatch !== []) {
            $phonesInserted += $this->executeBatchPhoneInsert($pdo, $phoneBatch);
        }

        if ($emailBatch !== []) {
            $emailsInserted += $this->executeBatchEmailInsert($pdo, $emailBatch);
        }

        $this->log->info(
            "ImportCsvData: Phase 3 — Inserted {$phonesInserted} phone numbers."
        );
        $this->log->info(
            "ImportCsvData: Phase 4 — Inserted {$emailsInserted} email addresses."
        );
    }

    /**
     * Batch INSERT phone_number + entity_phone_number rows.
     *
     * @param array<int, array{0: string, 1: string, 2: string, 3: string}> $batch
     *                 [phoneId, name, numeric, contactId]
     */
    private function executeBatchPhoneInsert(PDO $pdo, array $batch): int
    {
        if ($batch === []) {
            return 0;
        }

        // INSERT IGNORE into phone_number (may already exist from previous import).
        $placeholders = implode(', ', array_fill(0, count($batch), '(?, ?, 0, ?, ?, 0, 0)'));
        $sql = "INSERT IGNORE INTO `phone_number` (`id`, `name`, `deleted`, `type`, `numeric`, `invalid`, `opt_out`) VALUES {$placeholders}";

        $stmt = $pdo->prepare($sql);
        $i = 1;

        foreach ($batch as [$phoneId, $name, $numeric]) {
            $stmt->bindValue($i++, $phoneId);
            $stmt->bindValue($i++, $name);
            $stmt->bindValue($i++, 'Mobile');
            $stmt->bindValue($i++, $numeric);
        }

        $stmt->execute();

        // INSERT IGNORE into entity_phone_number (link contact → phone_number).
        $sql2 = "INSERT IGNORE INTO `entity_phone_number`
            (`entity_id`, `phone_number_id`, `entity_type`, `primary`, `deleted`)
            VALUES " . implode(', ', array_fill(0, count($batch), '(?, ?, ?, 1, 0)'));

        $stmt2 = $pdo->prepare($sql2);
        $i = 1;

        foreach ($batch as [$phoneId, , , $contactId]) {
            $stmt2->bindValue($i++, $contactId);
            $stmt2->bindValue($i++, $phoneId);
            $stmt2->bindValue($i++, 'Contact');
        }

        $stmt2->execute();

        return count($batch);
    }

    /**
     * Batch INSERT email_address + entity_email_address rows.
     *
     * @param array<int, array{0: string, 1: string, 2: string, 3: string}> $batch
     *                 [emailId, name, lower, contactId]
     */
    private function executeBatchEmailInsert(PDO $pdo, array $batch): int
    {
        if ($batch === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($batch), '(?, ?, 0, ?, 0, 0)'));
        $sql = "INSERT IGNORE INTO `email_address` (`id`, `name`, `deleted`, `lower`, `invalid`, `opt_out`) VALUES {$placeholders}";

        $stmt = $pdo->prepare($sql);
        $i = 1;

        foreach ($batch as [$emailId, $name, $lower]) {
            $stmt->bindValue($i++, $emailId);
            $stmt->bindValue($i++, $name);
            $stmt->bindValue($i++, $lower);
        }

        $stmt->execute();

        $sql2 = "INSERT IGNORE INTO `entity_email_address`
            (`entity_id`, `email_address_id`, `entity_type`, `primary`, `deleted`)
            VALUES " . implode(', ', array_fill(0, count($batch), '(?, ?, ?, 1, 0)'));

        $stmt2 = $pdo->prepare($sql2);
        $i = 1;

        foreach ($batch as [$emailId, , , $contactId]) {
            $stmt2->bindValue($i++, $contactId);
            $stmt2->bindValue($i++, $emailId);
            $stmt2->bindValue($i++, 'Contact');
        }

        $stmt2->execute();

        return count($batch);
    }

    /**
     * @param string[] $columns
     * @param string[] $updateColumns
     */
    private function importEntityCsv(
        PDO $pdo,
        string $csvPath,
        string $tableName,
        array $columns,
        array $updateColumns,
    ): int {
        $handle = fopen($csvPath, 'r');

        if (!$handle) {
            throw new \RuntimeException("Cannot open CSV file: {$csvPath}");
        }

        // Read and validate header.
        $header = fgetcsv($handle);

        if (!$header) {
            fclose($handle);

            return 0;
        }

        $totalRows = 0;
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== count($columns)) {
                // Skip malformed rows.
                continue;
            }

            // Normalize empty strings to NULL.
            $normalizedRow = array_map(
                fn ($val) => ($val === '' || $val === 'NULL') ? null : $val,
                $row
            );

            $batch[] = $normalizedRow;

            if (count($batch) >= self::BATCH_SIZE) {
                $totalRows += $this->executeBatchUpsert($pdo, $tableName, $columns, $updateColumns, $batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $totalRows += $this->executeBatchUpsert($pdo, $tableName, $columns, $updateColumns, $batch);
        }

        fclose($handle);

        return $totalRows;
    }

    /**
     * @param string[] $columns
     * @param string[] $updateColumns
     * @param array<int, array<int, string|null>> $batch
     */
    private function executeBatchUpsert(
        PDO $pdo,
        string $tableName,
        array $columns,
        array $updateColumns,
        array $batch,
    ): int {
        if ($batch === []) {
            return 0;
        }

        $sql = $this->buildUpsertSql($tableName, $columns, $updateColumns, count($batch));
        $stmt = $pdo->prepare($sql);

        $paramIndex = 1;

        foreach ($batch as $row) {
            foreach ($row as $value) {
                $stmt->bindValue($paramIndex++, $value);
            }
        }

        $stmt->execute();

        return count($batch);
    }

    /**
     * @param string[] $columns
     * @param string[] $updateColumns
     */
    private function buildUpsertSql(
        string $tableName,
        array $columns,
        array $updateColumns,
        int $rowCount,
    ): string {
        $columnList = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $valuesList = implode(', ', array_fill(0, $rowCount, $placeholders));

        $sql = "INSERT INTO `{$tableName}` ({$columnList}) VALUES {$valuesList}";

        if ($updateColumns !== []) {
            $updateParts = array_map(
                fn ($c) => "`{$c}` = VALUES(`{$c}`)",
                $updateColumns
            );
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updateParts);
        }

        return $sql;
    }

    private function importEntityTeamCsv(PDO $pdo, string $csvPath): int
    {
        $handle = fopen($csvPath, 'r');

        if (!$handle) {
            throw new \RuntimeException("Cannot open entity_team CSV: {$csvPath}");
        }

        // Read header.
        $header = fgetcsv($handle);

        if (!$header) {
            fclose($handle);

            return 0;
        }

        $totalRows = 0;
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) !== 4) {
                continue;
            }

            $batch[] = [
                $row[0], // entity_id
                $row[1], // team_id
                $row[2], // entity_type
                (int) $row[3], // deleted
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $totalRows += $this->executeBatchEntityTeam($pdo, $batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $totalRows += $this->executeBatchEntityTeam($pdo, $batch);
        }

        fclose($handle);

        return $totalRows;
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string, 3: int}> $batch
     */
    private function executeBatchEntityTeam(PDO $pdo, array $batch): int
    {
        if ($batch === []) {
            return 0;
        }

        // Use INSERT IGNORE to skip duplicate entity_team rows.
        // entity_team has no unique index on (entity_id, team_id, entity_type) by default,
        // but we use INSERT IGNORE to be safe if one gets added.
        $placeholders = implode(', ', array_fill(0, count($batch), '(?, ?, ?, ?)'));
        $sql = "INSERT IGNORE INTO `entity_team` (`entity_id`, `team_id`, `entity_type`, `deleted`) VALUES {$placeholders}";

        $stmt = $pdo->prepare($sql);

        $paramIndex = 1;

        foreach ($batch as $row) {
            $stmt->bindValue($paramIndex++, $row[0]);
            $stmt->bindValue($paramIndex++, $row[1]);
            $stmt->bindValue($paramIndex++, $row[2]);
            $stmt->bindValue($paramIndex++, $row[3], PDO::PARAM_INT);
        }

        $stmt->execute();

        return count($batch);
    }

    /**
     * Normalize a phone number string with Brazil +55 prefix.
     * Strips non-digit characters, removes leading 55 if > 11 digits, prepends +55.
     */
    private function normalizePhoneNumber(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || $trimmed === 'NULL') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $trimmed);

        if (!is_string($digits) || $digits === '') {
            return null;
        }

        if (strlen($digits) > 11 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        return '+55' . $digits;
    }

    private function cleanupOutputDir(string $csvOutputPath): void
    {
        if (!$this->fileManager->isDir($csvOutputPath)) {
            return;
        }

        try {
            $this->fileManager->removeInDir($csvOutputPath);
            @rmdir($csvOutputPath);
        } catch (Throwable $e) {
            $this->log->warning("ImportCsvData: Failed to clean up output directory: " . $e->getMessage());
        }
    }

    private function getPdo(): PDO
    {
        return $this->entityManager->getPDO();
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

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function escapeSqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    /**
     * Robustly sanitize CSV files by doubling internal quotes that are not already escaped.
     * This handles malformed rows where unescaped internal quotes break standard CSV parsing.
     */
    private function sanitizeCsv(string $filePath): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        $this->log->info("ImportCsvData: Sanitizing CSV: {$filePath}");

        $tempPath = $filePath . '.tmp';
        $handle = fopen($filePath, 'r');
        $out = fopen($tempPath, 'w');

        if (!$handle || !$out) {
            $this->log->error("ImportCsvData: Failed to open files for sanitization: {$filePath}");

            return;
        }

        // Regex for unescaped internal quotes:
        // A quote (") is internal if it is:
        // 1. NOT preceded by a comma or start of line: (?<!^|,)
        // 2. NOT followed by a comma, newline, or end of string: (?!,|[\r\n]|$)
        $regex = '/(?<!^|,)"+(?!,|[\r\n]|$)/';

        while (($line = fgets($handle)) !== false) {
            $sanitizedLine = preg_replace($regex, '""', $line);
            fwrite($out, $sanitizedLine);
        }

        fclose($handle);
        fclose($out);

        // Replace original file with sanitized one.
        if (!rename($tempPath, $filePath)) {
            $this->log->error("ImportCsvData: Failed to replace sanitized CSV: {$filePath}");
        }
    }
}
