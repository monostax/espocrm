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
        'tipoAtendimento',
        'profissional',
        'convenio',
        'especialidade',
        'sala',
        'unidade',
        'observacao',
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

        $result = parent::find($searchParams, $params);

        $entities = [];

        foreach ($result->getCollection() as $entity) {
            $entities[] = $entity;
        }

        if ($entities !== []) {
            $this->enrichEntities($entities, false);
        }

        return $result;
    }

    public function create(stdClass $data, CreateParams $params): Entity
    {
        $rawPacienteId = $this->extractRawPacienteIdFromInput($data);
        $rawTeamIdList = $this->extractTeamIdListFromInput($data);

        $entity = parent::create($data, $params);

        $teamIdList = $this->extractTeamIdList($entity);

        if ($teamIdList === [] && $rawTeamIdList !== []) {
            $teamIdList = $rawTeamIdList;
        }

        $credential = $this->getCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);

        if (!$credential) {
            $entity->set('syncStatus', 'error');

            $this->persistAgendamentoAfterCreate($entity);

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
            $this->persistAgendamentoAfterCreate($entity);

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
        $this->persistAgendamentoAfterCreate($entity);

        return $entity;
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
            $credential = $this->getCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);

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

                        foreach ($agendamentoMap[$agendamentoId] as $entity) {
                            foreach ($payload as $field => $value) {
                                if ($field === 'name' && ($value === null || $value === '')) {
                                    continue;
                                }

                                $entity->set($field, $value);
                            }

                            $generatedName = $this->generateName($entity, $payload);

                            if ($generatedName !== null && $generatedName !== '') {
                                $entity->set('name', $generatedName);
                            }

                            $localPacienteAnchor = $this->findLocalPacienteAnchor(
                                $entity->get('idPaciente'),
                                $credentialId,
                            );

                            $localPacienteId = $localPacienteAnchor['localId'];
                            $localPacienteName = $localPacienteAnchor['localName'];

                            $entity->set('pacienteId', $localPacienteId);

                            $entity->set('pacienteName', $localPacienteName);

                            $entity->set('syncStatus', 'synced');

                            if ($persist) {
                                $this->persistHydratedFields(
                                    $entity,
                                    $payload,
                                    'synced',
                                    $credentialId,
                                    $localPacienteId,
                                    $generatedName,
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

        return [];
    }

    private function getApiClient(): ClinicaNasNuvensApiClient
    {
        return $this->injectableFactory->create(ClinicaNasNuvensApiClient::class);
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

    private function persistAgendamentoAfterCreate(Entity $entity): void
    {
        $this->entityManager->saveEntity($entity, [
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

    private function generateName(Entity $entity, array $payload): ?string
    {
        $name = $payload['name'] ?? $entity->get('name');

        if (is_string($name)) {
            $trimmedName = trim($name);

            if ($trimmedName !== '') {
                return $trimmedName;
            }
        }

        $agendamentoId = (string) ($entity->get('agendamentoId') ?? '');
        $data = (string) ($entity->get('data') ?? '');
        $horaInicio = (string) ($entity->get('horaInicio') ?? '');

        $parts = [];

        if ($data !== '') {
            $parts[] = $data;
        }

        if ($horaInicio !== '') {
            $parts[] = $horaInicio;
        }

        if ($parts !== []) {
            $prefix = $agendamentoId !== '' ? 'Agendamento #' . $agendamentoId : 'Agendamento';

            return $prefix . ' · ' . implode(' ', $parts);
        }

        if ($agendamentoId !== '') {
            return 'Agendamento #' . $agendamentoId;
        }

        return null;
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
}
