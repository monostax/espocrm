<?php

namespace Espo\Modules\FeatureIntegrationMedx\Services;

use Espo\Core\Di;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\CreateResult;
use Espo\Core\Record\Collection as RecordCollection;
use Espo\Core\Record\FindParams;
use Espo\Core\Record\Service as RecordService;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Select\SearchParams;
use Espo\Modules\FeatureIntegrationMedx\Services\MedxIntegrationProfileResolver;
use Espo\ORM\Entity;
use stdClass;
use Throwable;

/**
 * Hybrid service for Cliente anchor rows.
 *
 * Enriches local records with remote MEDX API fields on list and detail reads.
 * Supports the same hybrid stored-database approach as ClinicaNasNuvensPaciente.
 *
 * @extends RecordService<Entity>
 */
class FeatureIntegrationMedxCliente extends RecordService implements
    Di\LogAware
{
    use Di\LogSetter;

    private const ENRICHMENT_BATCH_SIZE = 25;

    /**
     * @var string[]
     */
    private const ALLOWED_ORDER_FILTER_FIELDS = [
        'id',
        'name',
        'clienteId',
        'contact',
        'contactId',
        'credential',
        'credentialId',
        'syncStatus',
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
        'assinaturaId',
        'cpfCgc',
        'dataNascimento',
        'emailAddress',
        'celular',
        'telefoneResidencial',
        'telefoneResidencial1',
        'sexo',
        'nomeSocial',
        'estadoCivil',
        'profissao',
        'enderecoResidencial',
        'bairroResidencial',
        'cidadeResidencial',
        'estadoResidencial',
        'cepResidencial',
        'idDoConvenio',
        'convenio',
        'numeroMatricula',
        'numeroCns',
        'observacoes',
        'referencias',
        'tags',
        'vip',
        'malaDireta',
        'pendente',
        'excluiMkt',
        'indicadoPor',
        'comoConheceu',
        'escolaridade',
        'religiao',
    ];

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
            $data->credentialId = $resolved['webCredential']->getId();

            $rawTeamIdList = $this->loadProfileTeamIds($profile);
            $data->teamsIds = is_array($rawTeamIdList)
                ? array_values(array_filter($rawTeamIdList, fn ($id) => is_string($id) && trim($id) !== ''))
                : [];
        }

        $rawClienteId = $this->extractRawClienteIdFromInput($data);
        $rawTeamIdList = $this->extractTeamIdListFromInput($data);
        $preCreateCredential = null;

        if ($rawClienteId) {
            $preCreateCredential = $this->resolveAccessibleCredentialFromInput($data, $rawTeamIdList);

            if ($preCreateCredential) {
                $existing = $this->findClienteAnchorIncludingDeleted($rawClienteId, $preCreateCredential->getId());

                if ($existing) {
                    $existing = $this->restoreClienteIfDeleted($existing);

                    if ($existing) {
                        $this->mergeTeamsIntoClienteAnchor($existing, $rawTeamIdList);
                        $this->enrichEntities([$existing], true);

                        return new CreateResult($existing);
                    }
                }
            }

            $this->assertClienteExistsBeforeCreate($rawClienteId, $preCreateCredential);
        }

        $entity = parent::create($data, $params)->getEntity();

        $this->enrichEntities([$entity], true);
        $entity = $this->persistClienteAfterCreate($entity);

        return new CreateResult($entity);
    }

    public function hydrateAfterImport(string $id): void
    {
        $entity = $this->entityManager->getEntityById('FeatureIntegrationMedxCliente', $id);

        if (!$entity) {
            return;
        }

        $this->enrichEntities([$entity], true);
    }

    private function assertClienteExistsBeforeCreate(string $clienteId, ?Entity $credential): void
    {
        if (!$credential) {
            throw new BadRequest(
                "Cannot create cliente anchor '{$clienteId}' without an accessible MEDX credential."
            );
        }

        try {
            $payload = $this->getApiClient()->getClienteById($credential, $clienteId);
        } catch (Throwable $e) {
            throw new BadRequest(
                "Cannot create cliente anchor '{$clienteId}': invalid or non-existent remote cliente ID.",
                previous: $e
            );
        }

        if ($payload === []) {
            throw new BadRequest(
                "Cannot create cliente anchor '{$clienteId}': invalid or non-existent remote cliente ID."
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
        return;

        /** @var array<string, array<string, Entity[]>> $grouped */
        $grouped = [];

        /** @var array<string, Entity> $selectedCredentialMap */
        $selectedCredentialMap = [];

        foreach ($entities as $entity) {
            $clienteId = $entity->get('clienteId');

            if (!$clienteId) {
                continue;
            }

            $teamIdList = $this->extractTeamIdList($entity);
            $credential = $this->getCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);

            if (!$credential) {
                $entity->set('syncStatus', 'error');

                $this->log->warning(
                    "FeatureIntegrationMedxCliente: no accessible MEDX credential for cliente '" .
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

            if (!isset($grouped[$credentialId][$clienteId])) {
                $grouped[$credentialId][$clienteId] = [];
            }

            $grouped[$credentialId][$clienteId][] = $entity;
        }

        if ($grouped === []) {
            return;
        }

        foreach ($grouped as $credentialId => $clienteMap) {
            $credential = $selectedCredentialMap[$credentialId] ?? null;

            if (!$credential) {
                $this->log->warning(
                    "FeatureIntegrationMedxCliente: credential '{$credentialId}' not accessible."
                );

                continue;
            }

            $clienteIdList = array_keys($clienteMap);
            $chunks = array_chunk($clienteIdList, self::ENRICHMENT_BATCH_SIZE);

            foreach ($chunks as $chunk) {
                foreach ($chunk as $clienteId) {
                    try {
                        $payload = $this->getApiClient()->getClienteById($credential, $clienteId);

                        foreach ($clienteMap[$clienteId] as $entity) {
                            foreach ($payload as $field => $value) {
                                if ($this->shouldSkipBlankHydrationValue($entity, $field, $value)) {
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
                        foreach ($clienteMap[$clienteId] as $entity) {
                            $entity->set('syncStatus', 'error');
                        }

                        $this->log->warning(
                            "FeatureIntegrationMedxCliente: failed to enrich cliente '{$clienteId}' " .
                            "for credential '{$credentialId}': " . $e->getMessage()
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
                'entityType' => 'FeatureIntegrationMedxCliente',
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

    private function findClienteAnchorIncludingDeleted(string $clienteId, string $credentialId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationMedxCliente')
            ->where([
                'clienteId' => $clienteId,
                'credentialId' => $credentialId,
            ])
            ->withDeleted()
            ->order('deleted', 'ASC')
            ->build();

        return $this->entityManager
            ->getRDBRepository('FeatureIntegrationMedxCliente')
            ->clone($query)
            ->findOne();
    }

    private function restoreClienteIfDeleted(Entity $cliente): ?Entity
    {
        if (!$cliente->get('deleted')) {
            return $cliente;
        }

        $this->entityManager
            ->getRDBRepository('FeatureIntegrationMedxCliente')
            ->restoreDeleted($cliente->getId());

        return $this->entityManager->getEntityById('FeatureIntegrationMedxCliente', $cliente->getId());
    }

    /**
     * @param string[] $teamIdList
     */
    private function mergeTeamsIntoClienteAnchor(Entity $cliente, array $teamIdList): void
    {
        $existingTeamIdList = $this->extractTeamIdList($cliente);
        $mergedTeamIdList = array_values(array_unique(array_merge($existingTeamIdList, $teamIdList)));

        if ($mergedTeamIdList === $existingTeamIdList) {
            return;
        }

        $cliente->set('teamsIds', $mergedTeamIdList);

        $this->entityManager->saveEntity($cliente, [
            SaveOption::SILENT => true,
            SaveOption::SKIP_HOOKS => true,
            SaveOption::SKIP_MODIFIED_BY => true,
        ]);
    }

    private function getApiClient(): MedxApiClient
    {
        return $this->injectableFactory->create(MedxApiClient::class);
    }

    private function getCredentialHelper(): MedxCredentialHelper
    {
        return $this->injectableFactory->create(MedxCredentialHelper::class);
    }

    private function getProfileResolver(): MedxIntegrationProfileResolver
    {
        return $this->injectableFactory->create(MedxIntegrationProfileResolver::class);
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

            if (is_array($value) || is_object($value)) {
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
                "FeatureIntegrationMedxCliente: failed to persist hydrated fields for '" .
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

    private function persistClienteAfterCreate(Entity $entity): Entity
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

            $clienteId = $this->normalizeNullableString($entity->get('clienteId'));
            $credentialId = $this->normalizeNullableString($entity->get('credentialId'));

            if (!$clienteId || !$credentialId) {
                throw $e;
            }

            $existing = $this->findClienteAnchorIncludingDeleted($clienteId, $credentialId);

            if (!$existing) {
                throw $e;
            }

            $existing = $this->restoreClienteIfDeleted($existing);

            if (!$existing) {
                throw $e;
            }

            $this->mergeTeamsIntoClienteAnchor($existing, $this->extractTeamIdList($entity));

            $this->log->warning(
                "FeatureIntegrationMedxCliente: duplicate cliente anchor recovered for clienteId '" .
                $clienteId . "' and credential '" . $credentialId . "'."
            );

            return $existing;
        }
    }

    private function normalizeNullableString($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function extractRawClienteIdFromInput(stdClass $data): ?string
    {
        if (!property_exists($data, 'clienteId')) {
            return null;
        }

        return $this->normalizeNullableString($data->clienteId);
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
     * @param Entity[] $entities
     */
    private function loadProfileTeamIds(Entity $entity): array
    {
        $teamIdList = $entity->get('teamsIds');

        if (is_array($teamIdList) && $teamIdList !== []) {
            return array_values(array_filter($teamIdList, fn ($id) => is_string($id) && $id !== ''));
        }

        $settingsId = $this->normalizeNullableString($entity->get('settingsId'));

        if (!$settingsId) {
            return [];
        }

        $profile = $this->entityManager->getEntityById('FeatureIntegrationMedxSettings', $settingsId);

        if (!$profile) {
            return [];
        }

        $relation = $this->entityManager
            ->getRDBRepository('FeatureIntegrationMedxSettings')
            ->getRelation($profile, 'teams');

        $result = [];

        foreach ($relation->find() as $team) {
            $teamId = $team->getId();

            if (is_string($teamId) && $teamId !== '') {
                $result[] = $teamId;
            }
        }

        return array_values(array_unique($result));
    }
}
