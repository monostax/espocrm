<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services;

use Espo\Core\Di;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\Collection as RecordCollection;
use Espo\Core\Record\FindParams;
use Espo\Core\Record\ReadParams;
use Espo\Core\Record\Service as RecordService;
use Espo\Core\Select\SearchParams;
use Espo\ORM\Entity;
use stdClass;
use Throwable;

/**
 * Hybrid service for Agendamento anchor rows.
 *
 * Enriches local records with remote Clínica nas Nuvens agenda fields on list
 * and detail reads.
 *
 * @extends RecordService<Entity>
 */
class FeatureIntegrationClinicaNasNuvensAgendamento extends RecordService implements
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
        'agendamentoId',
        'idPaciente',
        'idProfissional',
        'idConvenio',
        'idEspecialidade',
        'idUnidade',
        'idSala',
        'data',
        'horaInicio',
        'horaFim',
        'status',
        'statusFaturamento',
        'tipoAtendimento',
        'profissional',
        'convenio',
        'especialidade',
        'sala',
        'unidade',
        'observacao',
        'valor',
        'valorCurrency',
        'syncStatus',
        'paciente',
        'pacienteId',
        'credential',
        'credentialId',
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
        'idPaciente',
        'idProfissional',
        'idConvenio',
        'idEspecialidade',
        'idUnidade',
        'idSala',
        'data',
        'horaInicio',
        'horaFim',
        'status',
        'statusFaturamento',
        'tipoAtendimento',
        'profissional',
        'convenio',
        'especialidade',
        'sala',
        'unidade',
        'observacao',
        'procedimentos',
    ];

    /**
     * @var string[]
     */
    private const JSON_ARRAY_REMOTE_FIELDS = [
        'procedimentos',
    ];

    /**
     * @var array<string, array{localId: ?string, localName: ?string}>
     */
    private array $pacienteAnchorCache = [];

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
        $rawAgendamentoId = $this->extractRawAgendamentoIdFromInput($data);
        $rawPacienteId = $this->extractRawPacienteIdFromInput($data);
        $rawTeamIdList = $this->extractTeamIdListFromInput($data);
        $preCreateCredential = null;

        if ($rawAgendamentoId) {
            $preCreateCredential = $this->resolveAccessibleCredentialFromInput($data, $rawTeamIdList);

            if ($preCreateCredential) {
                $preCreateCredentialId = $preCreateCredential->getId();
                $existing = $this->findAgendamentoAnchorIncludingDeleted($rawAgendamentoId, $preCreateCredentialId);

                if ($existing) {
                    $existing = $this->restoreAgendamentoIfDeleted($existing);

                    if ($existing) {
                        $this->mergeTeamsIntoAgendamentoAnchor($existing, $rawTeamIdList);
                        $this->discoverAndCreateFaturamentoAnchors($existing, $this->extractTeamIdList($existing));
                        $this->enrichEntities([$existing], true);

                        return $existing;
                    }
                }
            }

            $this->assertAgendamentoExistsBeforeCreate($rawAgendamentoId, $preCreateCredential);
        }

        $entity = parent::create($data, $params);

        $teamIdList = $this->extractTeamIdList($entity);

        if ($teamIdList === [] && $rawTeamIdList !== []) {
            $teamIdList = $rawTeamIdList;
        }

        $credential = $this->resolveAccessibleCredential($entity, $teamIdList);

        if (!$credential) {
            $entity->set('syncStatus', 'error');

            $entity = $this->persistAgendamentoAfterCreate($entity);
            $this->discoverAndCreateFaturamentoAnchors($entity, $teamIdList);

            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensAgendamento: no accessible CNN credential on create for agendamento '" .
                $entity->getId() . "'."
            );

            return $entity;
        }

        $credentialId = $credential->getId();

        if ($entity->get('credentialId') !== $credentialId) {
            $entity->set('credentialId', $credentialId);
        }

        $remotePacienteId = $this->resolveRemotePacienteIdForCreate($rawPacienteId, $entity, $credential);

        if (!$remotePacienteId) {
            $this->enrichEntities([$entity], true);
            $entity = $this->persistAgendamentoAfterCreate($entity);
            $this->discoverAndCreateFaturamentoAnchors($entity, $teamIdList);

            return $entity;
        }

        try {
            $paciente = $this->findOrRestoreOrCreatePacienteAnchor($remotePacienteId, $credentialId, $teamIdList);

            if ($paciente) {
                $entity->set('pacienteId', $paciente->getId());

                $this->updatePacienteAnchorCache($remotePacienteId, $credentialId, $paciente);
            }
        } catch (Throwable $e) {
            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensAgendamento: failed paciente upsert on create for agendamento '" .
                $entity->getId() . "', credential '" . $credentialId . "', remote paciente '" .
                $remotePacienteId . "': " . $e->getMessage()
            );
        }

        $this->enrichEntities([$entity], true);
        $entity = $this->persistAgendamentoAfterCreate($entity);
        $this->discoverAndCreateFaturamentoAnchors($entity, $teamIdList);

        return $entity;
    }

    public function hydrateAfterImport(string $id): void
    {
        $entity = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensAgendamento', $id);

        if (!$entity) {
            return;
        }

        $teamIdList = $this->extractTeamIdList($entity);

        $this->enrichEntities([$entity], true);
        $this->persistAgendamentoAfterCreate($entity);
        $this->discoverAndCreateFaturamentoAnchors($entity, $teamIdList);
    }

    private function assertAgendamentoExistsBeforeCreate(string $agendamentoId, ?Entity $credential): void
    {
        if (!$credential) {
            throw new BadRequest(
                "Cannot create agendamento anchor '{$agendamentoId}' without an accessible CNN credential."
            );
        }

        try {
            $payload = $this->getApiClient()->getAgendaById($credential, $agendamentoId);
        } catch (Throwable $e) {
            throw new BadRequest(
                "Cannot create agendamento anchor '{$agendamentoId}': invalid or non-existent remote agenda ID.",
                previous: $e
            );
        }

        $resolvedAgendamentoId = $this->normalizeNullableString($payload['agendamentoId'] ?? null);

        if (!$resolvedAgendamentoId || $resolvedAgendamentoId !== $agendamentoId) {
            throw new BadRequest(
                "Cannot create agendamento anchor '{$agendamentoId}': invalid or non-existent remote agenda ID."
            );
        }
    }

    /**
     * @param string[] $teamIdList
     */
    private function discoverAndCreateFaturamentoAnchors(Entity $agendamento, array $teamIdList): void
    {
        $agendamentoId = $this->normalizeNullableString($agendamento->get('agendamentoId'));

        if (!$agendamentoId) {
            return;
        }

        $webCredential = $this->getWebCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);

        if (!$webCredential) {
            return;
        }

        try {
            $faturamentoIdList = $this->getWebClient()->getFaturamentoIdsByAgendamentoId($webCredential, $agendamentoId);
        } catch (Throwable $e) {
            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensAgendamento: failed to discover faturamentos for agendamento '" .
                $agendamentoId . "' using web credential '" . $webCredential->getId() . "': " . $e->getMessage()
            );

            return;
        }

        if ($faturamentoIdList === []) {
            return;
        }

        $faturamentoService = $this->recordServiceContainer->get('FeatureIntegrationClinicaNasNuvensFaturamento');
        $webCredentialId = $webCredential->getId();

        foreach ($faturamentoIdList as $faturamentoId) {
            try {
                $payload = (object) [
                    'faturamentoId' => $faturamentoId,
                    'credentialId' => $webCredentialId,
                    'teamsIds' => $teamIdList,
                ];

                $faturamentoService->create($payload, CreateParams::create());
            } catch (Throwable $e) {
                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensAgendamento: failed to create faturamento anchor '" .
                    $faturamentoId . "' for agendamento '" . $agendamentoId . "': " . $e->getMessage()
                );
            }
        }
    }

    private function resolveRemotePacienteIdForCreate(?string $rawPacienteId, Entity $entity, Entity $credential): ?string
    {
        if ($rawPacienteId) {
            return $rawPacienteId;
        }

        $entityPacienteId = $this->normalizeNullableString($entity->get('idPaciente'));

        if ($entityPacienteId) {
            return $entityPacienteId;
        }

        $agendamentoId = $this->normalizeNullableString($entity->get('agendamentoId'));

        if (!$agendamentoId) {
            return null;
        }

        try {
            $payload = $this->getApiClient()->getAgendaById($credential, $agendamentoId);
            $resolvedPacienteId = $this->normalizeNullableString($payload['idPaciente'] ?? null);

            if (!$resolvedPacienteId) {
                return null;
            }

            $entity->set('idPaciente', $resolvedPacienteId);

            return $resolvedPacienteId;
        } catch (Throwable $e) {
            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensAgendamento: failed to resolve remote paciente id from agenda '" .
                $agendamentoId . "' on create: " . $e->getMessage()
            );

            return null;
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
            $agendamentoId = $entity->get('agendamentoId');

            if (!$agendamentoId) {
                continue;
            }

            $teamIdList = $this->extractTeamIdList($entity);
            $credential = $this->resolveAccessibleCredential($entity, $teamIdList);

            if (!$credential) {
                $entity->set('syncStatus', 'error');

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensAgendamento: no accessible CNN credential for agendamento '" .
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

            if (!isset($grouped[$credentialId][$agendamentoId])) {
                $grouped[$credentialId][$agendamentoId] = [];
            }

            $grouped[$credentialId][$agendamentoId][] = $entity;
        }

        if ($grouped === []) {
            return;
        }

        foreach ($grouped as $credentialId => $agendamentoMap) {
            $credential = $selectedCredentialMap[$credentialId] ?? null;

            if (!$credential) {
                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensAgendamento: credential '{$credentialId}' not accessible."
                );

                continue;
            }

            $agendamentoIdList = array_keys($agendamentoMap);
            $chunks = array_chunk($agendamentoIdList, self::ENRICHMENT_BATCH_SIZE);

            foreach ($chunks as $chunk) {
                foreach ($chunk as $agendamentoId) {
                    try {
                        $payload = $this->getApiClient()->getAgendaById($credential, $agendamentoId);
                        $payload = $this->normalizeStatusPayload($payload);

                        foreach ($agendamentoMap[$agendamentoId] as $entity) {
                            $entityPayload = $this->normalizeStatusFaturamentoPayload($payload, $entity, $agendamentoId);

                            foreach ($entityPayload as $field => $value) {
                                if ($field === 'agendamentoId') {
                                    continue;
                                }

                                if ($this->shouldSkipBlankHydrationValue($entity, $field, $value)) {
                                    continue;
                                }

                                $entity->set($field, $value);
                            }

                            $localPacienteAnchor = $this->findLocalPacienteAnchor(
                                $entity->get('idPaciente'),
                                $credentialId,
                            );

                            if ($persist && $localPacienteAnchor['localId'] === null) {
                                $remotePacienteId = $this->normalizeNullableString($entity->get('idPaciente'));

                                if ($remotePacienteId) {
                                    try {
                                        $paciente = $this->findOrRestoreOrCreatePacienteAnchor(
                                            $remotePacienteId,
                                            $credentialId,
                                            $this->extractTeamIdList($entity),
                                        );

                                        if ($paciente) {
                                            $this->updatePacienteAnchorCache($remotePacienteId, $credentialId, $paciente);

                                            $localPacienteAnchor = [
                                                'localId' => $paciente->getId(),
                                                'localName' => $this->normalizeNullableString($paciente->get('name')),
                                            ];
                                        }
                                    } catch (Throwable $e) {
                                        $this->log->warning(
                                            "FeatureIntegrationClinicaNasNuvensAgendamento: failed paciente upsert on read for agendamento '" .
                                            $entity->getId() . "', credential '" . $credentialId . "', remote paciente '" .
                                            $remotePacienteId . "': " . $e->getMessage()
                                        );
                                    }
                                }
                            }

                            $localPacienteId = $localPacienteAnchor['localId'];
                            $localPacienteName = $localPacienteAnchor['localName'];

                            $entity->set('pacienteId', $localPacienteId);

                            $entity->set('pacienteName', $localPacienteName);

                            $generatedName = $this->generateName($entity, $payload, $localPacienteName);

                            if ($generatedName !== null && $generatedName !== '') {
                                $entity->set('name', $generatedName);
                            }

                            $billingSnapshot = $this->findLatestFaturamentoSnapshot($entity);

                            if ($billingSnapshot !== null) {
                                $entity->set('valor', $billingSnapshot['valor']);
                                $entity->set('valorCurrency', $billingSnapshot['valorCurrency']);
                            }

                            $entity->set('syncStatus', 'synced');

                            if ($persist) {
                                $this->persistHydratedFields(
                                    $entity,
                                    $entityPayload,
                                    'synced',
                                    $credentialId,
                                    $localPacienteId,
                                    $generatedName,
                                    $billingSnapshot,
                                );
                            }
                        }
                    } catch (Throwable $e) {
                        foreach ($agendamentoMap[$agendamentoId] as $entity) {
                            $entity->set('syncStatus', 'error');
                        }

                        $this->log->warning(
                            "FeatureIntegrationClinicaNasNuvensAgendamento: failed to enrich agendamento '{$agendamentoId}' " .
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
                'entityType' => 'FeatureIntegrationClinicaNasNuvensAgendamento',
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

    private function getApiClient(): ClinicaNasNuvensApiClient
    {
        return $this->injectableFactory->create(ClinicaNasNuvensApiClient::class);
    }

    private function getWebClient(): ClinicaNasNuvensWebClient
    {
        return $this->injectableFactory->create(ClinicaNasNuvensWebClient::class);
    }

    private function findOrRestoreOrCreatePacienteAnchor(
        string $remotePacienteId,
        string $credentialId,
        array $teamIdList,
    ): ?Entity {
        $paciente = $this->findPacienteAnchorIncludingDeleted($remotePacienteId, $credentialId);

        if ($paciente) {
            $paciente = $this->restorePacienteIfDeleted($paciente);

            if ($paciente) {
                $this->mergeTeamsIntoPacienteAnchor($paciente, $teamIdList);
            }

            return $paciente;
        }

        return $this->createPacienteAnchorWithConflictRecovery($remotePacienteId, $credentialId, $teamIdList);
    }

    private function createPacienteAnchorWithConflictRecovery(
        string $remotePacienteId,
        string $credentialId,
        array $teamIdList,
    ): ?Entity {
        try {
            return $this->entityManager->createEntity('FeatureIntegrationClinicaNasNuvensPaciente', [
                'pacienteId' => $remotePacienteId,
                'credentialId' => $credentialId,
                'teamsIds' => $teamIdList,
                'syncStatus' => 'pending',
            ], [
                SaveOption::SILENT => true,
            ]);
        } catch (Throwable $e) {
            if (!$this->isDuplicateConstraintViolation($e)) {
                throw $e;
            }

            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensAgendamento: duplicate paciente anchor race for remote paciente '" .
                $remotePacienteId . "' and credential '" . $credentialId . "', retrying lookup."
            );

            $existing = $this->findPacienteAnchorIncludingDeleted($remotePacienteId, $credentialId);

            if (!$existing) {
                throw $e;
            }

            $existing = $this->restorePacienteIfDeleted($existing);

            if ($existing) {
                $this->mergeTeamsIntoPacienteAnchor($existing, $teamIdList);
            }

            return $existing;
        }
    }

    private function findPacienteAnchorIncludingDeleted(string $remotePacienteId, string $credentialId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensPaciente')
            ->where([
                'pacienteId' => $remotePacienteId,
                'credentialId' => $credentialId,
            ])
            ->withDeleted()
            ->build();

        return $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensPaciente')
            ->clone($query)
            ->findOne();
    }

    private function restorePacienteIfDeleted(Entity $paciente): ?Entity
    {
        if (!$paciente->get('deleted')) {
            return $paciente;
        }

        $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensPaciente')
            ->restoreDeleted($paciente->getId());

        return $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensPaciente', $paciente->getId());
    }

    /**
     * @param string[] $agendamentoTeamIdList
     */
    private function mergeTeamsIntoPacienteAnchor(Entity $paciente, array $agendamentoTeamIdList): void
    {
        $existingTeamIdList = $this->extractTeamIdList($paciente);

        $mergedTeamIdList = array_values(array_unique(array_merge($existingTeamIdList, $agendamentoTeamIdList)));

        if ($mergedTeamIdList === $existingTeamIdList) {
            return;
        }

        $paciente->set('teamsIds', $mergedTeamIdList);

        $this->entityManager->saveEntity($paciente, [
            SaveOption::SILENT => true,
            SaveOption::SKIP_HOOKS => true,
            SaveOption::SKIP_MODIFIED_BY => true,
        ]);
    }

    private function persistAgendamentoAfterCreate(Entity $entity): Entity
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

            $agendamentoId = $this->normalizeNullableString($entity->get('agendamentoId'));
            $credentialId = $this->normalizeNullableString($entity->get('credentialId'));

            if (!$agendamentoId || !$credentialId) {
                throw $e;
            }

            $existing = $this->findAgendamentoAnchorIncludingDeleted($agendamentoId, $credentialId);

            if (!$existing) {
                throw $e;
            }

            $existing = $this->restoreAgendamentoIfDeleted($existing);

            if (!$existing) {
                throw $e;
            }

            $this->mergeTeamsIntoAgendamentoAnchor($existing, $this->extractTeamIdList($entity));

            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensAgendamento: duplicate agendamento anchor recovered for agendamentoId '" .
                $agendamentoId . "' and credential '" . $credentialId . "'."
            );

            return $existing;
        }
    }

    private function findAgendamentoAnchorIncludingDeleted(string $agendamentoId, string $credentialId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensAgendamento')
            ->where([
                'agendamentoId' => $agendamentoId,
                'credentialId' => $credentialId,
            ])
            ->withDeleted()
            ->build();

        return $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensAgendamento')
            ->clone($query)
            ->findOne();
    }

    private function restoreAgendamentoIfDeleted(Entity $agendamento): ?Entity
    {
        if (!$agendamento->get('deleted')) {
            return $agendamento;
        }

        $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensAgendamento')
            ->restoreDeleted($agendamento->getId());

        return $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensAgendamento', $agendamento->getId());
    }

    /**
     * @param string[] $teamIdList
     */
    private function mergeTeamsIntoAgendamentoAnchor(Entity $agendamento, array $teamIdList): void
    {
        $existingTeamIdList = $this->extractTeamIdList($agendamento);
        $mergedTeamIdList = array_values(array_unique(array_merge($existingTeamIdList, $teamIdList)));

        if ($mergedTeamIdList === $existingTeamIdList) {
            return;
        }

        $agendamento->set('teamsIds', $mergedTeamIdList);

        $this->entityManager->saveEntity($agendamento, [
            SaveOption::SILENT => true,
            SaveOption::SKIP_HOOKS => true,
            SaveOption::SKIP_MODIFIED_BY => true,
        ]);
    }

    private function updatePacienteAnchorCache(string $remotePacienteId, string $credentialId, Entity $paciente): void
    {
        $cacheKey = $credentialId . '::' . $remotePacienteId;

        $name = $paciente->get('name');

        $this->pacienteAnchorCache[$cacheKey] = [
            'localId' => $paciente->getId(),
            'localName' => is_string($name) && trim($name) !== '' ? trim($name) : null,
        ];
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableString($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function extractRawPacienteIdFromInput(stdClass $data): ?string
    {
        if (!property_exists($data, 'idPaciente')) {
            return null;
        }

        return $this->normalizeNullableString($data->idPaciente);
    }

    private function extractRawAgendamentoIdFromInput(stdClass $data): ?string
    {
        if (!property_exists($data, 'agendamentoId')) {
            return null;
        }

        return $this->normalizeNullableString($data->agendamentoId);
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

    private function getCredentialHelper(): ClinicaNasNuvensCredentialHelper
    {
        return $this->injectableFactory->create(ClinicaNasNuvensCredentialHelper::class);
    }

    private function getWebCredentialHelper(): ClinicaNasNuvensWebCredentialHelper
    {
        return $this->injectableFactory->create(ClinicaNasNuvensWebCredentialHelper::class);
    }

    /**
     * @param string[] $teamIdList
     */
    private function resolveAccessibleCredential(Entity $entity, array $teamIdList): ?Entity
    {
        $credential = $this->getCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);

        if ($credential) {
            return $credential;
        }

        $credentialId = $this->normalizeNullableString($entity->get('credentialId'));

        if (!$credentialId) {
            return null;
        }

        $credentialMap = $this->getCredentialHelper()->getAccessibleCredentialMapByIds([$credentialId]);

        return $credentialMap[$credentialId] ?? null;
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeStatusPayload(array $payload): array
    {
        if (!array_key_exists('status', $payload)) {
            return $payload;
        }

        $normalizedStatus = $this->getStatusEnumSync()
            ->normalizeAndEnsureOption($this->normalizeNullableString($payload['status']));

        $payload['status'] = $normalizedStatus;

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeStatusFaturamentoPayload(array $payload, Entity $entity, string $agendamentoId): array
    {
        $statusFaturamento = $this->resolveStatusFaturamentoByAgendamentoId($entity, $agendamentoId);

        if ($statusFaturamento === null) {
            return $payload;
        }

        $normalizedStatus = $this->getStatusFaturamentoEnumSync()
            ->normalizeAndEnsureOption($statusFaturamento);

        if ($normalizedStatus === null) {
            return $payload;
        }

        $payload['statusFaturamento'] = $normalizedStatus;

        return $payload;
    }

    private function getStatusEnumSync(): AgendamentoStatusEnumSync
    {
        return $this->injectableFactory->create(AgendamentoStatusEnumSync::class);
    }

    private function getStatusFaturamentoEnumSync(): AgendamentoStatusFaturamentoEnumSync
    {
        return $this->injectableFactory->create(AgendamentoStatusFaturamentoEnumSync::class);
    }

    private function resolveStatusFaturamentoByAgendamentoId(Entity $entity, string $agendamentoId): ?string
    {
        if ($agendamentoId === '') {
            return null;
        }

        $teamIdList = $this->extractTeamIdList($entity);
        $webCredential = $this->getWebCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);

        if (!$webCredential) {
            return null;
        }

        try {
            return $this->getWebClient()->getStatusFaturamentoByAgendamentoId($webCredential, $agendamentoId);
        } catch (Throwable $e) {
            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensAgendamento: failed to resolve faturamento status for agendamento '" .
                $agendamentoId . "' using web credential '" . $webCredential->getId() . "': " . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Populate virtual display fields for already-synced entities without
     * hitting the external CNN API. Used by find() to avoid unnecessary
     * API calls for records that are already hydrated.
     */
    private function populateDisplayFieldsFromDb(Entity $entity): void
    {
        $credentialId = $this->normalizeNullableString($entity->get('credentialId'));

        if ($credentialId) {
            $credential = $this->entityManager->getEntityById('Credential', $credentialId);

            if ($credential) {
                $entity->set('credentialName', $credential->get('name'));
            }
        }

        $localPacienteAnchor = $this->findLocalPacienteAnchor(
            $entity->get('idPaciente'),
            $credentialId,
        );

        if ($localPacienteAnchor['localId'] !== null) {
            $entity->set('pacienteId', $localPacienteAnchor['localId']);
            $entity->set('pacienteName', $localPacienteAnchor['localName']);
        }

        $billingSnapshot = $this->findLatestFaturamentoSnapshot($entity);

        if ($billingSnapshot !== null) {
            $entity->set('valor', $billingSnapshot['valor']);
            $entity->set('valorCurrency', $billingSnapshot['valorCurrency']);
        }
    }

    private function generateName(Entity $entity, array $payload, ?string $_pacienteName): ?string
    {
        $agendamentoId = $this->normalizeNullableString($entity->get('agendamentoId'));

        if (!$agendamentoId) {
            $agendamentoId = $this->normalizeNullableString($payload['id'] ?? null);
        }

        if (!$agendamentoId) {
            return null;
        }

        $label = '#' . $agendamentoId;
        $startRaw = $this->normalizeNullableString($entity->get('horaInicio'))
            ?? $this->normalizeNullableString($payload['horaInicio'] ?? null);
        $endRaw = $this->normalizeNullableString($entity->get('horaFim'))
            ?? $this->normalizeNullableString($payload['horaFim'] ?? null);
        $dateRaw = $this->normalizeNullableString($entity->get('data'))
            ?? $this->normalizeNullableString($payload['data'] ?? null);

        $fallbackDate = $this->extractDatePart($dateRaw);
        $startAt = $this->parseDateTimeWithFallbackDate($startRaw, $fallbackDate);

        if ($startAt && $fallbackDate === null) {
            $fallbackDate = $startAt->format('Y-m-d');
        }

        $endAt = $this->parseDateTimeWithFallbackDate($endRaw, $fallbackDate);

        if ($startAt && $endAt) {
            return $startAt->format('d/m/Y H:i') . ' - ' . $endAt->format('H:i') . ' ' . $label;
        }

        if ($startAt) {
            return $startAt->format('d/m/Y H:i') . ' ' . $label;
        }

        return $label;
    }

    private function parseDateTimeWithFallbackDate(?string $value, ?string $fallbackDate): ?\DateTimeImmutable
    {
        if (!$value) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(\d{2}:\d{2})(?::(\d{2}))?$/', $trimmed, $match) === 1) {
            if (!$fallbackDate) {
                return null;
            }

            $time = $match[1] . ':' . ($match[2] ?? '00');

            try {
                return new \DateTimeImmutable($fallbackDate . ' ' . $time);
            } catch (Throwable $e) {
                return null;
            }
        }

        try {
            return new \DateTimeImmutable($trimmed);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function extractDatePart(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $trimmed, $match) === 1) {
            return $match[1];
        }

        try {
            return (new \DateTimeImmutable($trimmed))->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{localId: ?string, localName: ?string}
     */
    private function findLocalPacienteAnchor(?string $remotePacienteId, ?string $credentialId): array
    {
        if (!$remotePacienteId || !$credentialId) {
            return ['localId' => null, 'localName' => null];
        }

        $cacheKey = $credentialId . '::' . $remotePacienteId;

        if (isset($this->pacienteAnchorCache[$cacheKey])) {
            return $this->pacienteAnchorCache[$cacheKey];
        }

        $paciente = $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensPaciente')
            ->select(['id', 'name'])
            ->where([
                'pacienteId' => $remotePacienteId,
                'credentialId' => $credentialId,
                'deleted' => false,
            ])
            ->findOne();

        $resolved = [
            'localId' => $paciente?->getId(),
            'localName' => $paciente && is_string($paciente->get('name')) && trim((string) $paciente->get('name')) !== ''
                ? trim((string) $paciente->get('name'))
                : null,
        ];

        $this->pacienteAnchorCache[$cacheKey] = $resolved;

        return $resolved;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistHydratedFields(
        Entity $entity,
        array $payload,
        string $syncStatus,
        string $credentialId,
        ?string $localPacienteId,
        ?string $generatedName,
        ?array $billingSnapshot,
    ): void {
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

        if ($generatedName !== null && $generatedName !== '' && $entity->getFetched('name') !== $generatedName) {
            $toPersist['name'] = $generatedName;
        }

        if ($entity->getFetched('credentialId') !== $credentialId) {
            $toPersist['credentialId'] = $credentialId;
        }

        if ($entity->getFetched('pacienteId') !== $localPacienteId) {
            $toPersist['pacienteId'] = $localPacienteId;
        }

        if ($entity->getFetched('syncStatus') !== $syncStatus) {
            $toPersist['syncStatus'] = $syncStatus;
        }

        if ($billingSnapshot !== null) {
            if ($entity->getFetched('valor') !== $billingSnapshot['valor']) {
                $toPersist['valor'] = $billingSnapshot['valor'];
            }

            if ($entity->getFetched('valorCurrency') !== $billingSnapshot['valorCurrency']) {
                $toPersist['valorCurrency'] = $billingSnapshot['valorCurrency'];
            }
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
                "FeatureIntegrationClinicaNasNuvensAgendamento: failed to persist hydrated fields for '" .
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
     * @return array{valor: float, valorCurrency: string}|null
     */
    private function findLatestFaturamentoSnapshot(Entity $entity): ?array
    {
        $agendamentoLocalId = $this->normalizeNullableString($entity->getId());

        if (!$agendamentoLocalId) {
            return null;
        }

        $faturamento = $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensFaturamento')
            ->select(['valor', 'valorCurrency'])
            ->where([
                'agendamentoId' => $agendamentoLocalId,
                'deleted' => false,
            ])
            ->order('modifiedAt', 'DESC')
            ->findOne();

        if (!$faturamento) {
            return null;
        }

        $valor = $faturamento->get('valor');

        if (!is_int($valor) && !is_float($valor) && !(is_string($valor) && is_numeric($valor))) {
            return null;
        }

        $currency = $this->normalizeNullableString($faturamento->get('valorCurrency')) ?? 'BRL';

        return [
            'valor' => (float) $valor,
            'valorCurrency' => $currency,
        ];
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
}
