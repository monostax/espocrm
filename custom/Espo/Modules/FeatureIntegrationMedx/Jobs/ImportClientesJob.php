<?php

namespace Espo\Modules\FeatureIntegrationMedx\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureIntegrationMedx\Services\MedxApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * Job that imports MEDX Clientes into MySQL via DuckDB ETL.
 *
 * Pipeline: PHP fetches MEDX GetContatosGrid API (handles auth + pagination)
 *           -> writes raw JSON to temp file
 *           -> DuckDB reads JSON, transforms, generates deterministic IDs
 *           -> outputs per-entity CSVs
 *           -> PHP reads output CSVs -> batch INSERT ... ON DUPLICATE KEY UPDATE into MySQL.
 *
 * All entity IDs are deterministic (derived from credentialId + clienteId via MD5),
 * so the DuckDB output CSVs contain correct FK values that can be inserted directly
 * -- no post-ETL fixup needed.
 */
class ImportClientesJob implements Job
{
    private const BATCH_SIZE = 500;

    /**
     * Maximum rows to import per chunk. The Medx GetContatosGrid endpoint
     * defaults to 10 000.
     */
    private const CHUNK_TOP  = 10000;
    private const CHUNK_SKIP = 0;

    /**
     * Entity table and columns for import.
     * Key = output CSV filename (without .csv), Value = [table => MySQL table, columns => ordered column list].
     *
     * @var array<string, array{table: string, columns: string[]}>
     */
    private const ENTITY_MAP = [
        'clientes' => [
            'table' => 'feature_integration_medx_cliente',
            'columns' => [
                'id', 'name', 'deleted', 'cliente_id', 'sync_status',
                'assinatura_id', 'cpf_cgc', 'data_nascimento', 'email_address',
                'celular', 'telefone_residencial', 'telefone_residencial1',
                'sexo', 'nome_social', 'estado_civil', 'profissao',
                'endereco_residencial', 'bairro_residencial', 'cidade_residencial',
                'estado_residencial', 'cep_residencial',
                'id_do_convenio', 'convenio', 'numero_matricula', 'numero_cns',
                'observacoes', 'referencias', 'tags',
                'vip', 'mala_direta', 'pendente',
                'exclui_mkt', 'indicado_por', 'como_conheceu',
                'escolaridade', 'religiao',
                'created_at', 'modified_at',
                'contact_id', 'credential_id',
                'created_by_id', 'modified_by_id', 'settings_id',
            ],
        ],
    ];

    /**
     * Columns to update on duplicate key.
     * Includes 'id' so deterministic IDs replace time-based IDs on re-import.
     *
     * @var array<string, string[]>
     */
    private const UPDATE_COLUMNS = [
        'clientes' => [
            'id', 'name', 'sync_status',
            'assinatura_id', 'cpf_cgc', 'data_nascimento', 'email_address',
            'celular', 'telefone_residencial', 'telefone_residencial1',
            'sexo', 'nome_social', 'estado_civil', 'profissao',
            'endereco_residencial', 'bairro_residencial', 'cidade_residencial',
            'estado_residencial', 'cep_residencial',
            'id_do_convenio', 'convenio', 'numero_matricula', 'numero_cns',
            'observacoes', 'referencias', 'tags',
            'vip', 'mala_direta', 'pendente',
            'exclui_mkt', 'indicado_por', 'como_conheceu',
            'escolaridade', 'religiao',
            'modified_at', 'settings_id',
        ],
    ];

    public function __construct(
        private EntityManager $entityManager,
        private MedxApiClient $apiClient,
        private FileManager $fileManager,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $profileId = $this->requireString($data, 'profileId');
        $top       = (int) ($data->get('top')  ?? self::CHUNK_TOP);
        $skip      = (int) ($data->get('skip') ?? self::CHUNK_SKIP);

        $this->log->info(
            "ImportClientesJob: Starting for profile '{$profileId}', top={$top}, skip={$skip}."
        );

        $resolved   = $this->resolveProfile($profileId);
        $profile    = $resolved['profile'];
        $credential = $resolved['webCredential'];

        // Set import status to inProgress.
        $profile->set('importStatus', 'inProgress');
        $this->entityManager->saveEntity($profile, [SaveOption::SKIP_ALL => true]);

        try {
            // 1. Fetch from MEDX API (PHP handles auth + pagination).
            $this->log->info("ImportClientesJob: Fetching from MEDX API...");
            ['total' => $totalCount, 'rows' => $gridRows] = $this->apiClient->getContatosGrid($credential, $top, $skip);

            $this->log->info(
                "ImportClientesJob: Fetched {$totalCount} total, " . count($gridRows) . " rows in this chunk."
            );

            if ($gridRows === []) {
                $this->markProfileImportCompleted($profile, 0);
                $this->log->info("ImportClientesJob: No rows to import for profile '{$profileId}'.");

                return;
            }

            // 2. Write raw JSON to temp file.
            $jsonInputPath = $this->writeJsonToTempFile($profileId, $gridRows);

            // 3. Execute DuckDB ETL.
            $csvOutputPath = $this->executeDuckDbEtl(
                $jsonInputPath,
                $profileId,
                $credential->getId(),
                $profileId, // settingsId = profileId
                $this->resolveTeamId($profile),
            );

            // 4. Import all entity CSVs into MySQL.
            $totalRows = 0;
            $pdo = $this->getPdo();

            foreach (self::ENTITY_MAP as $entityKey => $entityDef) {
                $csvFile = $csvOutputPath . '/' . $entityKey . '.csv';

                if (!file_exists($csvFile)) {
                    $this->log->warning("ImportClientesJob: Output CSV not found: {$csvFile}. Skipping {$entityKey}.");

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
                    "ImportClientesJob: Imported {$rowCount} rows into {$entityDef['table']}."
                );

                $totalRows += $rowCount;
            }

            // Import entity_team separately (BIGINT auto-increment id).
            $entityTeamCsv = $csvOutputPath . '/entity_team.csv';

            if (file_exists($entityTeamCsv)) {
                $teamRows = $this->importEntityTeamCsv($pdo, $entityTeamCsv);
                $this->log->info("ImportClientesJob: Imported {$teamRows} entity_team rows.");
                $totalRows += $teamRows;
            }

            // 5. Cleanup temp files.
            $this->cleanupTempFiles($jsonInputPath, $csvOutputPath);

            // 6. Mark import as completed.
            $this->markProfileImportCompleted($profile, $totalRows);

            $this->log->info(
                "ImportClientesJob: Completed for profile '{$profileId}'. Total rows: {$totalRows}."
            );
        } catch (Throwable $e) {
            $this->log->error(
                "ImportClientesJob: Failed for profile '{$profileId}': " . $e->getMessage()
            );

            $freshProfile = $this->entityManager->getEntityById('FeatureIntegrationMedxSettings', $profileId);

            if ($freshProfile) {
                $this->markProfileImportFailed($freshProfile);
            }

            throw $e;
        }
    }

    /* ──────────────────────────────────────────────────────────────── */
    /*  Profile resolution                                               */
    /* ──────────────────────────────────────────────────────────────── */

    /**
     * @return array{profile: Entity, webCredential: Entity}
     */
    private function resolveProfile(string $profileId): array
    {
        $profile = $this->entityManager->getEntityById('FeatureIntegrationMedxSettings', $profileId);

        if (!$profile) {
            throw new \RuntimeException("ImportClientesJob: profile '{$profileId}' not found.");
        }

        if (!$profile->get('isActive')) {
            throw new \RuntimeException("ImportClientesJob: profile '{$profileId}' is inactive.");
        }

        $webCredentialId = $profile->get('webCredentialId');

        if (!is_string($webCredentialId) || $webCredentialId === '') {
            throw new \RuntimeException("ImportClientesJob: profile '{$profileId}' is missing Web credential.");
        }

        $webCredential = $this->entityManager->getEntityById('Credential', $webCredentialId);

        if (!$webCredential || !$webCredential->get('isActive')) {
            throw new \RuntimeException("ImportClientesJob: Web credential '{$webCredentialId}' is unavailable or inactive.");
        }

        return [
            'profile' => $profile,
            'webCredential' => $webCredential,
        ];
    }

    private function resolveTeamId(Entity $profile): string
    {
        $teamIds = $profile->get('teamsIds');
        $teamId = null;

        if (is_array($teamIds) && count($teamIds) > 0) {
            $teamId = $teamIds[0];
        }

        if (!$teamId) {
            $teams = $this->entityManager
                ->getRDBRepository('FeatureIntegrationMedxSettings')
                ->getRelation($profile, 'teams')
                ->find();

            foreach ($teams as $team) {
                $teamId = $team->getId();

                break;
            }
        }

        if (!$teamId) {
            throw new \RuntimeException("ImportClientesJob: profile has no team assigned.");
        }

        return $teamId;
    }

    /* ──────────────────────────────────────────────────────────────── */
    /*  JSON temp file                                                   */
    /* ──────────────────────────────────────────────────────────────── */

    /**
     * Write the raw API response rows to a temp JSON file.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return string Absolute path to the temp JSON file.
     */
    private function writeJsonToTempFile(string $profileId, array $rows): string
    {
        $tmpDir = "data/tmp/medx-import-{$profileId}";

        if (!$this->fileManager->isDir($tmpDir)) {
            $this->fileManager->mkdir($tmpDir);
        }

        $jsonPath = $tmpDir . '/clientes.json';
        $jsonContent = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($jsonContent === false) {
            throw new \RuntimeException("ImportClientesJob: Failed to encode JSON for profile '{$profileId}'.");
        }

        file_put_contents($jsonPath, $jsonContent);

        $absPath = realpath($jsonPath);

        if (!$absPath) {
            throw new \RuntimeException("ImportClientesJob: Cannot resolve absolute path for: {$jsonPath}");
        }

        return $absPath;
    }

    /* ──────────────────────────────────────────────────────────────── */
    /*  DuckDB ETL execution                                             */
    /* ──────────────────────────────────────────────────────────────── */

    private function executeDuckDbEtl(
        string $jsonInputPath,
        string $profileId,
        string $credentialId,
        string $settingsId,
        string $teamId,
    ): string {
        $csvOutputPath = "data/tmp/medx-import-{$profileId}/output";

        if (!$this->fileManager->isDir($csvOutputPath)) {
            $this->fileManager->mkdir($csvOutputPath);
        }

        // Build the DuckDB SQL script path.
        $sqlScriptPath = 'custom/Espo/Modules/FeatureIntegrationMedx/Resources/sql/import-clientes-etl.sql';

        if (!file_exists($sqlScriptPath)) {
            throw new \RuntimeException("DuckDB ETL script not found: {$sqlScriptPath}");
        }

        $absCsvOutputPath = realpath($csvOutputPath);

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
            sprintf("SET VARIABLE jsonInputPath = '%s';", $this->escapeSqlString($jsonInputPath)),
            sprintf("SET VARIABLE credentialId = '%s';", $this->escapeSqlString($credentialId)),
            sprintf("SET VARIABLE settingsId = '%s';", $this->escapeSqlString($settingsId)),
            sprintf("SET VARIABLE teamId = '%s';", $this->escapeSqlString($teamId)),
        ]);

        $fullSql = $setVars . "\n" . $sqlScript;

        $command = 'duckdb';

        // Prefer absolute path if available (common in k8s/pods).
        if (file_exists('/usr/local/bin/duckdb')) {
            $command = '/usr/local/bin/duckdb';
        }

        $this->log->info("ImportClientesJob: Executing DuckDB ETL for profile '{$profileId}'.");

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
            $this->log->error("ImportClientesJob: DuckDB ETL failed (exit {$exitCode}). stderr: {$stderr}");

            throw new \RuntimeException(
                "DuckDB ETL failed with exit code {$exitCode}: " . trim($stderr)
            );
        }

        $this->log->info("ImportClientesJob: DuckDB ETL completed. stdout: " . trim($stdout));

        return $csvOutputPath;
    }

    /* ──────────────────────────────────────────────────────────────── */
    /*  CSV -> MySQL import                                              */
    /* ──────────────────────────────────────────────────────────────── */

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

    /* ──────────────────────────────────────────────────────────────── */
    /*  Cleanup                                                          */
    /* ──────────────────────────────────────────────────────────────── */

    private function cleanupTempFiles(string $jsonInputPath, string $csvOutputPath): void
    {
        // Remove input JSON.
        if (file_exists($jsonInputPath)) {
            @unlink($jsonInputPath);
        }

        // Remove output CSV directory.
        if ($this->fileManager->isDir($csvOutputPath)) {
            try {
                $this->fileManager->removeInDir($csvOutputPath);
                @rmdir($csvOutputPath);
            } catch (Throwable $e) {
                $this->log->warning("ImportClientesJob: Failed to clean up output directory: " . $e->getMessage());
            }
        }

        // Remove parent temp directory if empty.
        $parentDir = dirname($jsonInputPath);

        if (is_dir($parentDir)) {
            $remaining = array_diff(scandir($parentDir) ?: [], ['.', '..']);

            if ($remaining === []) {
                @rmdir($parentDir);
            }
        }
    }

    /* ──────────────────────────────────────────────────────────────── */
    /*  Profile status helpers                                           */
    /* ──────────────────────────────────────────────────────────────── */

    private function markProfileImportCompleted(Entity $profile, int $count): void
    {
        $profile->set('importStatus', 'completed');
        $profile->set('lastImportAt', date('Y-m-d H:i:s'));
        $profile->set('totalImported', ((int) $profile->get('totalImported') ?: 0) + $count);
        $this->entityManager->saveEntity($profile, [SaveOption::SKIP_HOOKS => true]);
    }

    private function markProfileImportFailed(Entity $profile): void
    {
        $profile->set('importStatus', 'failed');
        $this->entityManager->saveEntity($profile, [SaveOption::SKIP_HOOKS => true]);
    }

    /* ──────────────────────────────────────────────────────────────── */
    /*  Utilities                                                        */
    /* ──────────────────────────────────────────────────────────────── */

    private function requireString(Data $data, string $field): string
    {
        $value = $data->get($field);

        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException("Missing required job data field '{$field}'.");
        }

        return trim($value);
    }

    private function getPdo(): PDO
    {
        return $this->entityManager->getPDO();
    }

    private function escapeSqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
