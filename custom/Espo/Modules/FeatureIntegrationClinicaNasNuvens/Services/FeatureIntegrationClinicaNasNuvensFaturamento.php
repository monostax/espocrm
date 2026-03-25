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
class FeatureIntegrationClinicaNasNuvensFaturamento extends RecordService implements
    Di\LogAware
{
    use Di\LogSetter;

    /**
     * @var string[]
     */
    private const ALLOWED_ORDER_FILTER_FIELDS = [
        'id',
        'name',
        'faturamentoId',
        'agendamentoId',
        'agendamento',
        'agendamentoName',
        'pacienteId',
        'paciente',
        'pacienteName',
        'documento',
        'dataFaturamento',
        'profissionalNome',
        'conta',
        'valor',
        'valorCurrency',
        'parcela',
        'dataVencimento',
        'description',
        'syncStatus',
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
        'documento',
        'dataFaturamento',
        'profissionalNome',
        'conta',
        'valor',
        'valorCurrency',
        'parcela',
        'dataVencimento',
        'description',
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
        $rawFaturamentoId = $this->extractRawFaturamentoIdFromInput($data);
        $rawTeamIdList = $this->extractTeamIdListFromInput($data);
        $preCreateCredential = null;

        if ($rawFaturamentoId) {
            $preCreateCredential = $this->resolveWebCredentialFromInput($data, $rawTeamIdList);

            if ($preCreateCredential) {
                $existing = $this->findFaturamentoAnchorIncludingDeleted($rawFaturamentoId, $preCreateCredential->getId());

                if ($existing) {
                    $existing = $this->restoreFaturamentoIfDeleted($existing);

                    if ($existing) {
                        $this->mergeTeamsIntoFaturamentoAnchor($existing, $rawTeamIdList);
                        $this->enrichEntities([$existing], true);

                        return $existing;
                    }
                }
            }

            $this->assertFaturamentoExistsBeforeCreate($rawFaturamentoId, $preCreateCredential);
        }

        $entity = parent::create($data, $params);

        $this->enrichEntities([$entity], true);
        $entity = $this->persistFaturamentoAfterCreate($entity);

        return $entity;
    }

    public function hydrateAfterImport(string $id): void
    {
        $entity = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensFaturamento', $id);

        if (!$entity) {
            return;
        }

        $this->enrichEntities([$entity], true);
    }

    private function assertFaturamentoExistsBeforeCreate(string $faturamentoId, ?Entity $credential): void
    {
        if (!$credential) {
            throw new BadRequest(
                "Cannot create faturamento anchor '{$faturamentoId}' without an accessible CNN web credential."
            );
        }

        try {
            $payload = $this->getWebClient()->getDetalhesConta($credential, $faturamentoId);
        } catch (Throwable $e) {
            throw new BadRequest(
                "Cannot create faturamento anchor '{$faturamentoId}': invalid or non-existent remote faturamento ID.",
                previous: $e
            );
        }

        $resolvedAgendamentoId = $this->normalizeNullableString($payload['agendamentoId'] ?? null);

        if (!$resolvedAgendamentoId) {
            throw new BadRequest(
                "Cannot create faturamento anchor '{$faturamentoId}': invalid or non-existent remote faturamento ID."
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
        foreach ($entities as $entity) {
            $faturamentoId = $this->normalizeNullableString($entity->get('faturamentoId'));

            if (!$faturamentoId) {
                continue;
            }

            $teamIdList = $this->extractTeamIdList($entity);
            $webCredential = $this->resolveWebCredential($entity, $teamIdList);

            if (!$webCredential) {
                $entity->set('syncStatus', 'error');

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensFaturamento: no accessible web credential for faturamento '" .
                    $faturamentoId . "' (entity '" . $entity->getId() . "')."
                );

                continue;
            }

            $webCredentialId = $webCredential->getId();

            $entity->set('credentialId', $webCredentialId);
            $entity->set('credentialName', $webCredential->get('name'));

            try {
                $payload = $this->getWebClient()->getDetalhesConta($webCredential, $faturamentoId);

                foreach (self::PERSISTED_REMOTE_FIELDS as $field) {
                    if (!array_key_exists($field, $payload)) {
                        continue;
                    }

                    $value = $payload[$field];

                    if ($this->shouldSkipBlankHydrationValue($entity, $field, $value)) {
                        continue;
                    }

                    $entity->set($field, $value);
                }

                $entity->set('valorCurrency', 'BRL');

                $agendamentoLocalId = null;
                $pacienteLocalId = null;
                $agendamentoRemoteId = $this->normalizeNullableString($payload['agendamentoId'] ?? null);

                if ($agendamentoRemoteId) {
                    $agendamento = $this->findOrRestoreOrCreateAgendamentoAnchor($agendamentoRemoteId, $teamIdList);

                    if ($agendamento) {
                        $agendamentoLocalId = $agendamento->getId();
                        $entity->set('agendamentoId', $agendamentoLocalId);
                        $entity->set('agendamentoName', $agendamento->get('name'));

                        $pacienteLocalId = $this->normalizeNullableString($agendamento->get('pacienteId'));

                        if ($pacienteLocalId) {
                            $entity->set('pacienteId', $pacienteLocalId);
                            $entity->set('pacienteName', $agendamento->get('pacienteName'));
                        }
                    }
                }

                $generatedName = $this->generateName($entity, $faturamentoId);

                if ($generatedName) {
                    $entity->set('name', $generatedName);
                }

                $entity->set('syncStatus', 'synced');

                if ($persist) {
                    $this->persistHydratedFields(
                        $entity,
                        $payload,
                        'synced',
                        $webCredentialId,
                        $agendamentoLocalId,
                        $pacienteLocalId,
                        $generatedName,
                    );

                    if ($agendamentoLocalId !== null) {
                        $this->syncAgendamentoBillingSnapshot($agendamentoLocalId, $entity);
                    }
                }
            } catch (Throwable $e) {
                $entity->set('syncStatus', 'error');

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensFaturamento: failed to hydrate faturamento '{$faturamentoId}' " .
                    "for credential '{$webCredentialId}': " . $e->getMessage()
                );
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
                'entityType' => 'FeatureIntegrationClinicaNasNuvensFaturamento',
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

    private function findOrRestoreOrCreateAgendamentoAnchor(string $agendamentoId, array $teamIdList): ?Entity
    {
        $apiCredential = $this->getApiCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);
        $apiCredentialId = $apiCredential?->getId();

        if (!$apiCredentialId) {
            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensFaturamento: web hydration succeeded but no API credential available " .
                "for agendamento '{$agendamentoId}'. Creating/linking anchor without API credential."
            );
        }

        $agendamento = $this->findAgendamentoAnchorIncludingDeleted($agendamentoId, $apiCredentialId);

        if ($agendamento) {
            $agendamento = $this->restoreAgendamentoIfDeleted($agendamento);

            if ($agendamento) {
                $this->mergeTeamsIntoAgendamentoAnchor($agendamento, $teamIdList);
                $this->triggerAgendamentoImmediateHydrate($agendamento);
            }

            return $agendamento;
        }

        $created = $this->createAgendamentoAnchorWithConflictRecovery($agendamentoId, $apiCredentialId, $teamIdList);

        if ($created) {
            $this->triggerAgendamentoImmediateHydrate($created);
        }

        return $created;
    }

    /**
     * @param string[] $teamIdList
     */
    private function resolveWebCredential(Entity $entity, array $teamIdList): ?Entity
    {
        $credentialId = $this->normalizeNullableString($entity->get('credentialId'));

        if (!$credentialId) {
            $entityId = $this->normalizeNullableString($entity->getId());

            if ($entityId) {
                $storedEntity = $this->entityManager
                    ->getRDBRepository('FeatureIntegrationClinicaNasNuvensFaturamento')
                    ->select(['credentialId'])
                    ->where([
                        'id' => $entityId,
                        'deleted' => false,
                    ])
                    ->findOne();

                if ($storedEntity) {
                    $credentialId = $this->normalizeNullableString($storedEntity->get('credentialId'));
                }
            }
        }

        if ($credentialId) {
            try {
                return $this->getWebCredentialHelper()->validateCredentialAccess($credentialId);
            } catch (Throwable $e) {
                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensFaturamento: linked credential '{$credentialId}' is not usable " .
                    "for entity '{$entity->getId()}': " . $e->getMessage()
                );
            }
        }

        return $this->getWebCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);
    }

    private function findAgendamentoAnchorIncludingDeleted(string $agendamentoId, ?string $credentialId): ?Entity
    {
        $where = ['agendamentoId' => $agendamentoId];

        if ($credentialId !== null) {
            $where['credentialId'] = $credentialId;
        }

        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensAgendamento')
            ->where($where)
            ->withDeleted()
            ->order('createdAt', 'ASC')
            ->build();

        return $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensAgendamento')
            ->clone($query)
            ->findOne();
    }

    private function findFaturamentoAnchorIncludingDeleted(string $faturamentoId, string $credentialId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensFaturamento')
            ->where([
                'faturamentoId' => $faturamentoId,
                'credentialId' => $credentialId,
            ])
            ->withDeleted()
            ->build();

        return $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensFaturamento')
            ->clone($query)
            ->findOne();
    }

    private function restoreFaturamentoIfDeleted(Entity $faturamento): ?Entity
    {
        if (!$faturamento->get('deleted')) {
            return $faturamento;
        }

        $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensFaturamento')
            ->restoreDeleted($faturamento->getId());

        return $this->entityManager
            ->getEntityById('FeatureIntegrationClinicaNasNuvensFaturamento', $faturamento->getId());
    }

    /**
     * @param string[] $teamIdList
     */
    private function mergeTeamsIntoFaturamentoAnchor(Entity $faturamento, array $teamIdList): void
    {
        $existingTeamIdList = $this->extractTeamIdList($faturamento);
        $mergedTeamIdList = array_values(array_unique(array_merge($existingTeamIdList, $teamIdList)));

        if ($mergedTeamIdList === $existingTeamIdList) {
            return;
        }

        $faturamento->set('teamsIds', $mergedTeamIdList);

        $this->entityManager->saveEntity($faturamento, [
            SaveOption::SILENT => true,
            SaveOption::SKIP_HOOKS => true,
            SaveOption::SKIP_MODIFIED_BY => true,
        ]);
    }

    private function restoreAgendamentoIfDeleted(Entity $agendamento): ?Entity
    {
        if (!$agendamento->get('deleted')) {
            return $agendamento;
        }

        $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensAgendamento')
            ->restoreDeleted($agendamento->getId());

        return $this->entityManager
            ->getEntityById('FeatureIntegrationClinicaNasNuvensAgendamento', $agendamento->getId());
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

    /**
     * @param string[] $teamIdList
     */
    private function createAgendamentoAnchorWithConflictRecovery(
        string $agendamentoId,
        ?string $credentialId,
        array $teamIdList,
    ): ?Entity {
        try {
            $payload = [
                'agendamentoId' => $agendamentoId,
                'teamsIds' => $teamIdList,
                'syncStatus' => 'pending',
            ];

            if ($credentialId !== null) {
                $payload['credentialId'] = $credentialId;
            }

            return $this->entityManager->createEntity('FeatureIntegrationClinicaNasNuvensAgendamento', $payload, [
                SaveOption::SILENT => true,
            ]);
        } catch (Throwable $e) {
            if (!$this->isDuplicateConstraintViolation($e)) {
                throw $e;
            }

            $existing = $this->findAgendamentoAnchorIncludingDeleted($agendamentoId, $credentialId);

            if (!$existing) {
                throw $e;
            }

            $existing = $this->restoreAgendamentoIfDeleted($existing);

            if ($existing) {
                $this->mergeTeamsIntoAgendamentoAnchor($existing, $teamIdList);
            }

            return $existing;
        }
    }

    private function triggerAgendamentoImmediateHydrate(Entity $agendamento): void
    {
        try {
            $agendamentoService = $this->recordServiceContainer->get('FeatureIntegrationClinicaNasNuvensAgendamento');
            $agendamentoService->read($agendamento->getId(), ReadParams::create());
        } catch (Throwable $e) {
            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensFaturamento: immediate hydrate failed for agendamento '" .
                $agendamento->getId() . "': " . $e->getMessage()
            );
        }
    }

    private function generateName(Entity $entity, string $faturamentoId): ?string
    {
        $label = '#' . $faturamentoId;
        $dataFaturamento = $this->normalizeNullableString($entity->get('dataFaturamento'));
        $formattedDate = $this->formatDateForName($dataFaturamento);

        if ($formattedDate) {
            return "{$formattedDate} {$label}";
        }

        return $label;
    }

    private function formatDateForName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $trimmed) === 1) {
            return $trimmed;
        }

        try {
            return (new \DateTimeImmutable($trimmed))->format('d/m/Y');
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistHydratedFields(
        Entity $entity,
        array $payload,
        string $syncStatus,
        ?string $credentialId,
        ?string $agendamentoLocalId,
        ?string $pacienteLocalId,
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

        if ($entity->getFetched('valorCurrency') !== 'BRL') {
            $toPersist['valorCurrency'] = 'BRL';
        }

        if ($credentialId !== null && $entity->getFetched('credentialId') !== $credentialId) {
            $toPersist['credentialId'] = $credentialId;
        }

        if ($agendamentoLocalId !== null && $entity->getFetched('agendamentoId') !== $agendamentoLocalId) {
            $toPersist['agendamentoId'] = $agendamentoLocalId;
        }

        if ($pacienteLocalId !== null && $entity->getFetched('pacienteId') !== $pacienteLocalId) {
            $toPersist['pacienteId'] = $pacienteLocalId;
        }

        if ($generatedName !== null && $generatedName !== '' && $entity->getFetched('name') !== $generatedName) {
            $toPersist['name'] = $generatedName;
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
                "FeatureIntegrationClinicaNasNuvensFaturamento: failed to persist hydrated fields for '" .
                $entity->getId() . "': " . $e->getMessage()
            );
        }
    }

    private function syncAgendamentoBillingSnapshot(string $agendamentoLocalId, Entity $faturamento): void
    {
        $valor = $faturamento->get('valor');

        if (!is_int($valor) && !is_float($valor) && !(is_string($valor) && is_numeric($valor))) {
            return;
        }

        $agendamento = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensAgendamento', $agendamentoLocalId);

        if (!$agendamento) {
            return;
        }

        $currency = $this->normalizeNullableString($faturamento->get('valorCurrency')) ?? 'BRL';
        $normalizedValor = (float) $valor;
        $toPersist = [];

        if ($agendamento->get('valor') !== $normalizedValor) {
            $toPersist['valor'] = $normalizedValor;
        }

        if ($agendamento->get('valorCurrency') !== $currency) {
            $toPersist['valorCurrency'] = $currency;
        }

        if ($toPersist === []) {
            return;
        }

        $agendamento->set($toPersist);

        try {
            $this->entityManager->saveEntity($agendamento, [
                SaveOption::SILENT => true,
                SaveOption::SKIP_HOOKS => true,
                SaveOption::SKIP_MODIFIED_BY => true,
            ]);
        } catch (Throwable $e) {
            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensFaturamento: failed to sync agendamento billing snapshot for '" .
                $agendamentoLocalId . "': " . $e->getMessage()
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

    private function persistFaturamentoAfterCreate(Entity $entity): Entity
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

            $faturamentoId = $this->normalizeNullableString($entity->get('faturamentoId'));
            $credentialId = $this->normalizeNullableString($entity->get('credentialId'));

            if (!$faturamentoId || !$credentialId) {
                throw $e;
            }

            $existing = $this->findFaturamentoAnchorIncludingDeleted($faturamentoId, $credentialId);

            if (!$existing) {
                throw $e;
            }

            $existing = $this->restoreFaturamentoIfDeleted($existing);

            if (!$existing) {
                throw $e;
            }

            $this->mergeTeamsIntoFaturamentoAnchor($existing, $this->extractTeamIdList($entity));

            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensFaturamento: duplicate faturamento anchor recovered for faturamentoId '" .
                $faturamentoId . "' and credential '" . $credentialId . "'."
            );

            return $existing;
        }
    }

    private function extractRawFaturamentoIdFromInput(stdClass $data): ?string
    {
        if (!property_exists($data, 'faturamentoId')) {
            return null;
        }

        return $this->normalizeNullableString($data->faturamentoId);
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

    /**
     * @param string[] $teamIdList
     */
    private function resolveWebCredentialFromInput(stdClass $data, array $teamIdList): ?Entity
    {
        $credential = $this->getWebCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);

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

        try {
            return $this->getWebCredentialHelper()->validateCredentialAccess($credentialId);
        } catch (Throwable $e) {
            return null;
        }
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

    private function getWebClient(): ClinicaNasNuvensWebClient
    {
        return $this->injectableFactory->create(ClinicaNasNuvensWebClient::class);
    }

    private function getWebCredentialHelper(): ClinicaNasNuvensWebCredentialHelper
    {
        return $this->injectableFactory->create(ClinicaNasNuvensWebCredentialHelper::class);
    }

    private function getApiCredentialHelper(): ClinicaNasNuvensCredentialHelper
    {
        return $this->injectableFactory->create(ClinicaNasNuvensCredentialHelper::class);
    }
}
