<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services;

use Espo\Core\Di;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Record\Collection as RecordCollection;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\CreateResult;
use Espo\Core\Record\FindParams;
use Espo\Core\Record\ReadParams;
use Espo\Core\Record\ReadResult;
use Espo\Core\Record\Service as RecordService;
use Espo\Core\Select\SearchParams;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensIntegrationProfileResolver;
use Espo\ORM\Entity;
use stdClass;
use Throwable;

/**
 * @extends RecordService<Entity>
 */
class FeatureIntegrationClinicaNasNuvensProfissional extends RecordService implements
    Di\LogAware
{
    use Di\LogSetter;

    private const ENRICHMENT_BATCH_SIZE = 25;
    private const EXPECTED_TIPO_EXECUTOR = 'PROFISSIONAL';

    /**
     * @var string[]
     */
    private const ALLOWED_ORDER_FILTER_FIELDS = [
        'id',
        'name',
        'profissionalId',
        'idPessoa',
        'credential',
        'credentialId',
        'syncStatus',
        'ativo',
        'tipoExecutor',
        'cpfcnpj',
        'email',
        'telefoneCelular',
        'telefoneComercial',
        'telefoneResidencial',
        'telefoneRecados',
        'profissional',
        'profissionalCodigo',
        'cbo',
        'registroProfissional',
        'clinicas',
        'especialidadesTexto',
        'createdAt',
        'modifiedAt',
        'createdBy',
        'createdById',
        'modifiedBy',
        'modifiedById',
        'teams',
        'teamsIds',
        'deleted',
    ];

    /**
     * @var string[]
     */
    private const PERSISTED_REMOTE_FIELDS = [
        'name',
        'idPessoa',
        'ativo',
        'tipoExecutor',
        'cpfcnpj',
        'email',
        'telefoneCelular',
        'telefoneComercial',
        'telefoneResidencial',
        'telefoneRecados',
        'profissional',
        'profissionalCodigo',
        'cbo',
        'registroProfissional',
        'clinicas',
        'especialidades',
        'especialidadesTexto',
    ];

    public function read(string $id, ReadParams $params = new ReadParams()): ReadResult
    {
        return parent::read($id, $params);
    }

    public function find(SearchParams $searchParams, ?FindParams $params = null): RecordCollection
    {
        $this->assertSearchParamsUseStorableFields($searchParams);

        return parent::find($searchParams, $params);
    }

    public function create(stdClass $data, CreateParams $params = new CreateParams()): CreateResult
    {
        $settingsId = $this->normalizeNullableString($data->settingsId ?? null);

        if (!$settingsId && property_exists($data, 'settings')) {
            $rawSettings = $data->settings;

            if (is_string($rawSettings)) {
                $settingsId = $this->normalizeNullableString($rawSettings);
            }

            if ($rawSettings instanceof stdClass && property_exists($rawSettings, 'id')) {
                $settingsId = $this->normalizeNullableString($rawSettings->id);
            }
        }

        if ($settingsId) {
            $resolved = $this->getProfileResolver()->resolveForProfileId($settingsId);
            $profile = $resolved['profile'];

            $data->settingsId = $settingsId;
            $data->credentialId = $resolved['apiCredential']->getId();

            $rawTeamIdList = $profile->get('teamsIds');
            $data->teamsIds = is_array($rawTeamIdList)
                ? array_values(array_filter($rawTeamIdList, fn ($id) => is_string($id) && trim($id) !== ''))
                : [];
        }

        $rawProfissionalId = $this->extractRawProfissionalIdFromInput($data);
        $rawPessoaId = $this->extractRawPessoaIdFromInput($data);
        $rawTeamIdList = $this->extractTeamIdListFromInput($data);
        $preCreateCredential = $this->resolveAccessibleCredentialFromInput($data, $rawTeamIdList);

        if (!$rawProfissionalId && $rawPessoaId && $preCreateCredential) {
            $existingByPessoa = $this->findProfissionalAnchorByPessoaIncludingDeleted($rawPessoaId, $preCreateCredential->getId());

            if ($existingByPessoa) {
                $existingByPessoa = $this->restoreProfissionalIfDeleted($existingByPessoa);

                if ($existingByPessoa) {
                    $this->mergeTeamsIntoProfissionalAnchor($existingByPessoa, $rawTeamIdList);
                    $this->enrichEntities([$existingByPessoa], true);

                    return new CreateResult($existingByPessoa);
                }
            }

            $resolvedProfissionalId = $this->getApiClient()->resolveExecutorAgendaIdByPessoaId($preCreateCredential, $rawPessoaId);

            if ($resolvedProfissionalId) {
                $rawProfissionalId = $resolvedProfissionalId;
                $data->profissionalId = $resolvedProfissionalId;
            }
        }

        if (!$rawProfissionalId) {
            if ($rawPessoaId) {
                throw new BadRequest(
                    "Cannot create profissional anchor from idPessoa '{$rawPessoaId}': no executor-agenda ID could be resolved."
                );
            }

            throw new BadRequest(
                'Cannot create profissional anchor without profissionalId or idPessoaExecutor.'
            );
        }

        if ($rawPessoaId) {
            $data->idPessoa = $rawPessoaId;
        }

        if ($preCreateCredential) {
            $existing = $this->findProfissionalAnchorIncludingDeleted($rawProfissionalId, $preCreateCredential->getId());

            if ($existing) {
                $existing = $this->restoreProfissionalIfDeleted($existing);

                if ($existing) {
                    $this->mergeTeamsIntoProfissionalAnchor($existing, $rawTeamIdList);
                    $this->enrichEntities([$existing], true);

                    return new CreateResult($existing);
                }
            }
        }

        $this->assertProfissionalExistsBeforeCreate($rawProfissionalId, $rawPessoaId, $preCreateCredential);

        $entity = parent::create($data, $params)->getEntity();

        $this->enrichEntities([$entity], true);
        $entity = $this->persistProfissionalAfterCreate($entity);

        return new CreateResult($entity);
    }

    public function hydrateAfterImport(string $id): void
    {
        $entity = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensProfissional', $id);

        if (!$entity) {
            return;
        }

        $this->enrichEntities([$entity], true);
    }

    private function assertProfissionalExistsBeforeCreate(string $profissionalId, ?string $idPessoa, ?Entity $credential): void
    {
        if (!$credential) {
            throw new BadRequest(
                "Cannot create profissional anchor '{$profissionalId}' without an accessible CNN credential."
            );
        }

        try {
            $payload = $this->getApiClient()->getExecutorAgendaById($credential, $profissionalId);
        } catch (Throwable $e) {
            throw new BadRequest(
                "Cannot create profissional anchor '{$profissionalId}': invalid or non-existent remote profissional ID.",
                previous: $e
            );
        }

        $resolvedProfissionalId = $this->normalizeNullableString($payload['profissionalId'] ?? null);

        if (!$resolvedProfissionalId || $resolvedProfissionalId !== $profissionalId) {
            throw new BadRequest(
                "Cannot create profissional anchor '{$profissionalId}': invalid or non-existent remote profissional ID."
            );
        }

        if ($idPessoa !== null) {
            $resolvedPessoaId = $this->normalizeNullableString($payload['idPessoa'] ?? null);

            if ($resolvedPessoaId !== null && $resolvedPessoaId !== $idPessoa) {
                throw new BadRequest(
                    "Cannot create profissional anchor '{$profissionalId}': remote idPessoa does not match '{$idPessoa}'."
                );
            }
        }

        if (!$this->isExpectedTipoExecutor($payload['tipoExecutor'] ?? null)) {
            throw new BadRequest(
                "Cannot create profissional anchor '{$profissionalId}': remote executor is not of type PROFISSIONAL."
            );
        }
    }

    private function assertSearchParamsUseStorableFields(SearchParams $searchParams): void
    {
        $orderBy = $searchParams->getOrderBy();

        if ($orderBy && !$this->isFieldAllowedForDbQuery($orderBy)) {
            throw new BadRequest("Sorting by '{$orderBy}' is not supported for this entity.");
        }

        $where = $searchParams->getWhere();

        if (!$where) {
            return;
        }

        $this->assertWhereNodeUsesStorableFields($where->getRaw());
    }

    /**
     * @param mixed $node
     */
    private function assertWhereNodeUsesStorableFields($node): void
    {
        if (!is_array($node)) {
            return;
        }

        if (isset($node['attribute']) && is_string($node['attribute'])) {
            $field = $node['attribute'];

            if (!$this->isFieldAllowedForDbQuery($field)) {
                throw new BadRequest("Filtering by '{$field}' is not supported for this entity.");
            }
        }

        if (isset($node['field']) && is_string($node['field'])) {
            $field = $node['field'];

            if (!$this->isFieldAllowedForDbQuery($field)) {
                throw new BadRequest("Filtering by '{$field}' is not supported for this entity.");
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->assertWhereNodeUsesStorableFields($value);
            }
        }
    }

    private function isFieldAllowedForDbQuery(string $field): bool
    {
        if (in_array($field, self::ALLOWED_ORDER_FILTER_FIELDS, true)) {
            return true;
        }

        if (str_ends_with($field, 'Id')) {
            $baseField = substr($field, 0, -2);

            if (in_array($baseField, self::ALLOWED_ORDER_FILTER_FIELDS, true)) {
                return true;
            }
        }

        $isNotStorable = (bool) $this->metadata->get([
            'entityDefs',
            $this->entityType,
            'fields',
            $field,
            'notStorable',
        ]);

        if ($isNotStorable) {
            return false;
        }

        return (bool) $this->metadata->get([
            'entityDefs',
            $this->entityType,
            'fields',
            $field,
        ]);
    }

    /**
     * @param Entity[] $entities
     */
    private function enrichEntities(array $entities, bool $persist): void
    {
        return; // Enrichment disabled — data is populated by CSV ETL import.

        /** @var array<string, array<string, Entity[]>> $grouped */
        $grouped = [];

        /** @var array<string, Entity> $selectedCredentialMap */
        $selectedCredentialMap = [];

        foreach ($entities as $entity) {
            $profissionalId = $entity->get('profissionalId');

            if (!$profissionalId) {
                continue;
            }

            $teamIdList = $this->extractTeamIdList($entity);
            $credential = $this->getCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);

            if (!$credential) {
                $entity->set('syncStatus', 'error');

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensProfissional: no accessible CNN credential for profissional '" .
                    $entity->getId() . "' teams."
                );

                continue;
            }

            $credentialId = $credential->getId();
            $selectedCredentialMap[$credentialId] = $credential;

            $entity->set('credentialId', $credentialId);
            $entity->set('credentialName', $credential->get('name'));

            if (!isset($grouped[$credentialId])) {
                $grouped[$credentialId] = [];
            }

            if (!isset($grouped[$credentialId][$profissionalId])) {
                $grouped[$credentialId][$profissionalId] = [];
            }

            $grouped[$credentialId][$profissionalId][] = $entity;
        }

        if ($grouped === []) {
            return;
        }

        foreach ($grouped as $credentialId => $profissionalMap) {
            $credential = $selectedCredentialMap[$credentialId] ?? null;

            if (!$credential) {
                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensProfissional: credential '{$credentialId}' not accessible."
                );

                continue;
            }

            $profissionalIdList = array_keys($profissionalMap);
            $chunks = array_chunk($profissionalIdList, self::ENRICHMENT_BATCH_SIZE);

            foreach ($chunks as $chunk) {
                foreach ($chunk as $profissionalId) {
                    try {
                        $payload = $this->getApiClient()->getExecutorAgendaById($credential, $profissionalId);

                        if (!$this->isExpectedTipoExecutor($payload['tipoExecutor'] ?? null)) {
                            throw new BadRequest('remote executor is not of type PROFISSIONAL');
                        }

                        foreach ($profissionalMap[$profissionalId] as $entity) {
                            foreach ($payload as $field => $value) {
                                if ($this->shouldSkipBlankHydrationValue($entity, $field, $value)) {
                                    continue;
                                }

                                if ((is_array($value) || is_object($value)) && $field !== 'especialidades') {
                                    continue;
                                }

                                $entity->set($field, $value);
                            }

                            $entity->set('syncStatus', 'synced');

                            if ($persist) {
                                $this->persistHydratedFields($entity, $payload, 'synced');
                            }
                        }
                    } catch (Throwable $e) {
                        foreach ($profissionalMap[$profissionalId] as $entity) {
                            $entity->set('syncStatus', 'error');
                        }

                        $this->log->warning(
                            "FeatureIntegrationClinicaNasNuvensProfissional: failed to enrich profissional '" .
                            $profissionalId . "' for credential '{$credentialId}': " . $e->getMessage()
                        );
                    }
                }
            }
        }
    }

    /**
     * @return string[]
     */
    private function extractTeamIdList(Entity $entity): array
    {
        $teamIdList = $entity->get('teamsIds');

        if (is_array($teamIdList) && $teamIdList !== []) {
            return array_values(array_filter($teamIdList, fn ($id) => is_string($id) && $id !== ''));
        }

        $entityId = $this->normalizeNullableString($entity->getId());

        if (!$entityId) {
            return [];
        }

        $query = $this->entityManager
            ->getQueryBuilder()
            ->select(['teamId'])
            ->from('EntityTeam')
            ->where([
                'entityType' => 'FeatureIntegrationClinicaNasNuvensProfissional',
                'entityId' => $entityId,
                'deleted' => false,
            ])
            ->build();

        $collection = $this->entityManager
            ->getRDBRepository('EntityTeam')
            ->clone($query)
            ->find();

        $resolved = [];

        foreach ($collection as $row) {
            $teamId = $this->normalizeNullableString($row->get('teamId'));

            if ($teamId) {
                $resolved[] = $teamId;
            }
        }

        if ($resolved !== []) {
            return array_values(array_unique($resolved));
        }

        return [];
    }

    private function findProfissionalAnchorIncludingDeleted(string $profissionalId, string $credentialId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensProfissional')
            ->where([
                'profissionalId' => $profissionalId,
                'credentialId' => $credentialId,
            ])
            ->withDeleted()
            ->order('deleted', 'ASC')
            ->build();

        return $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProfissional')
            ->clone($query)
            ->findOne();
    }

    private function findProfissionalAnchorByPessoaIncludingDeleted(string $idPessoa, string $credentialId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensProfissional')
            ->where([
                'idPessoa' => $idPessoa,
                'credentialId' => $credentialId,
            ])
            ->withDeleted()
            ->order('deleted', 'ASC')
            ->build();

        return $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProfissional')
            ->clone($query)
            ->findOne();
    }

    private function restoreProfissionalIfDeleted(Entity $profissional): ?Entity
    {
        if (!$profissional->get('deleted')) {
            return $profissional;
        }

        $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProfissional')
            ->restoreDeleted($profissional->getId());

        return $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensProfissional', $profissional->getId());
    }

    /**
     * @param string[] $teamIdList
     */
    private function mergeTeamsIntoProfissionalAnchor(Entity $profissional, array $teamIdList): void
    {
        $existingTeamIdList = $this->extractTeamIdList($profissional);
        $mergedTeamIdList = array_values(array_unique(array_merge($existingTeamIdList, $teamIdList)));

        if ($mergedTeamIdList === $existingTeamIdList) {
            return;
        }

        $profissional->set('teamsIds', $mergedTeamIdList);

        $this->entityManager->saveEntity($profissional, [
            SaveOption::SILENT => true,
            SaveOption::SKIP_HOOKS => true,
            SaveOption::SKIP_MODIFIED_BY => true,
        ]);
    }

    private function getApiClient(): ClinicaNasNuvensApiClient
    {
        return $this->injectableFactory->create(ClinicaNasNuvensApiClient::class);
    }

    private function getCredentialHelper(): ClinicaNasNuvensCredentialHelper
    {
        return $this->injectableFactory->create(ClinicaNasNuvensCredentialHelper::class);
    }

    private function getProfileResolver(): ClinicaNasNuvensIntegrationProfileResolver
    {
        return $this->injectableFactory->create(ClinicaNasNuvensIntegrationProfileResolver::class);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistHydratedFields(Entity $entity, array $payload, string $syncStatus): void
    {
        if ($payload === []) {
            return;
        }

        $toPersist = [];

        foreach (self::PERSISTED_REMOTE_FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];

            if ($this->shouldSkipBlankPersistValue($entity, $field, $value)) {
                continue;
            }

            if ((is_array($value) || is_object($value)) && $field !== 'especialidades') {
                continue;
            }

            if ($entity->getFetched($field) !== $value) {
                $toPersist[$field] = $value;
            }
        }

        if ($entity->getFetched('syncStatus') !== $syncStatus) {
            $toPersist['syncStatus'] = $syncStatus;
        }

        if ($toPersist === []) {
            return;
        }

        $entity->set($toPersist);

        try {
            $this->entityManager->saveEntity($entity, [
                SaveOption::SILENT => true,
                SaveOption::SKIP_HOOKS => true,
                SaveOption::SKIP_MODIFIED_BY => true,
            ]);
        } catch (Throwable $e) {
            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensProfissional: failed to persist hydrated fields for '" .
                $entity->getId() . "': " . $e->getMessage()
            );
        }
    }

    /**
     * @param mixed $value
     */
    private function shouldSkipBlankHydrationValue(Entity $entity, string $field, $value): bool
    {
        if ($value !== null && (!is_string($value) || trim($value) !== '')) {
            if (is_array($value)) {
                return false;
            }

            return false;
        }

        return $this->hasNonBlankValue($entity->get($field));
    }

    /**
     * @param mixed $value
     */
    private function shouldSkipBlankPersistValue(Entity $entity, string $field, $value): bool
    {
        if ($value !== null && (!is_string($value) || trim($value) !== '')) {
            if (is_array($value)) {
                return false;
            }

            return false;
        }

        return $this->hasNonBlankValue($entity->getFetched($field));
    }

    /**
     * @param mixed $value
     */
    private function hasNonBlankValue($value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    private function persistProfissionalAfterCreate(Entity $entity): Entity
    {
        try {
            $this->entityManager->saveEntity($entity, [
                SaveOption::SILENT => true,
                SaveOption::SKIP_HOOKS => true,
                SaveOption::SKIP_MODIFIED_BY => true,
            ]);

            return $entity;
        } catch (Throwable $e) {
            if (!$this->isDuplicateConstraintViolation($e)) {
                throw $e;
            }

            $profissionalId = $this->normalizeNullableString($entity->get('profissionalId'));
            $credentialId = $this->normalizeNullableString($entity->get('credentialId'));

            if (!$profissionalId || !$credentialId) {
                throw $e;
            }

            $existing = $this->findProfissionalAnchorIncludingDeleted($profissionalId, $credentialId);

            if (!$existing) {
                throw $e;
            }

            $existing = $this->restoreProfissionalIfDeleted($existing);

            if (!$existing) {
                throw $e;
            }

            $this->mergeTeamsIntoProfissionalAnchor($existing, $this->extractTeamIdList($entity));

            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensProfissional: duplicate profissional anchor recovered for profissionalId '" .
                $profissionalId . "' and credential '" . $credentialId . "'."
            );

            return $existing;
        }
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableString($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function extractRawProfissionalIdFromInput(stdClass $data): ?string
    {
        if (!property_exists($data, 'profissionalId')) {
            return null;
        }

        return $this->normalizeNullableString($data->profissionalId);
    }

    private function extractRawPessoaIdFromInput(stdClass $data): ?string
    {
        if (property_exists($data, 'idPessoa')) {
            $idPessoa = $this->normalizeNullableString($data->idPessoa);

            if ($idPessoa !== null) {
                return $idPessoa;
            }
        }

        if (property_exists($data, 'idPessoaExecutor')) {
            return $this->normalizeNullableString($data->idPessoaExecutor);
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function extractTeamIdListFromInput(stdClass $data): array
    {
        if (!property_exists($data, 'teamsIds') || !is_array($data->teamsIds)) {
            return [];
        }

        return array_values(array_filter($data->teamsIds, fn ($id) => is_string($id) && trim($id) !== ''));
    }

    private function isDuplicateConstraintViolation(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        $code = (string) $e->getCode();

        if ($code === '23000') {
            return true;
        }

        if (
            str_contains($message, 'duplicate') ||
            str_contains($message, 'unique') ||
            str_contains($message, 'integrity constraint')
        ) {
            return true;
        }

        $previous = $e->getPrevious();

        if ($previous instanceof Throwable) {
            return $this->isDuplicateConstraintViolation($previous);
        }

        return false;
    }

    /**
     * @param string[] $teamIdList
     */
    private function resolveAccessibleCredentialFromInput(stdClass $data, array $teamIdList): ?Entity
    {
        $credential = $this->getCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);

        if ($credential) {
            return $credential;
        }

        if (!property_exists($data, 'credentialId')) {
            return null;
        }

        $credentialId = $this->normalizeNullableString($data->credentialId);

        if (!$credentialId) {
            return null;
        }

        $credentialMap = $this->getCredentialHelper()->getAccessibleCredentialMapByIds([$credentialId]);

        return $credentialMap[$credentialId] ?? null;
    }

    /**
     * @param mixed $value
     */
    private function isExpectedTipoExecutor($value): bool
    {
        $tipoExecutor = $this->normalizeNullableString($value);

        if ($tipoExecutor === null) {
            return false;
        }

        return strtoupper($tipoExecutor) === self::EXPECTED_TIPO_EXECUTOR;
    }
}
