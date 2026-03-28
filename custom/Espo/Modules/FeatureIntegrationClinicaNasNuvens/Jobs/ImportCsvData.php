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
 * Pipeline: DuckDB reads raw CSVs → joins/transforms/generates IDs → outputs per-entity CSVs
 *           → PHP reads output CSVs → batch INSERT ... ON DUPLICATE KEY UPDATE into MySQL.
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
                'created_by_id', 'modified_by_id', 'sexo', 'payload_hash',
                'ativo', 'cpfcnpj', 'data_nascimento', 'telefone', 'celular',
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
                'created_by_id', 'modified_by_id', 'valor', 'valor_currency',
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
     * Columns to update on duplicate key (excluding id and unique constraint columns).
     * The key for each entity matches the ENTITY_MAP keys.
     *
     * @var array<string, string[]>
     */
    private const UPDATE_COLUMNS = [
        'consulta_tipo' => ['name', 'sync_status', 'ativo', 'reconsulta', 'modified_at', 'settings_id'],
        'convenio_tipo' => ['name', 'sync_status', 'ativo', 'beneficio', 'particular', 'modified_at', 'settings_id'],
        'procedimento_tipo' => ['name', 'sync_status', 'ativo', 'especialidades', 'modified_at', 'settings_id'],
        'procedimento_convenio' => [
            'name', 'is_active', 'convenio_name', 'preco_paciente', 'preco_convenio',
            'modified_at', 'preco_paciente_currency', 'preco_convenio_currency',
        ],
        'profissional' => [
            'name', 'id_pessoa', 'sync_status', 'ativo', 'tipo_executor', 'cpfcnpj',
            'profissional', 'profissional_codigo', 'cbo', 'registro_profissional',
            'especialidades', 'especialidades_texto', 'clinicas', 'modified_at', 'settings_id',
        ],
        'paciente' => [
            'name', 'sync_status', 'ativo', 'cpfcnpj', 'data_nascimento', 'telefone',
            'celular', 'sexo', 'estado_civil', 'profissao', 'endereco', 'numero',
            'complemento', 'bairro', 'cidade', 'estado', 'cep', 'convenio',
            'numero_convenio', 'validade_convenio', 'modified_at', 'settings_id',
        ],
        'agendamento' => [
            'name', 'sync_status', 'id_paciente', 'id_profissional', 'id_convenio',
            'id_especialidade', 'id_unidade', 'id_sala', 'data', 'hora_inicio', 'hora_fim',
            'status', 'profissional', 'convenio', 'especialidade', 'sala', 'observacao',
            'paciente_id', 'status_faturamento', 'id_pessoa_executor', 'profissional_anchor_id',
            'id_tipo_convenio', 'convenio_tipo_anchor_id', 'id_tipo_consulta',
            'consulta_tipo_anchor_id', 'modified_at', 'settings_id',
        ],
        'agendamento_procedimento' => [
            'name', 'quantidade', 'procedimento_nome', 'preco_paciente', 'preco_convenio',
            'valor_total', 'modified_at', 'preco_paciente_currency', 'preco_convenio_currency',
            'valor_total_currency',
        ],
        'faturamento' => [
            'name', 'sync_status', 'documento', 'data_faturamento', 'profissional_nome',
            'valor', 'parcela', 'data_vencimento', 'description', 'valor_currency',
            'agendamento_id', 'paciente_id', 'profissional_anchor_id', 'modified_at', 'settings_id',
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

            // Import each entity CSV into MySQL.
            // Anchor tables (consulta_tipo, convenio_tipo, etc.) are imported first.
            // Then we resolve actual DB IDs and fix FK columns in dependent CSVs
            // before importing agendamento/faturamento.
            $totalRows = 0;
            $pdo = $this->getPdo();

            // Phase 1: Import anchor tables (everything except agendamento,
            // agendamento_procedimento, faturamento, entity_team).
            $dependentEntities = ['agendamento', 'agendamento_procedimento', 'faturamento'];

            foreach (self::ENTITY_MAP as $entityKey => $entityDef) {
                if (in_array($entityKey, $dependentEntities, true)) {
                    continue;
                }

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

            // Phase 2: Resolve actual DB IDs for anchors and fix FK columns
            // in dependent CSVs. The ETL generates new random IDs each run,
            // but ON DUPLICATE KEY UPDATE keeps the original DB IDs.
            $this->fixAnchorForeignKeys($pdo, $csvOutputPath, $resolved['apiCredentialId']);

            // Phase 3: Import dependent tables with corrected FK columns.
            foreach ($dependentEntities as $entityKey) {
                if (!isset(self::ENTITY_MAP[$entityKey])) {
                    continue;
                }

                $entityDef = self::ENTITY_MAP[$entityKey];
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

            // Cleanup output directory.
            $this->cleanupOutputDir($csvOutputPath);

            // Fire EspoCRM hooks (CurrencyConverted, ForeignFields, etc.)
            // on all imported entities. Direct SQL bypasses the ORM, so hooks
            // like currency conversion and link-name resolution don't run.
            $this->fireHooksOnImportedEntities($profileId);

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
     * @return array{apiCredentialId: string, webCredentialId: string, settingsId: string, teamId: string}
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
     * Re-save all imported entities through EspoCRM's ORM to fire hooks
     * (CurrencyConverted, CurrencyDefault, ForeignFields, SyncContactPacienteId, etc.).
     * Direct SQL import bypasses these, so computed/derived fields are missing.
     */
    private function fireHooksOnImportedEntities(string $profileId): void
    {
        // Entity types in dependency order (anchors first, then dependents).
        $entityTypes = [
            'FeatureIntegrationClinicaNasNuvensConsultaTipo',
            'FeatureIntegrationClinicaNasNuvensConvenioTipo',
            'FeatureIntegrationClinicaNasNuvensProcedimentoTipo',
            'FeatureIntegrationClinicaNasNuvensProfissional',
            'FeatureIntegrationClinicaNasNuvensPaciente',
            'FeatureIntegrationClinicaNasNuvensAgendamento',
            'FeatureIntegrationClinicaNasNuvensFaturamento',
        ];

        $saveOptions = [
            SaveOption::SILENT => true,
            SaveOption::SKIP_MODIFIED_BY => true,
            SaveOption::IMPORT => true,
        ];

        foreach ($entityTypes as $entityType) {
            $collection = $this->entityManager
                ->getRDBRepository($entityType)
                ->where(['settingsId' => $profileId])
                ->find();

            $count = 0;

            foreach ($collection as $entity) {
                $this->entityManager->saveEntity($entity, $saveOptions);
                $count++;
            }

            $short = str_replace('FeatureIntegrationClinicaNasNuvens', '', $entityType);
            $this->log->info("ImportCsvData: Fired hooks on {$count} {$short} entities.");
        }
    }

    /**
     * After importing anchor tables, resolve the actual DB IDs and rewrite
     * FK columns in the agendamento/faturamento CSVs. The DuckDB ETL generates
     * fresh random IDs each run, but ON DUPLICATE KEY UPDATE preserves the
     * original DB IDs, so the ETL's lookup tables are stale.
     */
    private function fixAnchorForeignKeys(PDO $pdo, string $csvOutputPath, string $credentialId): void
    {
        // Build lookup maps: remote_id → actual DB id for each anchor type.
        $anchorLookups = [
            'consulta_tipo' => $this->buildAnchorLookup(
                $pdo,
                'feature_integration_clinica_nas_nuvens_consulta_tipo',
                'consulta_tipo_id',
                $credentialId,
            ),
            'convenio_tipo' => $this->buildAnchorLookup(
                $pdo,
                'feature_integration_clinica_nas_nuvens_convenio_tipo',
                'convenio_tipo_id',
                $credentialId,
            ),
            'profissional' => $this->buildAnchorLookup(
                $pdo,
                'feature_integration_clinica_nas_nuvens_profissional',
                'profissional_id',
                $credentialId,
            ),
            'paciente' => $this->buildAnchorLookup(
                $pdo,
                'feature_integration_clinica_nas_nuvens_paciente',
                'paciente_id',
                $credentialId,
            ),
        ];

        // Fix agendamento.csv FK columns.
        $agendamentoCsv = $csvOutputPath . '/agendamento.csv';

        if (file_exists($agendamentoCsv)) {
            $this->rewriteCsvForeignKeys($agendamentoCsv, [
                'consulta_tipo_anchor_id' => $anchorLookups['consulta_tipo'],
                'convenio_tipo_anchor_id' => $anchorLookups['convenio_tipo'],
                'profissional_anchor_id' => $anchorLookups['profissional'],
                'paciente_id' => $anchorLookups['paciente'],
            ]);
        }

        // Fix faturamento.csv FK columns.
        $faturamentoCsv = $csvOutputPath . '/faturamento.csv';

        if (file_exists($faturamentoCsv)) {
            $this->rewriteCsvForeignKeys($faturamentoCsv, [
                'profissional_anchor_id' => $anchorLookups['profissional'],
                'paciente_id' => $anchorLookups['paciente'],
            ]);
        }
    }

    /**
     * Build a map of ETL-generated-id → actual-DB-id for an anchor table.
     * The ETL output CSV and the DB table share the same remote_id column,
     * so we can map: (ETL row remote_id → ETL row id) and
     * (DB row remote_id → DB row id), then compose: ETL id → DB id.
     *
     * But since we don't have the ETL output anymore (it's already imported),
     * we use the DuckDB lookup approach differently: the agendamento CSV
     * references the ETL-generated anchor IDs. We need to map those to DB IDs.
     *
     * Simpler: query DB for (remote_id → id) and also read the ETL anchor CSV
     * for (id → remote_id), then compose.
     *
     * Actually simplest: just return (remote_id → DB id). Then in the CSV
     * rewrite, look up the remote_id from a secondary column.
     *
     * @return array<string, string> remote_id → actual DB id
     */
    private function buildAnchorLookup(
        PDO $pdo,
        string $tableName,
        string $remoteIdColumn,
        string $credentialId,
    ): array {
        $stmt = $pdo->prepare(
            "SELECT `{$remoteIdColumn}` AS remote_id, id FROM `{$tableName}` " .
            "WHERE deleted = 0 AND credential_id = ?"
        );

        $stmt->execute([$credentialId]);
        $map = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[$row['remote_id']] = $row['id'];
        }

        return $map;
    }

    /**
     * Rewrite FK columns in a CSV file using anchor lookups.
     * For each FK column, the ETL CSV also has a corresponding remote ID column
     * (e.g., id_tipo_consulta for consulta_tipo_anchor_id). We use the remote
     * ID to look up the correct DB anchor ID.
     *
     * @param array<string, array<string, string>> $fkMappings column_name → (remote_id → DB id)
     */
    private function rewriteCsvForeignKeys(string $csvPath, array $fkMappings): void
    {
        // Map FK columns to their corresponding remote ID columns in the CSV.
        $fkToRemoteCol = [
            'consulta_tipo_anchor_id' => 'id_tipo_consulta',
            'convenio_tipo_anchor_id' => 'id_tipo_convenio',
            'profissional_anchor_id' => 'id_profissional',
            'paciente_id' => 'id_paciente',
        ];

        $handle = fopen($csvPath, 'r');

        if (!$handle) {
            return;
        }

        $header = fgetcsv($handle);

        if (!$header) {
            fclose($handle);

            return;
        }

        // Find column indices.
        $colIndices = array_flip($header);
        $rows = [];
        $modified = false;

        while (($row = fgetcsv($handle)) !== false) {
            foreach ($fkMappings as $fkCol => $lookup) {
                $remoteCol = $fkToRemoteCol[$fkCol] ?? null;

                if (!$remoteCol || !isset($colIndices[$fkCol]) || !isset($colIndices[$remoteCol])) {
                    continue;
                }

                $fkIdx = $colIndices[$fkCol];
                $remoteIdx = $colIndices[$remoteCol];
                $remoteId = $row[$remoteIdx] ?? '';

                if ($remoteId !== '' && isset($lookup[$remoteId])) {
                    $row[$fkIdx] = $lookup[$remoteId];
                    $modified = true;
                }
            }

            $rows[] = $row;
        }

        fclose($handle);

        if (!$modified) {
            return;
        }

        // Rewrite the CSV.
        $handle = fopen($csvPath, 'w');

        if (!$handle) {
            return;
        }

        fputcsv($handle, $header);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
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
}
