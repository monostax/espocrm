<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services;

use Espo\Core\Di;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
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
class FeatureIntegrationClinicaNasNuvensProcedimentoTipo extends RecordService implements
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
        'procedimentoTipoId',
        'credential',
        'credentialId',
        'syncStatus',
        'ativo',
        'especialidades',
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
        'especialidades',
    ];

    /**
     * @var string[]
     */
    private const JSON_ARRAY_REMOTE_FIELDS = [
        'especialidades',
    ];

    private const PROCEDIMENTO_TIPO_CONVENIO_ENTITY_TYPE =
        'FeatureIntegrationClinicaNasNuvensProcedimentoConvenio';

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
        $rawProcedimentoTipoId = $this->extractRawProcedimentoTipoIdFromInput($data);
        $rawTeamIdList = $this->extractTeamIdListFromInput($data);
        $preCreateCredential = null;

        if ($rawProcedimentoTipoId) {
            $preCreateCredential = $this->resolveAccessibleCredentialFromInput($data, $rawTeamIdList);

            if ($preCreateCredential) {
                $existing = $this->findProcedimentoTipoAnchorIncludingDeleted(
                    $rawProcedimentoTipoId,
                    $preCreateCredential->getId(),
                );

                if ($existing) {
                    $existing = $this->restoreProcedimentoTipoIfDeleted($existing);

                    if ($existing) {
                        $this->mergeTeamsIntoProcedimentoTipoAnchor($existing, $rawTeamIdList);
                        $this->enrichEntities([$existing], true);

                        return $existing;
                    }
                }
            }

            $this->assertProcedimentoTipoExistsBeforeCreate($rawProcedimentoTipoId, $preCreateCredential);
        }

        $entity = parent::create($data, $params);

        $this->enrichEntities([$entity], true);
        $entity = $this->persistProcedimentoTipoAfterCreate($entity);

        return $entity;
    }

    public function hydrateAfterImport(string $id): void
    {
        $entity = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensProcedimentoTipo', $id);

        if (!$entity) {
            return;
        }

        $this->enrichEntities([$entity], true);
    }

    private function assertProcedimentoTipoExistsBeforeCreate(string $procedimentoTipoId, ?Entity $credential): void
    {
        if (!$credential) {
            throw new BadRequest(
                "Cannot create procedimento tipo anchor '{$procedimentoTipoId}' without an accessible CNN credential."
            );
        }

        try {
            $payload = $this->getApiClient()->getTipoProcedimentoById($credential, $procedimentoTipoId);
        } catch (Throwable $e) {
            throw new BadRequest(
                "Cannot create procedimento tipo anchor '{$procedimentoTipoId}': invalid or non-existent remote procedimento tipo ID.",
                previous: $e
            );
        }

        $resolvedProcedimentoTipoId = $this->normalizeNullableString($payload['procedimentoTipoId'] ?? null);

        if (!$resolvedProcedimentoTipoId || $resolvedProcedimentoTipoId !== $procedimentoTipoId) {
            throw new BadRequest(
                "Cannot create procedimento tipo anchor '{$procedimentoTipoId}': invalid or non-existent remote procedimento tipo ID."
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

        /** @var array<int, array{teamIdList: string[], webCredential: ?Entity}> $contextMap */
        $contextMap = [];

        foreach ($entities as $entity) {
            $procedimentoTipoId = $entity->get('procedimentoTipoId');

            if (!$procedimentoTipoId) {
                continue;
            }

            $teamIdList = $this->extractTeamIdList($entity);
            $context = null;

            try {
                $context = $this->resolvePairedCredentialContext($teamIdList);
            } catch (Throwable $e) {
                $entity->set('syncStatus', 'error');

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensProcedimentoTipo: invalid credential pairing for procedimento tipo '" .
                    $entity->getId() . "': " . $e->getMessage()
                );

                continue;
            }

            $credential = $context['apiCredential'] ?? null;

            if (!$credential instanceof Entity) {
                $entity->set('syncStatus', 'error');

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensProcedimentoTipo: no accessible CNN credential for procedimento tipo '" .
                    $entity->getId() . "' teams."
                );

                continue;
            }

            $credentialId = $credential->getId();

            if (!is_string($credentialId) || $credentialId === '') {
                $entity->set('syncStatus', 'error');

                continue;
            }

            $selectedCredentialMap[$credentialId] = $credential;
            $contextMap[spl_object_id($entity)] = [
                'teamIdList' => $teamIdList,
                'webCredential' => $context['webCredential'] ?? null,
            ];

            $entity->set('credentialId', $credentialId);
            $entity->set('credentialName', $credential->get('name'));

            if (!isset($grouped[$credentialId])) {
                $grouped[$credentialId] = [];
            }

            if (!isset($grouped[$credentialId][$procedimentoTipoId])) {
                $grouped[$credentialId][$procedimentoTipoId] = [];
            }

            $grouped[$credentialId][$procedimentoTipoId][] = $entity;
        }

        if ($grouped === []) {
            return;
        }

        foreach ($grouped as $credentialId => $procedimentoTipoMap) {
            $credential = $selectedCredentialMap[$credentialId] ?? null;

            if (!$credential) {
                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensProcedimentoTipo: credential '{$credentialId}' not accessible."
                );

                continue;
            }

            $procedimentoTipoIdList = array_keys($procedimentoTipoMap);
            $chunks = array_chunk($procedimentoTipoIdList, self::ENRICHMENT_BATCH_SIZE);

            foreach ($chunks as $chunk) {
                foreach ($chunk as $procedimentoTipoId) {
                    try {
                        $payload = $this->getApiClient()->getTipoProcedimentoById($credential, $procedimentoTipoId);

                        foreach ($procedimentoTipoMap[$procedimentoTipoId] as $entity) {
                            foreach ($payload as $field => $value) {
                                if ($this->shouldSkipBlankHydrationValue($entity, $field, $value)) {
                                    continue;
                                }

                                if (is_array($value) && !in_array($field, self::JSON_ARRAY_REMOTE_FIELDS, true)) {
                                    continue;
                                }

                                if (is_object($value)) {
                                    continue;
                                }

                                $entity->set($field, $value);
                            }

                            $entity->set('syncStatus', 'synced');

                            if ($persist) {
                                $this->persistHydratedFields($entity, $payload, 'synced');

                                $context = $contextMap[spl_object_id($entity)] ?? null;
                                $webCredential = $context['webCredential'] ?? null;
                                $teamIdList = $context['teamIdList'] ?? [];

                                if ($webCredential instanceof Entity) {
                                    try {
                                        $this->syncProcedimentoConvenioPricing($entity, $webCredential, $credential, $teamIdList);
                                    } catch (Throwable $e) {
                                        $this->log->warning(
                                            "FeatureIntegrationClinicaNasNuvensProcedimentoTipo: pricing sync failed for procedimento tipo '" .
                                            $procedimentoTipoId . "': " . $e->getMessage()
                                        );
                                    }
                                } else {
                                    $this->log->warning(
                                        "FeatureIntegrationClinicaNasNuvensProcedimentoTipo: API hydration succeeded but no paired web credential " .
                                        "for pricing sync on procedimento tipo '{$procedimentoTipoId}'."
                                    );
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        foreach ($procedimentoTipoMap[$procedimentoTipoId] as $entity) {
                            $entity->set('syncStatus', 'error');
                        }

                        $this->log->warning(
                            "FeatureIntegrationClinicaNasNuvensProcedimentoTipo: failed to enrich procedimento tipo '" .
                            $procedimentoTipoId . "' for credential '{$credentialId}': " . $e->getMessage()
                        );
                    }
                }
            }
        }
    }

    /**
     * @param string[] $teamIdList
     * @return array{apiCredential: ?Entity, webCredential: ?Entity}
     */
    private function resolvePairedCredentialContext(array $teamIdList): array
    {
        foreach ($teamIdList as $teamId) {
            if (!is_string($teamId) || $teamId === '') {
                continue;
            }

            $apiCredential = $this->getCredentialHelper()->findAccessibleCredentialForTeamIds([$teamId]);
            $webCredential = $this->getWebCredentialHelper()->findAccessibleCredentialForTeamIds([$teamId]);

            if ($apiCredential instanceof Entity && $webCredential instanceof Entity) {
                return [
                    'apiCredential' => $apiCredential,
                    'webCredential' => $webCredential,
                ];
            }
        }

        $apiCredential = $this->getCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);

        if (!$apiCredential instanceof Entity) {
            return [
                'apiCredential' => null,
                'webCredential' => null,
            ];
        }

        $webCredential = $this->getWebCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);

        if ($webCredential instanceof Entity) {
            throw new Error(
                'API and web credentials from different accounts for teams [' . implode(', ', $teamIdList) . '].'
            );
        }

        return [
            'apiCredential' => $apiCredential,
            'webCredential' => null,
        ];
    }

    /**
     * @param string[] $teamIdList
     */
    private function upsertConvenioTipoFromRemoteId(string $convenioTipoId, array $teamIdList, Entity $apiCredential): Entity
    {
        $service = $this->recordServiceContainer->get('FeatureIntegrationClinicaNasNuvensConvenioTipo');

        return $service->create((object) [
            'convenioTipoId' => $convenioTipoId,
            'teamsIds' => $teamIdList,
            'credentialId' => $apiCredential->getId(),
        ], CreateParams::create());
    }

    /**
     * @param string[] $teamIdList
     */
    private function syncProcedimentoConvenioPricing(
        Entity $procedimentoTipoEntity,
        Entity $webCredential,
        Entity $apiCredential,
        array $teamIdList,
    ): void {
        $procedimentoTipoRemoteId = $this->normalizeNullableString($procedimentoTipoEntity->get('procedimentoTipoId'));
        $procedimentoTipoLocalId = $this->normalizeNullableString($procedimentoTipoEntity->getId());

        if (!$procedimentoTipoRemoteId || !$procedimentoTipoLocalId) {
            return;
        }

        $response = $this->getWebClient()->getProcedimentoConvenioPricingByProcedimentoId(
            $webCredential,
            $procedimentoTipoRemoteId,
        );
        $rows = is_array($response['rows'] ?? null) ? $response['rows'] : [];

        $rowsUpserted = 0;
        $rowsSoftDeleted = 0;
        $convenioUpserted = 0;
        $errors = 0;

        /** @var string[] $processedConvenioTipoIdList */
        $processedConvenioTipoIdList = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $errors++;

                continue;
            }

            try {
                $convenioTipoRemoteId = $this->normalizeNullableString($row['codigoTipoConvenio'] ?? null);

                if ($convenioTipoRemoteId === null) {
                    $errors++;

                    continue;
                }

                $convenioTipo = $this->upsertConvenioTipoFromRemoteId($convenioTipoRemoteId, $teamIdList, $apiCredential);
                $convenioUpserted++;

                $convenioTipoLocalId = $this->normalizeNullableString($convenioTipo->getId());

                if ($convenioTipoLocalId === null) {
                    $errors++;

                    continue;
                }

                $processedConvenioTipoIdList[] = $convenioTipoLocalId;

                $child = $this->findProcedimentoTipoConvenioIncludingDeleted(
                    $procedimentoTipoLocalId,
                    $convenioTipoLocalId,
                );

                if (!$child) {
                    $child = $this->createProcedimentoTipoConvenioWithConflictRecovery(
                        $procedimentoTipoEntity,
                        $convenioTipo,
                        $teamIdList,
                    );
                }

                if (!$child) {
                    $errors++;

                    continue;
                }

                if ((bool) $child->get('deleted')) {
                    $this->entityManager
                        ->getRDBRepository(self::PROCEDIMENTO_TIPO_CONVENIO_ENTITY_TYPE)
                        ->restoreDeleted($child->getId());

                    $reloaded = $this->entityManager->getEntityById(self::PROCEDIMENTO_TIPO_CONVENIO_ENTITY_TYPE, $child->getId());

                    if ($reloaded instanceof Entity) {
                        $child = $reloaded;
                    }
                }

                $child->set([
                    'procedimentoTipoId' => $procedimentoTipoLocalId,
                    'convenioTipoId' => $convenioTipoLocalId,
                    'codigoTipoProcedimentoConvenio' => $row['codigoTipoProcedimentoConvenio'] ?? null,
                    'isActive' => (bool) ($row['isActive'] ?? false),
                    'precoPaciente' => $row['precoPaciente'] ?? null,
                    'precoPacienteCurrency' => 'BRL',
                    'precoConvenio' => $row['precoConvenio'] ?? null,
                    'precoConvenioCurrency' => 'BRL',
                    'convenioName' => $this->normalizeNullableString($convenioTipo->get('name')),
                    'teamsIds' => $teamIdList,
                ]);

                $this->entityManager->saveEntity($child, [
                    SaveOption::SILENT => true,
                    SaveOption::SKIP_MODIFIED_BY => true,
                ]);

                $rowsUpserted++;
            } catch (Throwable $e) {
                $errors++;

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensProcedimentoTipo: failed to process convenio pricing row for procedimento tipo '" .
                    $procedimentoTipoRemoteId . "': " . $e->getMessage()
                );
            }
        }

        $processedConvenioTipoIdList = array_values(array_unique($processedConvenioTipoIdList));

        $activeRows = $this->entityManager
            ->getRDBRepository(self::PROCEDIMENTO_TIPO_CONVENIO_ENTITY_TYPE)
            ->where([
                'procedimentoTipoId' => $procedimentoTipoLocalId,
                'deleted' => false,
            ])
            ->find();

        foreach ($activeRows as $activeRow) {
            $convenioTipoId = $this->normalizeNullableString($activeRow->get('convenioTipoId'));

            if ($convenioTipoId !== null && in_array($convenioTipoId, $processedConvenioTipoIdList, true)) {
                continue;
            }

            $this->entityManager->removeEntity($activeRow, [
                SaveOption::SILENT => true,
                SaveOption::SKIP_MODIFIED_BY => true,
            ]);

            $rowsSoftDeleted++;
        }

        $this->log->info(
            "FeatureIntegrationClinicaNasNuvensProcedimentoTipo: pricing sync completed for procedimento tipo '{$procedimentoTipoRemoteId}'.",
            [
                'rowsUpserted' => $rowsUpserted,
                'rowsSoftDeleted' => $rowsSoftDeleted,
                'convenioUpserted' => $convenioUpserted,
                'errors' => $errors,
            ]
        );
    }

    private function findProcedimentoTipoConvenioIncludingDeleted(string $procedimentoTipoId, string $convenioTipoId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from(self::PROCEDIMENTO_TIPO_CONVENIO_ENTITY_TYPE)
            ->where([
                'procedimentoTipoId' => $procedimentoTipoId,
                'convenioTipoId' => $convenioTipoId,
            ])
            ->withDeleted()
            ->build();

        return $this->entityManager
            ->getRDBRepository(self::PROCEDIMENTO_TIPO_CONVENIO_ENTITY_TYPE)
            ->clone($query)
            ->findOne();
    }

    /**
     * @param string[] $teamIdList
     */
    private function createProcedimentoTipoConvenioWithConflictRecovery(
        Entity $procedimentoTipoEntity,
        Entity $convenioTipoEntity,
        array $teamIdList,
    ): ?Entity {
        $procedimentoTipoId = $this->normalizeNullableString($procedimentoTipoEntity->getId());
        $convenioTipoId = $this->normalizeNullableString($convenioTipoEntity->getId());

        if ($procedimentoTipoId === null || $convenioTipoId === null) {
            return null;
        }

        try {
            return $this->entityManager->createEntity(self::PROCEDIMENTO_TIPO_CONVENIO_ENTITY_TYPE, [
                'procedimentoTipoId' => $procedimentoTipoId,
                'convenioTipoId' => $convenioTipoId,
                'teamsIds' => $teamIdList,
                'precoPacienteCurrency' => 'BRL',
                'precoConvenioCurrency' => 'BRL',
            ], [
                SaveOption::SILENT => true,
            ]);
        } catch (Throwable $e) {
            if (!$this->isDuplicateConstraintViolation($e)) {
                throw $e;
            }

            return $this->findProcedimentoTipoConvenioIncludingDeleted($procedimentoTipoId, $convenioTipoId);
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
                'entityType' => 'FeatureIntegrationClinicaNasNuvensProcedimentoTipo',
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

    private function findProcedimentoTipoAnchorIncludingDeleted(string $procedimentoTipoId, string $credentialId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensProcedimentoTipo')
            ->where([
                'procedimentoTipoId' => $procedimentoTipoId,
                'credentialId' => $credentialId,
            ])
            ->withDeleted()
            ->build();

        return $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProcedimentoTipo')
            ->clone($query)
            ->findOne();
    }

    private function restoreProcedimentoTipoIfDeleted(Entity $procedimentoTipo): ?Entity
    {
        if (!$procedimentoTipo->get('deleted')) {
            return $procedimentoTipo;
        }

        $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProcedimentoTipo')
            ->restoreDeleted($procedimentoTipo->getId());

        return $this->entityManager->getEntityById(
            'FeatureIntegrationClinicaNasNuvensProcedimentoTipo',
            $procedimentoTipo->getId(),
        );
    }

    /**
     * @param string[] $teamIdList
     */
    private function mergeTeamsIntoProcedimentoTipoAnchor(Entity $procedimentoTipo, array $teamIdList): void
    {
        $existingTeamIdList = $this->extractTeamIdList($procedimentoTipo);
        $mergedTeamIdList = array_values(array_unique(array_merge($existingTeamIdList, $teamIdList)));

        if ($mergedTeamIdList === $existingTeamIdList) {
            return;
        }

        $procedimentoTipo->set('teamsIds', $mergedTeamIdList);

        $this->entityManager->saveEntity($procedimentoTipo, [
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

    private function getWebClient(): ClinicaNasNuvensWebClient
    {
        return $this->injectableFactory->create(ClinicaNasNuvensWebClient::class);
    }

    private function getWebCredentialHelper(): ClinicaNasNuvensWebCredentialHelper
    {
        return $this->injectableFactory->create(ClinicaNasNuvensWebCredentialHelper::class);
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

            if (is_array($value)) {
                if (!in_array($field, self::JSON_ARRAY_REMOTE_FIELDS, true)) {
                    continue;
                }

                $currentValue = $entity->getFetched($field);

                if (!$this->areJsonArraysEqual($currentValue, $value)) {
                    $toPersist[$field] = $value;
                }

                continue;
            }

            if (is_object($value)) {
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
                "FeatureIntegrationClinicaNasNuvensProcedimentoTipo: failed to persist hydrated fields for '" .
                $entity->getId() . "': " . $e->getMessage()
            );
        }
    }

    /**
     * @param mixed $left
     * @param mixed[] $right
     */
    private function areJsonArraysEqual($left, array $right): bool
    {
        if (!is_array($left)) {
            return false;
        }

        return json_encode($left) === json_encode($right);
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

    private function persistProcedimentoTipoAfterCreate(Entity $entity): Entity
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

            $procedimentoTipoId = $this->normalizeNullableString($entity->get('procedimentoTipoId'));
            $credentialId = $this->normalizeNullableString($entity->get('credentialId'));

            if (!$procedimentoTipoId || !$credentialId) {
                throw $e;
            }

            $existing = $this->findProcedimentoTipoAnchorIncludingDeleted($procedimentoTipoId, $credentialId);

            if (!$existing) {
                throw $e;
            }

            $existing = $this->restoreProcedimentoTipoIfDeleted($existing);

            if (!$existing) {
                throw $e;
            }

            $this->mergeTeamsIntoProcedimentoTipoAnchor($existing, $this->extractTeamIdList($entity));

            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensProcedimentoTipo: duplicate procedimento tipo anchor recovered for procedimentoTipoId '" .
                $procedimentoTipoId . "' and credential '" . $credentialId . "'."
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

    private function extractRawProcedimentoTipoIdFromInput(stdClass $data): ?string
    {
        if (!property_exists($data, 'procedimentoTipoId')) {
            return null;
        }

        return $this->normalizeNullableString($data->procedimentoTipoId);
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
