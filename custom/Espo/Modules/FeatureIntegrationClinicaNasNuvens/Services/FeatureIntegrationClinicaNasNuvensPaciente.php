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
        $entity = parent::create($data, $params);

        $this->enrichEntities([$entity], true);
        $this->persistPacienteAfterCreate($entity);

        return $entity;
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
                                if ($field === 'name' && ($value === null || $value === '')) {
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

        return [];
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

    private function persistPacienteAfterCreate(Entity $entity): void
    {
        try {
            $this->entityManager->saveEntity($entity, [
                SaveOption::SILENT => true,
                SaveOption::SKIP_HOOKS => true,
                SaveOption::SKIP_MODIFIED_BY => true,
            ]);
        } catch (Throwable $e) {
            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensPaciente: failed to persist create-time enrichment for '" .
                $entity->getId() . "': " . $e->getMessage()
            );
        }
    }
}
