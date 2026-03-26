<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services;

use Espo\Core\Di;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Record\Collection as RecordCollection;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\FindParams;
use Espo\Core\Record\ReadParams;
use Espo\Core\Record\Service as RecordService;
use Espo\Core\Select\SearchParams;
use Espo\ORM\Entity;
use stdClass;
use Throwable;

/**
 * @extends RecordService<Entity>
 */
class FeatureIntegrationClinicaNasNuvensConsultaTipo extends RecordService implements
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
        'consultaTipoId',
        'credential',
        'credentialId',
        'syncStatus',
        'ativo',
        'reconsulta',
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
        'ativo',
        'reconsulta',
    ];

    public function read(string $id, ReadParams $params): Entity
    {
        $entity = parent::read($id, $params);

        $this->enrichEntities([$entity], true);

        return $entity;
    }

    public function find(SearchParams $searchParams, ?FindParams $params = null): RecordCollection
    {
        $this->assertSearchParamsUseStorableFields($searchParams);

        return parent::find($searchParams, $params);
    }

    public function create(stdClass $data, CreateParams $params): Entity
    {
        $rawConsultaTipoId = $this->extractRawConsultaTipoIdFromInput($data);
        $rawTeamIdList = $this->extractTeamIdListFromInput($data);
        $preCreateCredential = null;

        if ($rawConsultaTipoId) {
            $preCreateCredential = $this->resolveAccessibleCredentialFromInput($data, $rawTeamIdList);

            if ($preCreateCredential) {
                $existing = $this->findConsultaTipoAnchorIncludingDeleted($rawConsultaTipoId, $preCreateCredential->getId());

                if ($existing) {
                    $existing = $this->restoreConsultaTipoIfDeleted($existing);

                    if ($existing) {
                        $this->mergeTeamsIntoConsultaTipoAnchor($existing, $rawTeamIdList);
                        $this->enrichEntities([$existing], true);

                        return $existing;
                    }
                }
            }

            $this->assertConsultaTipoExistsBeforeCreate($rawConsultaTipoId, $preCreateCredential);
        }

        $entity = parent::create($data, $params);

        $this->enrichEntities([$entity], true);
        $entity = $this->persistConsultaTipoAfterCreate($entity);

        return $entity;
    }

    public function hydrateAfterImport(string $id): void
    {
        $entity = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensConsultaTipo', $id);

        if (!$entity) {
            return;
        }

        $this->enrichEntities([$entity], true);
    }

    private function assertConsultaTipoExistsBeforeCreate(string $consultaTipoId, ?Entity $credential): void
    {
        if (!$credential) {
            throw new BadRequest(
                "Cannot create consulta tipo anchor '{$consultaTipoId}' without an accessible CNN credential."
            );
        }

        try {
            $payload = $this->getApiClient()->getTipoConsultaById($credential, $consultaTipoId);
        } catch (Throwable $e) {
            throw new BadRequest(
                "Cannot create consulta tipo anchor '{$consultaTipoId}': invalid or non-existent remote consulta tipo ID.",
                previous: $e
            );
        }

        $resolvedConsultaTipoId = $this->normalizeNullableString($payload['consultaTipoId'] ?? null);

        if (!$resolvedConsultaTipoId || $resolvedConsultaTipoId !== $consultaTipoId) {
            throw new BadRequest(
                "Cannot create consulta tipo anchor '{$consultaTipoId}': invalid or non-existent remote consulta tipo ID."
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
        /** @var array<string, array<string, Entity[]>> $grouped */
        $grouped = [];

        /** @var array<string, Entity> $selectedCredentialMap */
        $selectedCredentialMap = [];

        foreach ($entities as $entity) {
            $consultaTipoId = $entity->get('consultaTipoId');

            if (!$consultaTipoId) {
                continue;
            }

            $teamIdList = $this->extractTeamIdList($entity);
            $credential = $this->getCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);

            if (!$credential) {
                $entity->set('syncStatus', 'error');

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensConsultaTipo: no accessible CNN credential for consulta tipo '" .
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

            if (!isset($grouped[$credentialId][$consultaTipoId])) {
                $grouped[$credentialId][$consultaTipoId] = [];
            }

            $grouped[$credentialId][$consultaTipoId][] = $entity;
        }

        if ($grouped === []) {
            return;
        }

        foreach ($grouped as $credentialId => $consultaTipoMap) {
            $credential = $selectedCredentialMap[$credentialId] ?? null;

            if (!$credential) {
                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensConsultaTipo: credential '{$credentialId}' not accessible."
                );

                continue;
            }

            $consultaTipoIdList = array_keys($consultaTipoMap);
            $chunks = array_chunk($consultaTipoIdList, self::ENRICHMENT_BATCH_SIZE);

            foreach ($chunks as $chunk) {
                foreach ($chunk as $consultaTipoId) {
                    try {
                        $payload = $this->getApiClient()->getTipoConsultaById($credential, $consultaTipoId);

                        foreach ($consultaTipoMap[$consultaTipoId] as $entity) {
                            foreach ($payload as $field => $value) {
                                if ($this->shouldSkipBlankHydrationValue($entity, $field, $value)) {
                                    continue;
                                }

                                if (is_array($value) || is_object($value)) {
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
                        foreach ($consultaTipoMap[$consultaTipoId] as $entity) {
                            $entity->set('syncStatus', 'error');
                        }

                        $this->log->warning(
                            "FeatureIntegrationClinicaNasNuvensConsultaTipo: failed to enrich consulta tipo '" .
                            $consultaTipoId . "' for credential '{$credentialId}': " . $e->getMessage()
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
                'entityType' => 'FeatureIntegrationClinicaNasNuvensConsultaTipo',
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

    private function findConsultaTipoAnchorIncludingDeleted(string $consultaTipoId, string $credentialId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensConsultaTipo')
            ->where([
                'consultaTipoId' => $consultaTipoId,
                'credentialId' => $credentialId,
            ])
            ->withDeleted()
            ->order('deleted', 'ASC')
            ->build();

        return $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensConsultaTipo')
            ->clone($query)
            ->findOne();
    }

    private function restoreConsultaTipoIfDeleted(Entity $consultaTipo): ?Entity
    {
        if (!$consultaTipo->get('deleted')) {
            return $consultaTipo;
        }

        $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensConsultaTipo')
            ->restoreDeleted($consultaTipo->getId());

        return $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensConsultaTipo', $consultaTipo->getId());
    }

    /**
     * @param string[] $teamIdList
     */
    private function mergeTeamsIntoConsultaTipoAnchor(Entity $consultaTipo, array $teamIdList): void
    {
        $existingTeamIdList = $this->extractTeamIdList($consultaTipo);
        $mergedTeamIdList = array_values(array_unique(array_merge($existingTeamIdList, $teamIdList)));

        if ($mergedTeamIdList === $existingTeamIdList) {
            return;
        }

        $consultaTipo->set('teamsIds', $mergedTeamIdList);

        $this->entityManager->saveEntity($consultaTipo, [
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
                "FeatureIntegrationClinicaNasNuvensConsultaTipo: failed to persist hydrated fields for '" .
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

    private function persistConsultaTipoAfterCreate(Entity $entity): Entity
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

            $consultaTipoId = $this->normalizeNullableString($entity->get('consultaTipoId'));
            $credentialId = $this->normalizeNullableString($entity->get('credentialId'));

            if (!$consultaTipoId || !$credentialId) {
                throw $e;
            }

            $existing = $this->findConsultaTipoAnchorIncludingDeleted($consultaTipoId, $credentialId);

            if (!$existing) {
                throw $e;
            }

            $existing = $this->restoreConsultaTipoIfDeleted($existing);

            if (!$existing) {
                throw $e;
            }

            $this->mergeTeamsIntoConsultaTipoAnchor($existing, $this->extractTeamIdList($entity));

            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensConsultaTipo: duplicate consulta tipo anchor recovered for consultaTipoId '" .
                $consultaTipoId . "' and credential '" . $credentialId . "'."
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

    private function extractRawConsultaTipoIdFromInput(stdClass $data): ?string
    {
        if (!property_exists($data, 'consultaTipoId')) {
            return null;
        }

        return $this->normalizeNullableString($data->consultaTipoId);
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
}
