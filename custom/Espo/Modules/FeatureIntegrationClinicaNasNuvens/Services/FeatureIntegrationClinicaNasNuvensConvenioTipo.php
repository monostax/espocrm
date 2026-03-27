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
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensIntegrationProfileResolver;
use Espo\ORM\Entity;
use stdClass;
use Throwable;

/**
 * @extends RecordService<Entity>
 */
class FeatureIntegrationClinicaNasNuvensConvenioTipo extends RecordService implements
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
        'convenioTipoId',
        'credential',
        'credentialId',
        'syncStatus',
        'ativo',
        'beneficio',
        'particular',
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
        'beneficio',
        'particular',
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

        $rawConvenioTipoId = $this->extractRawConvenioTipoIdFromInput($data);
        $rawTeamIdList = $this->extractTeamIdListFromInput($data);
        $preCreateCredential = null;

        if ($rawConvenioTipoId) {
            $preCreateCredential = $this->resolveAccessibleCredentialFromInput($data, $rawTeamIdList);

            if ($preCreateCredential) {
                $existing = $this->findConvenioTipoAnchorIncludingDeleted($rawConvenioTipoId, $preCreateCredential->getId());

                if ($existing) {
                    $existing = $this->restoreConvenioTipoIfDeleted($existing);

                    if ($existing) {
                        $this->mergeTeamsIntoConvenioTipoAnchor($existing, $rawTeamIdList);
                        $this->enrichEntities([$existing], true);

                        return $existing;
                    }
                }
            }

            $this->assertConvenioTipoExistsBeforeCreate($rawConvenioTipoId, $preCreateCredential);
        }

        $entity = parent::create($data, $params);

        $this->enrichEntities([$entity], true);
        $entity = $this->persistConvenioTipoAfterCreate($entity);

        return $entity;
    }

    public function hydrateAfterImport(string $id): void
    {
        $entity = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensConvenioTipo', $id);

        if (!$entity) {
            return;
        }

        $this->enrichEntities([$entity], true);
    }

    private function assertConvenioTipoExistsBeforeCreate(string $convenioTipoId, ?Entity $credential): void
    {
        if (!$credential) {
            throw new BadRequest(
                "Cannot create convenio tipo anchor '{$convenioTipoId}' without an accessible CNN credential."
            );
        }

        try {
            $payload = $this->getApiClient()->getTipoConvenioById($credential, $convenioTipoId);
        } catch (Throwable $e) {
            throw new BadRequest(
                "Cannot create convenio tipo anchor '{$convenioTipoId}': invalid or non-existent remote convenio tipo ID.",
                previous: $e
            );
        }

        $resolvedConvenioTipoId = $this->normalizeNullableString($payload['convenioTipoId'] ?? null);

        if (!$resolvedConvenioTipoId || $resolvedConvenioTipoId !== $convenioTipoId) {
            throw new BadRequest(
                "Cannot create convenio tipo anchor '{$convenioTipoId}': invalid or non-existent remote convenio tipo ID."
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
            $convenioTipoId = $entity->get('convenioTipoId');

            if (!$convenioTipoId) {
                continue;
            }

            $teamIdList = $this->extractTeamIdList($entity);
            $credential = $this->getCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);

            if (!$credential) {
                $entity->set('syncStatus', 'error');

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensConvenioTipo: no accessible CNN credential for convenio tipo '" .
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

            if (!isset($grouped[$credentialId][$convenioTipoId])) {
                $grouped[$credentialId][$convenioTipoId] = [];
            }

            $grouped[$credentialId][$convenioTipoId][] = $entity;
        }

        if ($grouped === []) {
            return;
        }

        foreach ($grouped as $credentialId => $convenioTipoMap) {
            $credential = $selectedCredentialMap[$credentialId] ?? null;

            if (!$credential) {
                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensConvenioTipo: credential '{$credentialId}' not accessible."
                );

                continue;
            }

            $convenioTipoIdList = array_keys($convenioTipoMap);
            $chunks = array_chunk($convenioTipoIdList, self::ENRICHMENT_BATCH_SIZE);

            foreach ($chunks as $chunk) {
                foreach ($chunk as $convenioTipoId) {
                    try {
                        $payload = $this->getApiClient()->getTipoConvenioById($credential, $convenioTipoId);

                        foreach ($convenioTipoMap[$convenioTipoId] as $entity) {
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
                        foreach ($convenioTipoMap[$convenioTipoId] as $entity) {
                            $entity->set('syncStatus', 'error');
                        }

                        $this->log->warning(
                            "FeatureIntegrationClinicaNasNuvensConvenioTipo: failed to enrich convenio tipo '" .
                            $convenioTipoId . "' for credential '{$credentialId}': " . $e->getMessage()
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
                'entityType' => 'FeatureIntegrationClinicaNasNuvensConvenioTipo',
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

    private function findConvenioTipoAnchorIncludingDeleted(string $convenioTipoId, string $credentialId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensConvenioTipo')
            ->where([
                'convenioTipoId' => $convenioTipoId,
                'credentialId' => $credentialId,
            ])
            ->withDeleted()
            ->order('deleted', 'ASC')
            ->build();

        return $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensConvenioTipo')
            ->clone($query)
            ->findOne();
    }

    private function restoreConvenioTipoIfDeleted(Entity $convenioTipo): ?Entity
    {
        if (!$convenioTipo->get('deleted')) {
            return $convenioTipo;
        }

        $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensConvenioTipo')
            ->restoreDeleted($convenioTipo->getId());

        return $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensConvenioTipo', $convenioTipo->getId());
    }

    /**
     * @param string[] $teamIdList
     */
    private function mergeTeamsIntoConvenioTipoAnchor(Entity $convenioTipo, array $teamIdList): void
    {
        $existingTeamIdList = $this->extractTeamIdList($convenioTipo);
        $mergedTeamIdList = array_values(array_unique(array_merge($existingTeamIdList, $teamIdList)));

        if ($mergedTeamIdList === $existingTeamIdList) {
            return;
        }

        $convenioTipo->set('teamsIds', $mergedTeamIdList);

        $this->entityManager->saveEntity($convenioTipo, [
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
                "FeatureIntegrationClinicaNasNuvensConvenioTipo: failed to persist hydrated fields for '" .
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

    private function persistConvenioTipoAfterCreate(Entity $entity): Entity
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

            $convenioTipoId = $this->normalizeNullableString($entity->get('convenioTipoId'));
            $credentialId = $this->normalizeNullableString($entity->get('credentialId'));

            if (!$convenioTipoId || !$credentialId) {
                throw $e;
            }

            $existing = $this->findConvenioTipoAnchorIncludingDeleted($convenioTipoId, $credentialId);

            if (!$existing) {
                throw $e;
            }

            $existing = $this->restoreConvenioTipoIfDeleted($existing);

            if (!$existing) {
                throw $e;
            }

            $this->mergeTeamsIntoConvenioTipoAnchor($existing, $this->extractTeamIdList($entity));

            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensConvenioTipo: duplicate convenio tipo anchor recovered for convenioTipoId '" .
                $convenioTipoId . "' and credential '" . $credentialId . "'."
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

    private function extractRawConvenioTipoIdFromInput(stdClass $data): ?string
    {
        if (!property_exists($data, 'convenioTipoId')) {
            return null;
        }

        return $this->normalizeNullableString($data->convenioTipoId);
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
