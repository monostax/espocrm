<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services;

use Espo\Core\Di;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\Collection as RecordCollection;
use Espo\Core\Record\FindParams;
use Espo\Core\Record\ReadParams;
use Espo\Core\Record\Service as RecordService;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Select\SearchParams;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensIntegrationProfileResolver;
use Espo\ORM\Entity;
use stdClass;
use Throwable;

/**
 * Hybrid service for Paciente anchor rows.
 *
 * Enriches local records with remote Clínica nas Nuvens profile fields on list
 * and detail reads.
 *
 * @extends RecordService<Entity>
 */
class FeatureIntegrationClinicaNasNuvensPaciente extends RecordService implements
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
        'pacienteId',
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
        'ativo',
        'cpfcnpj',
        'dataNascimento',
        'email',
        'telefone',
        'celular',
        'sexo',
        'nomeMae',
        'nomePai',
        'estadoCivil',
        'profissao',
        'endereco',
        'numero',
        'complemento',
        'bairro',
        'cidade',
        'estado',
        'cep',
        'observacao',
        'convenio',
        'numeroConvenio',
        'validadeConvenio',
    ];

    public function read(string $id, ReadParams $params): Entity
    {
        $entity = parent::read($id, $params);

        $this->enrichEntities([$entity], true);
        $this->hydrateRelatedAgendamentosAndFaturamentos($entity);

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

        $rawPacienteId = $this->extractRawPacienteIdFromInput($data);
        $rawTeamIdList = $this->extractTeamIdListFromInput($data);
        $preCreateCredential = null;

        if ($rawPacienteId) {
            $preCreateCredential = $this->resolveAccessibleCredentialFromInput($data, $rawTeamIdList);

            if ($preCreateCredential) {
                $existing = $this->findPacienteAnchorIncludingDeleted($rawPacienteId, $preCreateCredential->getId());

                if ($existing) {
                    $existing = $this->restorePacienteIfDeleted($existing);

                    if ($existing) {
                        $this->mergeTeamsIntoPacienteAnchor($existing, $rawTeamIdList);
                        $this->enrichEntities([$existing], true);

                        return $existing;
                    }
                }
            }

            $this->assertPacienteExistsBeforeCreate($rawPacienteId, $preCreateCredential);
        }

        $entity = parent::create($data, $params);

        $this->enrichEntities([$entity], true);
        $entity = $this->persistPacienteAfterCreate($entity);

        return $entity;
    }

    public function hydrateAfterImport(string $id): void
    {
        $entity = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensPaciente', $id);

        if (!$entity) {
            return;
        }

        $this->enrichEntities([$entity], true);
    }

    private function assertPacienteExistsBeforeCreate(string $pacienteId, ?Entity $credential): void
    {
        if (!$credential) {
            throw new BadRequest(
                "Cannot create paciente anchor '{$pacienteId}' without an accessible CNN credential."
            );
        }

        try {
            $payload = $this->getApiClient()->getPacienteById($credential, $pacienteId);
        } catch (Throwable $e) {
            throw new BadRequest(
                "Cannot create paciente anchor '{$pacienteId}': invalid or non-existent remote paciente ID.",
                previous: $e
            );
        }

        if ($payload === []) {
            throw new BadRequest(
                "Cannot create paciente anchor '{$pacienteId}': invalid or non-existent remote paciente ID."
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
            $pacienteId = $entity->get('pacienteId');

            if (!$pacienteId) {
                continue;
            }

            $teamIdList = $this->extractTeamIdList($entity);
            $credential = $this->getCredentialHelper()->findAccessibleCredentialForTeamIds($teamIdList);

            if (!$credential) {
                $entity->set('syncStatus', 'error');

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensPaciente: no accessible CNN credential for paciente '" .
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

            if (!isset($grouped[$credentialId][$pacienteId])) {
                $grouped[$credentialId][$pacienteId] = [];
            }

            $grouped[$credentialId][$pacienteId][] = $entity;
        }

        if ($grouped === []) {
            return;
        }

        foreach ($grouped as $credentialId => $pacienteMap) {
            $credential = $selectedCredentialMap[$credentialId] ?? null;

            if (!$credential) {
                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensPaciente: credential '{$credentialId}' not accessible."
                );

                continue;
            }

            $pacienteIdList = array_keys($pacienteMap);
            $chunks = array_chunk($pacienteIdList, self::ENRICHMENT_BATCH_SIZE);

            foreach ($chunks as $chunk) {
                foreach ($chunk as $pacienteId) {
                    try {
                        $payload = $this->getApiClient()->getPacienteById($credential, $pacienteId);

                        foreach ($pacienteMap[$pacienteId] as $entity) {
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
                        foreach ($pacienteMap[$pacienteId] as $entity) {
                            $entity->set('syncStatus', 'error');
                        }

                        $this->log->warning(
                            "FeatureIntegrationClinicaNasNuvensPaciente: failed to enrich paciente '{$pacienteId}' " .
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
                'entityType' => 'FeatureIntegrationClinicaNasNuvensPaciente',
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

    private function findPacienteAnchorIncludingDeleted(string $pacienteId, string $credentialId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensPaciente')
            ->where([
                'pacienteId' => $pacienteId,
                'credentialId' => $credentialId,
            ])
            ->withDeleted()
            ->order('deleted', 'ASC')
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
     * @param string[] $teamIdList
     */
    private function mergeTeamsIntoPacienteAnchor(Entity $paciente, array $teamIdList): void
    {
        $existingTeamIdList = $this->extractTeamIdList($paciente);
        $mergedTeamIdList = array_values(array_unique(array_merge($existingTeamIdList, $teamIdList)));

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
                "FeatureIntegrationClinicaNasNuvensPaciente: failed to persist hydrated fields for '" .
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

    private function persistPacienteAfterCreate(Entity $entity): Entity
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

            $pacienteId = $this->normalizeNullableString($entity->get('pacienteId'));
            $credentialId = $this->normalizeNullableString($entity->get('credentialId'));

            if (!$pacienteId || !$credentialId) {
                throw $e;
            }

            $existing = $this->findPacienteAnchorIncludingDeleted($pacienteId, $credentialId);

            if (!$existing) {
                throw $e;
            }

            $existing = $this->restorePacienteIfDeleted($existing);

            if (!$existing) {
                throw $e;
            }

            $this->mergeTeamsIntoPacienteAnchor($existing, $this->extractTeamIdList($entity));

            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensPaciente: duplicate paciente anchor recovered for pacienteId '" .
                $pacienteId . "' and credential '" . $credentialId . "'."
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

    private function extractRawPacienteIdFromInput(stdClass $data): ?string
    {
        if (!property_exists($data, 'pacienteId')) {
            return null;
        }

        return $this->normalizeNullableString($data->pacienteId);
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
     * Hydrate all linked Agendamentos and Faturamentos for this Paciente.
     *
     * Runs on read() so that when a user views a Paciente detail page, all
     * related anchors are refreshed with the latest remote data behind the
     * scenes.
     *
     * The initial render already shows stored DB values (fast). This method
     * re-syncs every linked record regardless of syncStatus for freshness.
     * enrichEntities() uses shouldSkipBlankHydrationValue internally, so
     * existing non-blank field values are never overwritten with blanks.
     */
    private function hydrateRelatedAgendamentosAndFaturamentos(Entity $paciente): void
    {
        $pacienteLocalId = $this->normalizeNullableString($paciente->getId());

        if (!$pacienteLocalId) {
            return;
        }

        /** @var FeatureIntegrationClinicaNasNuvensAgendamento $agendamentoService */
        $agendamentoService = $this->recordServiceContainer->get('FeatureIntegrationClinicaNasNuvensAgendamento');

        $agendamentos = $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensAgendamento')
            ->where([
                'pacienteId' => $pacienteLocalId,
                'deleted' => false,
            ])
            ->find();

        foreach ($agendamentos as $agendamento) {
            $id = $agendamento->getId();

            if (!is_string($id) || $id === '') {
                continue;
            }

            try {
                $agendamentoService->hydrateAfterImport($id);
            } catch (Throwable $e) {
                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensPaciente: failed to hydrate related agendamento '" .
                    $id . "' for paciente '" . $pacienteLocalId . "': " . $e->getMessage()
                );
            }
        }

        /** @var FeatureIntegrationClinicaNasNuvensFaturamento $faturamentoService */
        $faturamentoService = $this->recordServiceContainer->get('FeatureIntegrationClinicaNasNuvensFaturamento');

        $faturamentos = $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensFaturamento')
            ->where([
                'pacienteId' => $pacienteLocalId,
                'deleted' => false,
            ])
            ->find();

        foreach ($faturamentos as $faturamento) {
            $id = $faturamento->getId();

            if (!is_string($id) || $id === '') {
                continue;
            }

            try {
                $faturamentoService->hydrateAfterImport($id);
            } catch (Throwable $e) {
                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensPaciente: failed to hydrate related faturamento '" .
                    $id . "' for paciente '" . $pacienteLocalId . "': " . $e->getMessage()
                );
            }
        }
    }
}
