<?php

namespace Espo\Modules\FeatureIntegrationMedx\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureIntegrationMedx\Services\MedxApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ImportClientesJob implements Job
{
    /**
     * Maximum rows to import per chunk.  The Medx GetContatosGrid endpoint
     * defaults to 10 000.
     */
    private const CHUNK_TOP  = 10000;
    private const CHUNK_SKIP = 0;

    public function __construct(
        private EntityManager $entityManager,
        private MedxApiClient $apiClient,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $profileId = $this->requireString($data, 'profileId');
        $top       = (int) ($data->get('top')  ?? self::CHUNK_TOP);
        $skip      = (int) ($data->get('skip') ?? self::CHUNK_SKIP);

        $resolved   = $this->resolveProfile($profileId);
        $profile    = $resolved['profile'];
        $credential = $resolved['webCredential'];

        $totalRows  = 0;
        $created    = 0;
        $updated    = 0;
        $errors     = 0;

        try {
            ['total' => $totalCount, 'rows' => $gridRows] = $this->apiClient->getContatosGrid($credential, $top, $skip);

            foreach ($gridRows as $rawRow) {
                $clienteId = $rawRow['Id_do_Cliente'] ?? null;

                if ($clienteId === null || $clienteId === 'null' || (string) $clienteId === '') {
                    continue;
                }

                $clienteId = (string) $clienteId;

                $existing = $this->findExistingCliente($clienteId, $credential->getId());

                try {
                    $mapped = $this->mapGridRow($rawRow, $clienteId);

                    if ($existing) {
                        $this->applyMappedToEntity($existing, $mapped, false);
                        $existing->set('settingsId', $profile->getId());

                        $this->entityManager->saveEntity($existing, [
                            SaveOption::IMPORT => true,
                        ]);

                        $updated++;
                    } else {
                        $entity = $this->entityManager->getEntity('FeatureIntegrationMedxCliente');
                        $entity->set($mapped);
                        $entity->set('settingsId', $profile->getId());
                        $entity->set('credentialId', $credential->getId());
                        $entity->set('teamsIds', $this->loadProfileTeamIds($profile));

                        $this->entityManager->saveEntity($entity, [
                            SaveOption::IMPORT => true,
                        ]);

                        $created++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $this->log->warning("ImportClientesJob: failed {$clienteId}: " . $e->getMessage());
                }

                $totalRows++;
            }

            $this->markProfileImportCompleted($profile, $created + $updated);

            $this->log->info(
                "ImportClientesJob (profile {$profileId}): " .
                "{$totalRows} processed, {$created} created, {$updated} updated, {$errors} errors."
            );
        } catch (\Throwable $e) {
            $this->markProfileImportFailed($profile);
            $this->log->error("ImportClientesJob (profile {$profileId}): " . $e->getMessage());

            throw $e;
        }
    }

    /* ──────────────────────────────────────────────────────────────── */
    /*  Helpers                                                          */
    /* ──────────────────────────────────────────────────────────────── */

    /**
     * Resolve profile and credential directly from EntityManager.
     * Jobs run in system context without a user, so ACL checks from
     * MedxIntegrationProfileResolver would fail.
     *
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

    private function requireString(Data $data, string $field): string
    {
        $value = $data->get($field);

        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException("Missing required job data field '{$field}'.");
        }

        return trim($value);
    }

    private function findExistingCliente(string $clienteId, string $credentialId): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository('FeatureIntegrationMedxCliente')
            ->where([
                'clienteId'    => $clienteId,
                'credentialId' => $credentialId,
                'deleted'      => false,
            ])
            ->findOne();
    }

    /**
     * Map a single grid row to entity field values.
     *
     * Grid endpoint returns a flat object.  For enrichment-capable fields
     * we store only the values present on the grid.  The HydrateOnImport
     * hook will trigger enrichment from GetContatosFichaById afterward.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapGridRow(array $row, string $clienteId): array
    {
        return [
            'name'               => $this->nullableString($row['Nome'] ?? null),
            'clienteId'          => $clienteId,
            'assinaturaId'       => $this->nullableInt($row['Id_da_Assinatura'] ?? null),
            'cpfCgc'             => $this->nullableString($row['CPF_CGC'] ?? null),
            'emailAddress'       => $this->nullableString(
                $this->nullifyLiteral('null', $row['Email'] ?? null)
            ),
            'celular'            => $this->nullableString($row['Celular'] ?? null),
            'telefoneResidencial' => $this->nullableString($row['Telefone_Residencial'] ?? null),
            'telefoneResidencial1' => $this->nullableString($row['Telefone_Residencial_1'] ?? null),
            'idDoConvenio'       => $this->nullableInt($row['IddoConvenio'] ?? $row['Id_do_Convenio'] ?? null),
            'convenio'           => $this->nullableString($row['Convenio'] ?? null),
            'pendente'           => $this->fauxBoolToBool($row['Pendente'] ?? null),
            'nomeSocial'         => $this->nullableString($row['Nome_Social'] ?? null),
            'syncStatus'         => 'pending',
        ];
    }

    /**
     * Apply mapped data to an existing entity without overwriting existing
     * non-blank values (import-time enrichment will fill the rest).
     *
     * @param array<string, mixed> $mapped
     */
    private function applyMappedToEntity(Entity $entity, array $mapped, bool $overwrite): void
    {
        $syncStatus = $entity->get('syncStatus');

        foreach ($mapped as $field => $value) {
            if ($field === 'syncStatus') {
                continue;
            }

            if ($value === null) {
                continue;
            }

            $current = $entity->get($field);

            if ($current !== null && !$overwrite) {
                continue;
            }

            $entity->set($field, $value);
        }

        if ($syncStatus !== 'error') {
            $entity->set('syncStatus', 'pending');
        }
    }

    /**
     * @return string[]
     */
    private function loadProfileTeamIds(Entity $profile): array
    {
        $relation = $this->entityManager
            ->getRDBRepository('FeatureIntegrationMedxSettings')
            ->getRelation($profile, 'teams');

        $teamIds = [];

        foreach ($relation->find() as $team) {
            $tid = $team->getId();

            if (is_string($tid) && $tid !== '') {
                $teamIds[] = $tid;
            }
        }

        return array_values(array_unique($teamIds));
    }

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
    /*  Value normalisers                                                */
    /* ──────────────────────────────────────────────────────────────── */

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_scalar($value)) {
            $trimmed = trim((string) $value);

            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
    }

    private function nullifyLiteral(string $literal, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (strtolower(trim($value)) === strtolower($literal)) {
            return null;
        }

        return $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $intVal = (int) $value;

            return $intVal !== 0 ? $intVal : null;
        }

        return null;
    }

    /**
     * Convert MEDX faux-bool ("true"/"false"/true/false) to a real bool.
     */
    private function fauxBoolToBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $lower = strtolower(trim((string) $value));

        if ($lower === 'true') {
            return true;
        }

        if ($lower === 'false') {
            return false;
        }

        return null;
    }
}
