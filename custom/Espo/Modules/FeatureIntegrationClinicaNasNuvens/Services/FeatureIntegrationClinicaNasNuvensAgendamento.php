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

    private const PROCEDIMENTO_TIPO_ENTITY_TYPE = 'FeatureIntegrationClinicaNasNuvensProcedimentoTipo';
    private const CONVENIO_TIPO_ENTITY_TYPE = 'FeatureIntegrationClinicaNasNuvensConvenioTipo';
    private const CONSULTA_TIPO_ENTITY_TYPE = 'FeatureIntegrationClinicaNasNuvensConsultaTipo';
    private const AGENDAMENTO_PROCEDIMENTO_ENTITY_TYPE = 'FeatureIntegrationClinicaNasNuvensAgendamentoProcedimento';

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
        'idPessoaExecutor',
        'idConvenio',
        'idTipoConvenio',
        'idTipoConsulta',
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
        'valorFaturamentos',
        'valorFaturamentosCurrency',
        'valorProcedimentos',
        'valorProcedimentosCurrency',
        'valorFinanceiro',
        'valorFinanceiroCurrency',
        'syncStatus',
        'paciente',
        'pacienteId',
        'profissionalAnchor',
        'profissionalAnchorId',
        'convenioTipoAnchor',
        'convenioTipoAnchorId',
        'consultaTipoAnchor',
        'consultaTipoAnchorId',
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
        'idPessoaExecutor',
        'idConvenio',
        'idTipoConvenio',
        'idTipoConsulta',
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

    /**
     * @var array<string, array{localId: ?string, localName: ?string}>
     */
    private array $profissionalAnchorCache = [];

    /**
     * @var array<string, array{localId: ?string, localName: ?string}>
     */
    private array $convenioTipoAnchorCache = [];

    /**
     * @var array<string, array{localId: ?string, localName: ?string}>
     */
    private array $consultaTipoAnchorCache = [];

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
        $rawProfissionalId = $this->extractRawProfissionalIdFromInput($data);
        $rawPessoaExecutorId = $this->extractRawPessoaExecutorIdFromInput($data);
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

        try {
            $resolvedProfissionalRefs = $this->resolveRemoteProfissionalRefsForCreate(
                $rawProfissionalId,
                $rawPessoaExecutorId,
                $entity,
                $credential,
            );

            $remoteProfissionalId = $resolvedProfissionalRefs['idProfissional'];
            $remotePessoaExecutorId = $resolvedProfissionalRefs['idPessoaExecutor'];

            if ($remoteProfissionalId || $remotePessoaExecutorId) {
                $profissional = $this->findOrRestoreOrCreateProfissionalAnchor(
                    $remoteProfissionalId,
                    $remotePessoaExecutorId,
                    $credentialId,
                    $teamIdList,
                );

                if ($profissional) {
                    $entity->set('profissionalAnchorId', $profissional->getId());

                    $this->updateProfissionalAnchorCache($credentialId, $profissional);
                }
            }
        } catch (Throwable $e) {
            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensAgendamento: failed profissional upsert on create for agendamento '" .
                $entity->getId() . "', credential '" . $credentialId . "': " . $e->getMessage()
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
        $credential = $this->resolveAccessibleCredential($entity, $teamIdList);

        if ($credential) {
            $credentialId = $credential->getId();

            if ($entity->get('credentialId') !== $credentialId) {
                $entity->set('credentialId', $credentialId);
            }

            $this->createProfissionalAnchorForImport($entity, $credential, $credentialId, $teamIdList);
            $this->createPacienteAnchorForImport($entity, $credential, $credentialId, $teamIdList);
        }

        $this->enrichEntities([$entity], true);
        $this->persistAgendamentoAfterCreate($entity);
        $this->discoverAndCreateFaturamentoAnchors($entity, $teamIdList);
    }

    /**
     * Enrich all downstream entities related to this Agendamento.
     *
     * Re-enriches the Agendamento itself, then triggers enrichment (via
     * hydrateAfterImport or read) for every related Paciente, Profissional,
     * ConvenioTipo, Faturamento, and ProcedimentoTipo anchor.
     *
     * @return array{enriched: string[], errors: string[]}
     */
    public function enrichDownstreams(string $id): array
    {
        $enriched = [];
        $errors = [];

        // 1. Re-enrich the Agendamento itself (and discover new faturamentos).
        $entity = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensAgendamento', $id);

        if (!$entity) {
            $errors[] = 'Agendamento not found.';

            return ['enriched' => $enriched, 'errors' => $errors];
        }

        $teamIdList = $this->extractTeamIdList($entity);

        $this->enrichEntities([$entity], true);
        $this->discoverAndCreateFaturamentoAnchors($entity, $teamIdList);

        $enriched[] = 'Agendamento';

        // Re-read entity to get fresh links after enrichment.
        $entity = $this->entityManager->getEntityById('FeatureIntegrationClinicaNasNuvensAgendamento', $id);

        if (!$entity) {
            return ['enriched' => $enriched, 'errors' => $errors];
        }

        // 2. Enrich Paciente.
        $pacienteId = $this->normalizeNullableString($entity->get('pacienteId'));

        if ($pacienteId) {
            try {
                $pacienteService = $this->recordServiceContainer->get('FeatureIntegrationClinicaNasNuvensPaciente');
                $pacienteService->hydrateAfterImport($pacienteId);
                $enriched[] = 'Paciente';
            } catch (Throwable $e) {
                $errors[] = 'Paciente: ' . $e->getMessage();

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensAgendamento::enrichDownstreams: failed to enrich paciente '" .
                    $pacienteId . "' for agendamento '" . $id . "': " . $e->getMessage()
                );
            }
        }

        // 3. Enrich Profissional.
        $profissionalId = $this->normalizeNullableString($entity->get('profissionalAnchorId'));

        if ($profissionalId) {
            try {
                $profissionalService = $this->recordServiceContainer->get('FeatureIntegrationClinicaNasNuvensProfissional');
                $profissionalService->hydrateAfterImport($profissionalId);
                $enriched[] = 'Profissional';
            } catch (Throwable $e) {
                $errors[] = 'Profissional: ' . $e->getMessage();

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensAgendamento::enrichDownstreams: failed to enrich profissional '" .
                    $profissionalId . "' for agendamento '" . $id . "': " . $e->getMessage()
                );
            }
        }

        // 4. Enrich ConvenioTipo.
        $convenioTipoId = $this->normalizeNullableString($entity->get('convenioTipoAnchorId'));

        if ($convenioTipoId) {
            try {
                $convenioTipoService = $this->recordServiceContainer->get('FeatureIntegrationClinicaNasNuvensConvenioTipo');
                $convenioTipoService->hydrateAfterImport($convenioTipoId);
                $enriched[] = 'ConvenioTipo';
            } catch (Throwable $e) {
                $errors[] = 'ConvenioTipo: ' . $e->getMessage();

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensAgendamento::enrichDownstreams: failed to enrich convenio tipo '" .
                    $convenioTipoId . "' for agendamento '" . $id . "': " . $e->getMessage()
                );
            }
        }

        // 5. Enrich ConsultaTipo.
        $consultaTipoId = $this->normalizeNullableString($entity->get('consultaTipoAnchorId'));

        if ($consultaTipoId) {
            try {
                $consultaTipoService = $this->recordServiceContainer->get(self::CONSULTA_TIPO_ENTITY_TYPE);
                $consultaTipoService->hydrateAfterImport($consultaTipoId);
                $enriched[] = 'ConsultaTipo';
            } catch (Throwable $e) {
                $errors[] = 'ConsultaTipo: ' . $e->getMessage();

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensAgendamento::enrichDownstreams: failed to enrich consulta tipo '" .
                    $consultaTipoId . "' for agendamento '" . $id . "': " . $e->getMessage()
                );
            }
        }

        // 6. Enrich Faturamentos.
        $faturamentos = $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensFaturamento')
            ->where([
                'agendamentoId' => $id,
                'deleted' => false,
            ])
            ->find();

        $faturamentoService = $this->recordServiceContainer->get('FeatureIntegrationClinicaNasNuvensFaturamento');

        foreach ($faturamentos as $faturamento) {
            $faturamentoLocalId = $this->normalizeNullableString($faturamento->getId());

            if (!$faturamentoLocalId) {
                continue;
            }

            try {
                $faturamentoService->hydrateAfterImport($faturamentoLocalId);
                $enriched[] = 'Faturamento';
            } catch (Throwable $e) {
                $errors[] = 'Faturamento (' . $faturamentoLocalId . '): ' . $e->getMessage();

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensAgendamento::enrichDownstreams: failed to enrich faturamento '" .
                    $faturamentoLocalId . "' for agendamento '" . $id . "': " . $e->getMessage()
                );
            }
        }

        // 7. Enrich ProcedimentoTipos (via AgendamentoProcedimento junction).
        $procedimentoItens = $this->entityManager
            ->getRDBRepository(self::AGENDAMENTO_PROCEDIMENTO_ENTITY_TYPE)
            ->where([
                'agendamentoId' => $id,
                'deleted' => false,
            ])
            ->find();

        $procedimentoTipoService = $this->recordServiceContainer->get(self::PROCEDIMENTO_TIPO_ENTITY_TYPE);
        $enrichedProcedimentoTipoIds = [];

        foreach ($procedimentoItens as $item) {
            $procedimentoTipoId = $this->normalizeNullableString($item->get('procedimentoTipoId'));

            if (!$procedimentoTipoId || in_array($procedimentoTipoId, $enrichedProcedimentoTipoIds, true)) {
                continue;
            }

            try {
                $procedimentoTipoService->hydrateAfterImport($procedimentoTipoId);
                $enrichedProcedimentoTipoIds[] = $procedimentoTipoId;
                $enriched[] = 'ProcedimentoTipo';
            } catch (Throwable $e) {
                $errors[] = 'ProcedimentoTipo (' . $procedimentoTipoId . '): ' . $e->getMessage();

                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensAgendamento::enrichDownstreams: failed to enrich procedimento tipo '" .
                    $procedimentoTipoId . "' for agendamento '" . $id . "': " . $e->getMessage()
                );
            }
        }

        return ['enriched' => $enriched, 'errors' => $errors];
    }

    private function createProfissionalAnchorForImport(
        Entity $entity,
        Entity $credential,
        string $credentialId,
        array $teamIdList,
    ): void {
        try {
            $resolvedProfissionalRefs = $this->resolveRemoteProfissionalRefsForCreate(
                null,
                null,
                $entity,
                $credential,
            );

            $remoteProfissionalId = $resolvedProfissionalRefs['idProfissional'];
            $remotePessoaExecutorId = $resolvedProfissionalRefs['idPessoaExecutor'];

            if ($remoteProfissionalId || $remotePessoaExecutorId) {
                $profissional = $this->findOrRestoreOrCreateProfissionalAnchor(
                    $remoteProfissionalId,
                    $remotePessoaExecutorId,
                    $credentialId,
                    $teamIdList,
                );

                if ($profissional) {
                    $entity->set('profissionalAnchorId', $profissional->getId());

                    $this->updateProfissionalAnchorCache($credentialId, $profissional);
                }
            }
        } catch (Throwable $e) {
            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensAgendamento: failed profissional upsert on import for agendamento '" .
                $entity->getId() . "', credential '" . $credentialId . "': " . $e->getMessage()
            );
        }
    }

    private function createPacienteAnchorForImport(
        Entity $entity,
        Entity $credential,
        string $credentialId,
        array $teamIdList,
    ): void {
        try {
            $remotePacienteId = $this->resolveRemotePacienteIdForCreate(null, $entity, $credential);

            if (!$remotePacienteId) {
                return;
            }

            $paciente = $this->findOrRestoreOrCreatePacienteAnchor($remotePacienteId, $credentialId, $teamIdList);

            if ($paciente) {
                $entity->set('pacienteId', $paciente->getId());

                $this->updatePacienteAnchorCache($remotePacienteId, $credentialId, $paciente);
            }
        } catch (Throwable $e) {
            $this->log->warning(
                "FeatureIntegrationClinicaNasNuvensAgendamento: failed paciente upsert on import for agendamento '" .
                $entity->getId() . "', credential '" . $credentialId . "': " . $e->getMessage()
            );
        }
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

    /**
     * @param ?string $rawProfissionalId
     * @param ?string $rawPessoaExecutorId
     * @return array{idProfissional: ?string, idPessoaExecutor: ?string}
     */
    private function resolveRemoteProfissionalRefsForCreate(
        ?string $rawProfissionalId,
        ?string $rawPessoaExecutorId,
        Entity $entity,
        Entity $credential,
    ): array {
        $resolvedProfissionalId = $rawProfissionalId
            ?? $this->normalizeNullableString($entity->get('idProfissional'));
        $resolvedPessoaExecutorId = $rawPessoaExecutorId
            ?? $this->normalizeNullableString($entity->get('idPessoaExecutor'));

        $credentialId = $this->normalizeNullableString($credential->getId());

        if (!$resolvedProfissionalId && $resolvedPessoaExecutorId && $credentialId) {
            $existing = $this->findProfissionalAnchorByPessoaIncludingDeleted($resolvedPessoaExecutorId, $credentialId);

            if ($existing) {
                $resolvedProfissionalId = $this->normalizeNullableString($existing->get('profissionalId'));
            }
        }

        $agendamentoId = $this->normalizeNullableString($entity->get('agendamentoId'));

        if ((!$resolvedProfissionalId || !$resolvedPessoaExecutorId) && $agendamentoId) {
            try {
                $payload = $this->getApiClient()->getAgendaById($credential, $agendamentoId);

                $resolvedProfissionalId = $resolvedProfissionalId
                    ?? $this->normalizeNullableString($payload['idProfissional'] ?? null);
                $resolvedPessoaExecutorId = $resolvedPessoaExecutorId
                    ?? $this->normalizeNullableString($payload['idPessoaExecutor'] ?? null);
            } catch (Throwable $e) {
                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensAgendamento: failed to resolve remote profissional refs from agenda '" .
                    $agendamentoId . "' on create: " . $e->getMessage()
                );
            }
        }

        if (!$resolvedProfissionalId && $resolvedPessoaExecutorId) {
            try {
                $resolvedProfissionalId = $this->getApiClient()
                    ->resolveExecutorAgendaIdByPessoaId($credential, $resolvedPessoaExecutorId);
            } catch (Throwable $e) {
                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensAgendamento: failed to resolve executor-agenda id from idPessoaExecutor '" .
                    $resolvedPessoaExecutorId . "': " . $e->getMessage()
                );
            }
        }

        if ($resolvedProfissionalId) {
            $entity->set('idProfissional', $resolvedProfissionalId);
        }

        if ($resolvedPessoaExecutorId) {
            $entity->set('idPessoaExecutor', $resolvedPessoaExecutorId);
        }

        return [
            'idProfissional' => $resolvedProfissionalId,
            'idPessoaExecutor' => $resolvedPessoaExecutorId,
        ];
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

                            $localProfissionalAnchor = $this->findLocalProfissionalAnchor(
                                $this->normalizeNullableString($entity->get('idProfissional')),
                                $this->normalizeNullableString($entity->get('idPessoaExecutor')),
                                $credentialId,
                            );

                            if ($persist && $localProfissionalAnchor['localId'] === null) {
                                try {
                                    $enrichIdProfissional = $this->normalizeNullableString($entity->get('idProfissional'));
                                    $enrichIdPessoaExecutor = $this->normalizeNullableString($entity->get('idPessoaExecutor'));

                                    if (!$enrichIdProfissional && $enrichIdPessoaExecutor) {
                                        try {
                                            $enrichIdProfissional = $this->getApiClient()
                                                ->resolveExecutorAgendaIdByPessoaId($credential, $enrichIdPessoaExecutor);

                                            if ($enrichIdProfissional) {
                                                $entity->set('idProfissional', $enrichIdProfissional);
                                            }
                                        } catch (Throwable $e) {
                                            $this->log->warning(
                                                "FeatureIntegrationClinicaNasNuvensAgendamento: failed to resolve profissionalId " .
                                                "from idPessoaExecutor '" . $enrichIdPessoaExecutor . "' for agendamento '" .
                                                $entity->getId() . "': " . $e->getMessage()
                                            );
                                        }
                                    }

                                    $profissional = $this->findOrRestoreOrCreateProfissionalAnchor(
                                        $enrichIdProfissional,
                                        $enrichIdPessoaExecutor,
                                        $credentialId,
                                        $this->extractTeamIdList($entity),
                                    );

                                    if ($profissional) {
                                        $this->updateProfissionalAnchorCache($credentialId, $profissional);

                                        $localProfissionalAnchor = [
                                            'localId' => $profissional->getId(),
                                            'localName' => $this->normalizeNullableString($profissional->get('name')),
                                        ];

                                        $resolvedProfissionalId = $this->normalizeNullableString($profissional->get('profissionalId'));
                                        $resolvedPessoaExecutorId = $this->normalizeNullableString($profissional->get('idPessoa'));

                                        if ($resolvedProfissionalId !== null) {
                                            $entity->set('idProfissional', $resolvedProfissionalId);
                                        }

                                        if ($resolvedPessoaExecutorId !== null) {
                                            $entity->set('idPessoaExecutor', $resolvedPessoaExecutorId);
                                        }
                                    }
                                } catch (Throwable $e) {
                                    $this->log->warning(
                                        "FeatureIntegrationClinicaNasNuvensAgendamento: failed profissional upsert on read for agendamento '" .
                                        $entity->getId() . "', credential '" . $credentialId . "': " . $e->getMessage()
                                    );
                                }
                            }

                            $localProfissionalId = $localProfissionalAnchor['localId'];
                            $localProfissionalName = $localProfissionalAnchor['localName'];

                            $entity->set('profissionalAnchorId', $localProfissionalId);
                            $entity->set('profissionalAnchorName', $localProfissionalName);

                            if (
                                $localProfissionalName !== null &&
                                !$this->hasNonBlankValue($entity->get('profissional'))
                            ) {
                                $entity->set('profissional', $localProfissionalName);
                            }

                            $localConvenioTipoAnchor = $this->findLocalConvenioTipoAnchor(
                                $this->normalizeNullableString($entity->get('idTipoConvenio')),
                                $credentialId,
                            );

                            if ($persist && $localConvenioTipoAnchor['localId'] === null) {
                                $remoteConvenioTipoId = $this->normalizeNullableString($entity->get('idTipoConvenio'));

                                if ($remoteConvenioTipoId) {
                                    try {
                                        $convenioTipo = $this->findOrRestoreOrCreateConvenioTipoAnchor(
                                            $remoteConvenioTipoId,
                                            $credentialId,
                                            $this->extractTeamIdList($entity),
                                        );

                                        if ($convenioTipo) {
                                            $this->updateConvenioTipoAnchorCache($remoteConvenioTipoId, $credentialId, $convenioTipo);

                                            $localConvenioTipoAnchor = [
                                                'localId' => $convenioTipo->getId(),
                                                'localName' => $this->normalizeNullableString($convenioTipo->get('name')),
                                            ];
                                        }
                                    } catch (Throwable $e) {
                                        $this->log->warning(
                                            "FeatureIntegrationClinicaNasNuvensAgendamento: failed convenio tipo upsert on read for agendamento '" .
                                            $entity->getId() . "', credential '" . $credentialId . "', remote convenio tipo '" .
                                            $remoteConvenioTipoId . "': " . $e->getMessage()
                                        );
                                    }
                                }
                            }

                            $localConvenioTipoId = $localConvenioTipoAnchor['localId'];

                            $entity->set('convenioTipoAnchorId', $localConvenioTipoId);
                            $entity->set('convenioTipoAnchorName', $localConvenioTipoAnchor['localName']);

                            $localConsultaTipoAnchor = $this->findLocalConsultaTipoAnchor(
                                $this->normalizeNullableString($entity->get('idTipoConsulta')),
                                $credentialId,
                            );

                            if ($persist && $localConsultaTipoAnchor['localId'] === null) {
                                $remoteConsultaTipoId = $this->normalizeNullableString($entity->get('idTipoConsulta'));

                                if ($remoteConsultaTipoId) {
                                    try {
                                        $consultaTipo = $this->findOrRestoreOrCreateConsultaTipoAnchor(
                                            $remoteConsultaTipoId,
                                            $credentialId,
                                            $this->extractTeamIdList($entity),
                                        );

                                        if ($consultaTipo) {
                                            $this->updateConsultaTipoAnchorCache($remoteConsultaTipoId, $credentialId, $consultaTipo);

                                            $localConsultaTipoAnchor = [
                                                'localId' => $consultaTipo->getId(),
                                                'localName' => $this->normalizeNullableString($consultaTipo->get('name')),
                                            ];
                                        }
                                    } catch (Throwable $e) {
                                        $this->log->warning(
                                            "FeatureIntegrationClinicaNasNuvensAgendamento: failed consulta tipo upsert on read for agendamento '" .
                                            $entity->getId() . "', credential '" . $credentialId . "', remote consulta tipo '" .
                                            $remoteConsultaTipoId . "': " . $e->getMessage()
                                        );
                                    }
                                }
                            }

                            $localConsultaTipoId = $localConsultaTipoAnchor['localId'];

                            $entity->set('consultaTipoAnchorId', $localConsultaTipoId);
                            $entity->set('consultaTipoAnchorName', $localConsultaTipoAnchor['localName']);

                            $generatedName = $this->generateName($entity, $payload, $localPacienteName);

                            if ($generatedName !== null && $generatedName !== '') {
                                $entity->set('name', $generatedName);
                            }

                            $billingSnapshot = $this->findLatestFaturamentoSnapshot($entity);

                            if ($billingSnapshot !== null) {
                                $entity->set('valorFaturamentos', $billingSnapshot['valor']);
                                $entity->set('valorFaturamentosCurrency', $billingSnapshot['valorCurrency']);
                            }

                            $entity->set('syncStatus', 'synced');

                            if ($persist) {
                                $this->persistHydratedFields(
                                    $entity,
                                    $entityPayload,
                                    'synced',
                                    $credentialId,
                                    $localPacienteId,
                                    $localProfissionalId,
                                    $localConvenioTipoId,
                                    $localConsultaTipoId,
                                    $generatedName,
                                    $billingSnapshot,
                                );

                                $this->syncAgendamentoProcedimentoTipoLinks(
                                    $entity,
                                    $entityPayload,
                                    $credentialId,
                                );

                                $procedimentosSnapshot = $this->computeValorProcedimentos($entity, $localConvenioTipoId);

                                if ($procedimentosSnapshot !== null) {
                                    $entity->set('valorProcedimentos', $procedimentosSnapshot['valor']);
                                    $entity->set('valorProcedimentosCurrency', $procedimentosSnapshot['valorCurrency']);
                                }

                                $this->computeAndSetValorFinanceiro($entity, $billingSnapshot, $procedimentosSnapshot);
                                $this->persistValorFields($entity, $billingSnapshot, $procedimentosSnapshot);
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
        return $this->extractTeamIdListForEntity($entity, 'FeatureIntegrationClinicaNasNuvensAgendamento');
    }

    /**
     * @return string[]
     */
    private function extractTeamIdListForEntity(Entity $entity, string $entityType): array
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
                'entityType' => $entityType,
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

    private function findOrRestoreOrCreateProfissionalAnchor(
        ?string $remoteProfissionalId,
        ?string $remotePessoaExecutorId,
        string $credentialId,
        array $teamIdList,
    ): ?Entity {
        $profissional = null;

        if ($remoteProfissionalId) {
            $profissional = $this->findProfissionalAnchorIncludingDeleted($remoteProfissionalId, $credentialId);
        }

        if (!$profissional && $remotePessoaExecutorId) {
            $profissional = $this->findProfissionalAnchorByPessoaIncludingDeleted($remotePessoaExecutorId, $credentialId);
        }

        if ($profissional) {
            $profissional = $this->restoreProfissionalIfDeleted($profissional);

            if ($profissional) {
                $this->mergeTeamsIntoProfissionalAnchor($profissional, $teamIdList);
            }

            return $profissional;
        }

        if (!$remoteProfissionalId && $remotePessoaExecutorId) {
            return null;
        }

        if (!$remoteProfissionalId) {
            return null;
        }

        return $this->createProfissionalAnchorWithConflictRecovery(
            $remoteProfissionalId,
            $remotePessoaExecutorId,
            $credentialId,
            $teamIdList,
        );
    }

    private function createProfissionalAnchorWithConflictRecovery(
        string $remoteProfissionalId,
        ?string $remotePessoaExecutorId,
        string $credentialId,
        array $teamIdList,
    ): ?Entity {
        try {
            return $this->entityManager->createEntity('FeatureIntegrationClinicaNasNuvensProfissional', [
                'profissionalId' => $remoteProfissionalId,
                'idPessoa' => $remotePessoaExecutorId,
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
                "FeatureIntegrationClinicaNasNuvensAgendamento: duplicate profissional anchor race for remote profissional '" .
                $remoteProfissionalId . "' and credential '" . $credentialId . "', retrying lookup."
            );

            $existing = $this->findProfissionalAnchorIncludingDeleted($remoteProfissionalId, $credentialId);

            if (!$existing && $remotePessoaExecutorId) {
                $existing = $this->findProfissionalAnchorByPessoaIncludingDeleted($remotePessoaExecutorId, $credentialId);
            }

            if (!$existing) {
                throw $e;
            }

            $existing = $this->restoreProfissionalIfDeleted($existing);

            if ($existing) {
                $this->mergeTeamsIntoProfissionalAnchor($existing, $teamIdList);
            }

            return $existing;
        }
    }

    private function findProfissionalAnchorIncludingDeleted(string $remoteProfissionalId, string $credentialId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensProfissional')
            ->where([
                'profissionalId' => $remoteProfissionalId,
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

    private function findProfissionalAnchorByPessoaIncludingDeleted(string $remotePessoaExecutorId, string $credentialId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensProfissional')
            ->where([
                'idPessoa' => $remotePessoaExecutorId,
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

        return $this->entityManager
            ->getEntityById('FeatureIntegrationClinicaNasNuvensProfissional', $profissional->getId());
    }

    /**
     * @param string[] $agendamentoTeamIdList
     */
    private function mergeTeamsIntoProfissionalAnchor(Entity $profissional, array $agendamentoTeamIdList): void
    {
        $existingTeamIdList = $this->extractTeamIdList($profissional);

        $mergedTeamIdList = array_values(array_unique(array_merge($existingTeamIdList, $agendamentoTeamIdList)));

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

    private function updateProfissionalAnchorCache(string $credentialId, Entity $profissional): void
    {
        $name = $profissional->get('name');

        $resolved = [
            'localId' => $profissional->getId(),
            'localName' => is_string($name) && trim($name) !== '' ? trim($name) : null,
        ];

        $profissionalId = $this->normalizeNullableString($profissional->get('profissionalId'));

        if ($profissionalId) {
            $this->profissionalAnchorCache[$credentialId . '::pid::' . $profissionalId] = $resolved;
        }

        $idPessoa = $this->normalizeNullableString($profissional->get('idPessoa'));

        if ($idPessoa) {
            $this->profissionalAnchorCache[$credentialId . '::pessoa::' . $idPessoa] = $resolved;
        }
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
            ->order('deleted', 'ASC')
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
     * @return array{localId: ?string, localName: ?string}
     */
    private function findLocalConvenioTipoAnchor(?string $remoteConvenioTipoId, ?string $credentialId): array
    {
        if (!$remoteConvenioTipoId || !$credentialId) {
            return ['localId' => null, 'localName' => null];
        }

        $cacheKey = $credentialId . '::' . $remoteConvenioTipoId;

        if (isset($this->convenioTipoAnchorCache[$cacheKey])) {
            return $this->convenioTipoAnchorCache[$cacheKey];
        }

        $convenioTipo = $this->entityManager
            ->getRDBRepository(self::CONVENIO_TIPO_ENTITY_TYPE)
            ->select(['id', 'name'])
            ->where([
                'convenioTipoId' => $remoteConvenioTipoId,
                'credentialId' => $credentialId,
                'deleted' => false,
            ])
            ->findOne();

        $resolved = [
            'localId' => $convenioTipo?->getId(),
            'localName' => $convenioTipo && is_string($convenioTipo->get('name')) && trim((string) $convenioTipo->get('name')) !== ''
                ? trim((string) $convenioTipo->get('name'))
                : null,
        ];

        $this->convenioTipoAnchorCache[$cacheKey] = $resolved;

        return $resolved;
    }

    private function updateConvenioTipoAnchorCache(string $remoteConvenioTipoId, string $credentialId, Entity $convenioTipo): void
    {
        $cacheKey = $credentialId . '::' . $remoteConvenioTipoId;

        $name = $convenioTipo->get('name');

        $this->convenioTipoAnchorCache[$cacheKey] = [
            'localId' => $convenioTipo->getId(),
            'localName' => is_string($name) && trim($name) !== '' ? trim($name) : null,
        ];
    }

    /**
     * @param string[] $teamIdList
     */
    private function findOrRestoreOrCreateConvenioTipoAnchor(
        string $remoteConvenioTipoId,
        string $credentialId,
        array $teamIdList,
    ): ?Entity {
        $convenioTipo = $this->findConvenioTipoAnchorIncludingDeleted($remoteConvenioTipoId, $credentialId);

        if ($convenioTipo) {
            $convenioTipo = $this->restoreConvenioTipoIfDeleted($convenioTipo);

            if ($convenioTipo) {
                $this->mergeTeamsIntoConvenioTipoAnchor($convenioTipo, $teamIdList);
            }

            return $convenioTipo;
        }

        return $this->createConvenioTipoAnchorWithConflictRecovery(
            $remoteConvenioTipoId,
            $credentialId,
            $teamIdList,
        );
    }

    private function findConvenioTipoAnchorIncludingDeleted(string $convenioTipoId, string $credentialId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from(self::CONVENIO_TIPO_ENTITY_TYPE)
            ->where([
                'convenioTipoId' => $convenioTipoId,
                'credentialId' => $credentialId,
            ])
            ->withDeleted()
            ->order('deleted', 'ASC')
            ->build();

        return $this->entityManager
            ->getRDBRepository(self::CONVENIO_TIPO_ENTITY_TYPE)
            ->clone($query)
            ->findOne();
    }

    private function restoreConvenioTipoIfDeleted(Entity $convenioTipo): ?Entity
    {
        if (!$convenioTipo->get('deleted')) {
            return $convenioTipo;
        }

        $this->entityManager
            ->getRDBRepository(self::CONVENIO_TIPO_ENTITY_TYPE)
            ->restoreDeleted($convenioTipo->getId());

        return $this->entityManager->getEntityById(self::CONVENIO_TIPO_ENTITY_TYPE, $convenioTipo->getId());
    }

    /**
     * @param string[] $teamIdList
     */
    private function mergeTeamsIntoConvenioTipoAnchor(Entity $convenioTipo, array $teamIdList): void
    {
        $existingTeamIdList = $this->extractTeamIdListForEntity($convenioTipo, self::CONVENIO_TIPO_ENTITY_TYPE);
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

    /**
     * @param string[] $teamIdList
     */
    private function createConvenioTipoAnchorWithConflictRecovery(
        string $remoteConvenioTipoId,
        string $credentialId,
        array $teamIdList,
    ): ?Entity {
        try {
            return $this->entityManager->createEntity(self::CONVENIO_TIPO_ENTITY_TYPE, [
                'convenioTipoId' => $remoteConvenioTipoId,
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

            $existing = $this->findConvenioTipoAnchorIncludingDeleted($remoteConvenioTipoId, $credentialId);

            if (!$existing) {
                throw $e;
            }

            $existing = $this->restoreConvenioTipoIfDeleted($existing);

            if ($existing) {
                $this->mergeTeamsIntoConvenioTipoAnchor($existing, $teamIdList);
            }

            return $existing;
        }
    }

    /**
     * @return array{localId: ?string, localName: ?string}
     */
    private function findLocalConsultaTipoAnchor(?string $remoteConsultaTipoId, ?string $credentialId): array
    {
        if (!$remoteConsultaTipoId || !$credentialId) {
            return ['localId' => null, 'localName' => null];
        }

        $cacheKey = $credentialId . '::' . $remoteConsultaTipoId;

        if (isset($this->consultaTipoAnchorCache[$cacheKey])) {
            return $this->consultaTipoAnchorCache[$cacheKey];
        }

        $consultaTipo = $this->entityManager
            ->getRDBRepository(self::CONSULTA_TIPO_ENTITY_TYPE)
            ->select(['id', 'name'])
            ->where([
                'consultaTipoId' => $remoteConsultaTipoId,
                'credentialId' => $credentialId,
                'deleted' => false,
            ])
            ->findOne();

        $resolved = [
            'localId' => $consultaTipo?->getId(),
            'localName' => $consultaTipo && is_string($consultaTipo->get('name')) && trim((string) $consultaTipo->get('name')) !== ''
                ? trim((string) $consultaTipo->get('name'))
                : null,
        ];

        $this->consultaTipoAnchorCache[$cacheKey] = $resolved;

        return $resolved;
    }

    private function updateConsultaTipoAnchorCache(string $remoteConsultaTipoId, string $credentialId, Entity $consultaTipo): void
    {
        $cacheKey = $credentialId . '::' . $remoteConsultaTipoId;

        $name = $consultaTipo->get('name');

        $this->consultaTipoAnchorCache[$cacheKey] = [
            'localId' => $consultaTipo->getId(),
            'localName' => is_string($name) && trim($name) !== '' ? trim($name) : null,
        ];
    }

    /**
     * @param string[] $teamIdList
     */
    private function findOrRestoreOrCreateConsultaTipoAnchor(
        string $remoteConsultaTipoId,
        string $credentialId,
        array $teamIdList,
    ): ?Entity {
        $consultaTipo = $this->findConsultaTipoAnchorIncludingDeleted($remoteConsultaTipoId, $credentialId);

        if ($consultaTipo) {
            $consultaTipo = $this->restoreConsultaTipoIfDeleted($consultaTipo);

            if ($consultaTipo) {
                $this->mergeTeamsIntoConsultaTipoAnchor($consultaTipo, $teamIdList);
            }

            return $consultaTipo;
        }

        return $this->createConsultaTipoAnchorWithConflictRecovery(
            $remoteConsultaTipoId,
            $credentialId,
            $teamIdList,
        );
    }

    private function findConsultaTipoAnchorIncludingDeleted(string $consultaTipoId, string $credentialId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from(self::CONSULTA_TIPO_ENTITY_TYPE)
            ->where([
                'consultaTipoId' => $consultaTipoId,
                'credentialId' => $credentialId,
            ])
            ->withDeleted()
            ->order('deleted', 'ASC')
            ->build();

        return $this->entityManager
            ->getRDBRepository(self::CONSULTA_TIPO_ENTITY_TYPE)
            ->clone($query)
            ->findOne();
    }

    private function restoreConsultaTipoIfDeleted(Entity $consultaTipo): ?Entity
    {
        if (!$consultaTipo->get('deleted')) {
            return $consultaTipo;
        }

        $this->entityManager
            ->getRDBRepository(self::CONSULTA_TIPO_ENTITY_TYPE)
            ->restoreDeleted($consultaTipo->getId());

        return $this->entityManager->getEntityById(self::CONSULTA_TIPO_ENTITY_TYPE, $consultaTipo->getId());
    }

    /**
     * @param string[] $teamIdList
     */
    private function mergeTeamsIntoConsultaTipoAnchor(Entity $consultaTipo, array $teamIdList): void
    {
        $existingTeamIdList = $this->extractTeamIdListForEntity($consultaTipo, self::CONSULTA_TIPO_ENTITY_TYPE);
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

    /**
     * @param string[] $teamIdList
     */
    private function createConsultaTipoAnchorWithConflictRecovery(
        string $remoteConsultaTipoId,
        string $credentialId,
        array $teamIdList,
    ): ?Entity {
        try {
            return $this->entityManager->createEntity(self::CONSULTA_TIPO_ENTITY_TYPE, [
                'consultaTipoId' => $remoteConsultaTipoId,
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

            $existing = $this->findConsultaTipoAnchorIncludingDeleted($remoteConsultaTipoId, $credentialId);

            if (!$existing) {
                throw $e;
            }

            $existing = $this->restoreConsultaTipoIfDeleted($existing);

            if ($existing) {
                $this->mergeTeamsIntoConsultaTipoAnchor($existing, $teamIdList);
            }

            return $existing;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function syncAgendamentoProcedimentoTipoLinks(Entity $agendamento, array $payload, string $credentialId): void
    {
        $agendamentoLocalId = $this->normalizeNullableString($agendamento->getId());

        if ($agendamentoLocalId === null) {
            return;
        }

        $aggregatedRows = $this->collectProcedimentoTipoAggregatesFromPayload($payload);

        $teamIdList = $this->extractTeamIdList($agendamento);
        $processedProcedimentoTipoIds = [];

        foreach ($aggregatedRows as $remoteProcedimentoTipoId => $row) {
            try {
                $procedimentoTipo = $this->findOrRestoreOrCreateProcedimentoTipoAnchor(
                    $remoteProcedimentoTipoId,
                    $credentialId,
                    $teamIdList,
                );

                if (!$procedimentoTipo) {
                    continue;
                }

                $procedimentoTipoLocalId = $this->normalizeNullableString($procedimentoTipo->getId());

                if ($procedimentoTipoLocalId === null) {
                    continue;
                }

                $processedProcedimentoTipoIds[] = $procedimentoTipoLocalId;

                $child = $this->findAgendamentoProcedimentoIncludingDeleted($agendamentoLocalId, $procedimentoTipoLocalId);

                if (!$child) {
                    $child = $this->createAgendamentoProcedimentoWithConflictRecovery(
                        $agendamentoLocalId,
                        $procedimentoTipoLocalId,
                        $teamIdList,
                    );
                }

                if (!$child) {
                    continue;
                }

                if ((bool) $child->get('deleted')) {
                    $this->entityManager
                        ->getRDBRepository(self::AGENDAMENTO_PROCEDIMENTO_ENTITY_TYPE)
                        ->restoreDeleted($child->getId());

                    $reloaded = $this->entityManager->getEntityById(self::AGENDAMENTO_PROCEDIMENTO_ENTITY_TYPE, $child->getId());

                    if ($reloaded instanceof Entity) {
                        $child = $reloaded;
                    }
                }

                $child->set([
                    'agendamentoId' => $agendamentoLocalId,
                    'procedimentoTipoId' => $procedimentoTipoLocalId,
                    'quantidade' => $row['quantidade'],
                    'procedimentoNome' => $row['nome'],
                    'teamsIds' => $teamIdList,
                ]);

                $this->entityManager->saveEntity($child, [
                    SaveOption::SILENT => true,
                    SaveOption::SKIP_MODIFIED_BY => true,
                ]);
            } catch (Throwable $e) {
                $this->log->warning(
                    "FeatureIntegrationClinicaNasNuvensAgendamento: failed to sync procedimento tipo links for agendamento '" .
                    $agendamentoLocalId . "', credential '" . $credentialId . "', procedimentoTipoId '" .
                    $remoteProcedimentoTipoId . "': " . $e->getMessage()
                );
            }
        }

        $processedProcedimentoTipoIds = array_values(array_unique($processedProcedimentoTipoIds));

        $activeRows = $this->entityManager
            ->getRDBRepository(self::AGENDAMENTO_PROCEDIMENTO_ENTITY_TYPE)
            ->where([
                'agendamentoId' => $agendamentoLocalId,
                'deleted' => false,
            ])
            ->find();

        foreach ($activeRows as $activeRow) {
            $procedimentoTipoId = $this->normalizeNullableString($activeRow->get('procedimentoTipoId'));

            if ($procedimentoTipoId !== null && in_array($procedimentoTipoId, $processedProcedimentoTipoIds, true)) {
                continue;
            }

            $this->entityManager->removeEntity($activeRow, [
                SaveOption::SILENT => true,
                SaveOption::SKIP_MODIFIED_BY => true,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array{quantidade: int, nome: ?string}>
     */
    private function collectProcedimentoTipoAggregatesFromPayload(array $payload): array
    {
        $procedimentos = $payload['procedimentos'] ?? null;

        if (!is_array($procedimentos)) {
            return [];
        }

        $aggregated = [];

        foreach ($procedimentos as $procedimento) {
            if (!is_array($procedimento)) {
                continue;
            }

            $procedimentoTipoId = $this->normalizeNullableString($procedimento['idTipoProcedimento'] ?? null);

            if ($procedimentoTipoId === null) {
                continue;
            }

            $quantidade = $this->normalizeProcedimentoQuantidade($procedimento['quantidade'] ?? null);
            $nome = $this->normalizeNullableString($procedimento['nome'] ?? null);

            if (!array_key_exists($procedimentoTipoId, $aggregated)) {
                $aggregated[$procedimentoTipoId] = [
                    'quantidade' => 0,
                    'nome' => $nome,
                ];
            }

            $aggregated[$procedimentoTipoId]['quantidade'] += $quantidade;

            if ($aggregated[$procedimentoTipoId]['nome'] === null && $nome !== null) {
                $aggregated[$procedimentoTipoId]['nome'] = $nome;
            }
        }

        return $aggregated;
    }

    /**
     * @param mixed $value
     */
    private function normalizeProcedimentoQuantidade($value): int
    {
        if ($value === null) {
            return 1;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : 1;
        }

        if (is_float($value)) {
            $normalized = (int) round($value);

            return $normalized > 0 ? $normalized : 1;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '' || !is_numeric($trimmed)) {
                return 1;
            }

            $normalized = (int) round((float) $trimmed);

            return $normalized > 0 ? $normalized : 1;
        }

        return 1;
    }

    /**
     * @param string[] $teamIdList
     */
    private function findOrRestoreOrCreateProcedimentoTipoAnchor(
        string $remoteProcedimentoTipoId,
        string $credentialId,
        array $teamIdList,
    ): ?Entity {
        $procedimentoTipo = $this->findProcedimentoTipoAnchorIncludingDeleted($remoteProcedimentoTipoId, $credentialId);

        if ($procedimentoTipo) {
            $procedimentoTipo = $this->restoreProcedimentoTipoIfDeleted($procedimentoTipo);

            if ($procedimentoTipo) {
                $this->mergeTeamsIntoProcedimentoTipoAnchor($procedimentoTipo, $teamIdList);
            }

            return $procedimentoTipo;
        }

        return $this->createProcedimentoTipoAnchorWithConflictRecovery(
            $remoteProcedimentoTipoId,
            $credentialId,
            $teamIdList,
        );
    }

    private function findProcedimentoTipoAnchorIncludingDeleted(string $procedimentoTipoId, string $credentialId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from(self::PROCEDIMENTO_TIPO_ENTITY_TYPE)
            ->where([
                'procedimentoTipoId' => $procedimentoTipoId,
                'credentialId' => $credentialId,
            ])
            ->withDeleted()
            ->order('deleted', 'ASC')
            ->build();

        return $this->entityManager
            ->getRDBRepository(self::PROCEDIMENTO_TIPO_ENTITY_TYPE)
            ->clone($query)
            ->findOne();
    }

    private function restoreProcedimentoTipoIfDeleted(Entity $procedimentoTipo): ?Entity
    {
        if (!$procedimentoTipo->get('deleted')) {
            return $procedimentoTipo;
        }

        $this->entityManager
            ->getRDBRepository(self::PROCEDIMENTO_TIPO_ENTITY_TYPE)
            ->restoreDeleted($procedimentoTipo->getId());

        return $this->entityManager->getEntityById(self::PROCEDIMENTO_TIPO_ENTITY_TYPE, $procedimentoTipo->getId());
    }

    /**
     * @param string[] $teamIdList
     */
    private function mergeTeamsIntoProcedimentoTipoAnchor(Entity $procedimentoTipo, array $teamIdList): void
    {
        $existingTeamIdList = $this->extractTeamIdListForEntity($procedimentoTipo, self::PROCEDIMENTO_TIPO_ENTITY_TYPE);
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

    /**
     * @param string[] $teamIdList
     */
    private function createProcedimentoTipoAnchorWithConflictRecovery(
        string $remoteProcedimentoTipoId,
        string $credentialId,
        array $teamIdList,
    ): ?Entity {
        try {
            return $this->entityManager->createEntity(self::PROCEDIMENTO_TIPO_ENTITY_TYPE, [
                'procedimentoTipoId' => $remoteProcedimentoTipoId,
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

            $existing = $this->findProcedimentoTipoAnchorIncludingDeleted($remoteProcedimentoTipoId, $credentialId);

            if (!$existing) {
                throw $e;
            }

            $existing = $this->restoreProcedimentoTipoIfDeleted($existing);

            if ($existing) {
                $this->mergeTeamsIntoProcedimentoTipoAnchor($existing, $teamIdList);
            }

            return $existing;
        }
    }

    private function findAgendamentoProcedimentoIncludingDeleted(
        string $agendamentoLocalId,
        string $procedimentoTipoLocalId,
    ): ?Entity {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from(self::AGENDAMENTO_PROCEDIMENTO_ENTITY_TYPE)
            ->where([
                'agendamentoId' => $agendamentoLocalId,
                'procedimentoTipoId' => $procedimentoTipoLocalId,
            ])
            ->withDeleted()
            ->order('deleted', 'ASC')
            ->build();

        return $this->entityManager
            ->getRDBRepository(self::AGENDAMENTO_PROCEDIMENTO_ENTITY_TYPE)
            ->clone($query)
            ->findOne();
    }

    /**
     * @param string[] $teamIdList
     */
    private function createAgendamentoProcedimentoWithConflictRecovery(
        string $agendamentoLocalId,
        string $procedimentoTipoLocalId,
        array $teamIdList,
    ): ?Entity {
        try {
            return $this->entityManager->createEntity(self::AGENDAMENTO_PROCEDIMENTO_ENTITY_TYPE, [
                'agendamentoId' => $agendamentoLocalId,
                'procedimentoTipoId' => $procedimentoTipoLocalId,
                'teamsIds' => $teamIdList,
            ], [
                SaveOption::SILENT => true,
            ]);
        } catch (Throwable $e) {
            if (!$this->isDuplicateConstraintViolation($e)) {
                throw $e;
            }

            return $this->findAgendamentoProcedimentoIncludingDeleted($agendamentoLocalId, $procedimentoTipoLocalId);
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

    private function extractRawPacienteIdFromInput(stdClass $data): ?string
    {
        if (!property_exists($data, 'idPaciente')) {
            return null;
        }

        return $this->normalizeNullableString($data->idPaciente);
    }

    private function extractRawProfissionalIdFromInput(stdClass $data): ?string
    {
        if (!property_exists($data, 'idProfissional')) {
            return null;
        }

        return $this->normalizeNullableString($data->idProfissional);
    }

    private function extractRawPessoaExecutorIdFromInput(stdClass $data): ?string
    {
        if (!property_exists($data, 'idPessoaExecutor')) {
            return null;
        }

        return $this->normalizeNullableString($data->idPessoaExecutor);
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

        $localProfissionalAnchor = $this->findLocalProfissionalAnchor(
            $this->normalizeNullableString($entity->get('idProfissional')),
            $this->normalizeNullableString($entity->get('idPessoaExecutor')),
            $credentialId,
        );

        if ($localProfissionalAnchor['localId'] !== null) {
            $entity->set('profissionalAnchorId', $localProfissionalAnchor['localId']);
            $entity->set('profissionalAnchorName', $localProfissionalAnchor['localName']);

            if (
                $localProfissionalAnchor['localName'] !== null &&
                !$this->hasNonBlankValue($entity->get('profissional'))
            ) {
                $entity->set('profissional', $localProfissionalAnchor['localName']);
            }
        }

        $localConvenioTipoAnchor = $this->findLocalConvenioTipoAnchor(
            $this->normalizeNullableString($entity->get('idTipoConvenio')),
            $credentialId,
        );

        if ($localConvenioTipoAnchor['localId'] !== null) {
            $entity->set('convenioTipoAnchorId', $localConvenioTipoAnchor['localId']);
            $entity->set('convenioTipoAnchorName', $localConvenioTipoAnchor['localName']);
        }

        $localConsultaTipoAnchor = $this->findLocalConsultaTipoAnchor(
            $this->normalizeNullableString($entity->get('idTipoConsulta')),
            $credentialId,
        );

        if ($localConsultaTipoAnchor['localId'] !== null) {
            $entity->set('consultaTipoAnchorId', $localConsultaTipoAnchor['localId']);
            $entity->set('consultaTipoAnchorName', $localConsultaTipoAnchor['localName']);
        }

        $billingSnapshot = $this->findLatestFaturamentoSnapshot($entity);

        if ($billingSnapshot !== null) {
            $entity->set('valorFaturamentos', $billingSnapshot['valor']);
            $entity->set('valorFaturamentosCurrency', $billingSnapshot['valorCurrency']);
        }

        $localConvenioTipoIdForPricing = $localConvenioTipoAnchor['localId'];
        $procedimentosSnapshot = $this->computeValorProcedimentos($entity, $localConvenioTipoIdForPricing);

        if ($procedimentosSnapshot !== null) {
            $entity->set('valorProcedimentos', $procedimentosSnapshot['valor']);
            $entity->set('valorProcedimentosCurrency', $procedimentosSnapshot['valorCurrency']);
        }

        $this->computeAndSetValorFinanceiro($entity, $billingSnapshot, $procedimentosSnapshot);
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
     * @return array{localId: ?string, localName: ?string}
     */
    private function findLocalProfissionalAnchor(
        ?string $remoteProfissionalId,
        ?string $remotePessoaExecutorId,
        ?string $credentialId,
    ): array {
        if ((!$remoteProfissionalId && !$remotePessoaExecutorId) || !$credentialId) {
            return ['localId' => null, 'localName' => null];
        }

        if ($remoteProfissionalId) {
            $cacheKeyByProfissional = $credentialId . '::pid::' . $remoteProfissionalId;

            if (isset($this->profissionalAnchorCache[$cacheKeyByProfissional])) {
                return $this->profissionalAnchorCache[$cacheKeyByProfissional];
            }
        }

        if ($remotePessoaExecutorId) {
            $cacheKeyByPessoa = $credentialId . '::pessoa::' . $remotePessoaExecutorId;

            if (isset($this->profissionalAnchorCache[$cacheKeyByPessoa])) {
                return $this->profissionalAnchorCache[$cacheKeyByPessoa];
            }
        }

        $queryWhere = [
            'credentialId' => $credentialId,
            'deleted' => false,
        ];

        if ($remoteProfissionalId) {
            $queryWhere['profissionalId'] = $remoteProfissionalId;
        } elseif ($remotePessoaExecutorId) {
            $queryWhere['idPessoa'] = $remotePessoaExecutorId;
        }

        $profissional = $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProfissional')
            ->select(['id', 'name', 'profissionalId', 'idPessoa'])
            ->where($queryWhere)
            ->findOne();

        $resolved = [
            'localId' => $profissional?->getId(),
            'localName' => $profissional && is_string($profissional->get('name')) && trim((string) $profissional->get('name')) !== ''
                ? trim((string) $profissional->get('name'))
                : null,
        ];

        if ($profissional) {
            $this->updateProfissionalAnchorCache($credentialId, $profissional);
        }

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
        ?string $localProfissionalId,
        ?string $localConvenioTipoId,
        ?string $localConsultaTipoId,
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

        if ($entity->getFetched('profissionalAnchorId') !== $localProfissionalId) {
            $toPersist['profissionalAnchorId'] = $localProfissionalId;
        }

        if ($entity->getFetched('convenioTipoAnchorId') !== $localConvenioTipoId) {
            $toPersist['convenioTipoAnchorId'] = $localConvenioTipoId;
        }

        if ($entity->getFetched('consultaTipoAnchorId') !== $localConsultaTipoId) {
            $toPersist['consultaTipoAnchorId'] = $localConsultaTipoId;
        }

        if ($entity->getFetched('syncStatus') !== $syncStatus) {
            $toPersist['syncStatus'] = $syncStatus;
        }

        if ($billingSnapshot !== null) {
            if ($entity->getFetched('valorFaturamentos') !== $billingSnapshot['valor']) {
                $toPersist['valorFaturamentos'] = $billingSnapshot['valor'];
            }

            if ($entity->getFetched('valorFaturamentosCurrency') !== $billingSnapshot['valorCurrency']) {
                $toPersist['valorFaturamentosCurrency'] = $billingSnapshot['valorCurrency'];
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
     * Compute the total valor from ProcedimentoConvenio pricing rows for all
     * AgendamentoProcedimento items linked to this agendamento.
     *
     * For each item: (precoPaciente + precoConvenio) * quantidade.
     *
     * @return array{valor: float, valorCurrency: string}|null
     */
    private function computeValorProcedimentos(Entity $entity, ?string $localConvenioTipoId): ?array
    {
        $agendamentoLocalId = $this->normalizeNullableString($entity->getId());

        if (!$agendamentoLocalId || !$localConvenioTipoId) {
            return null;
        }

        $agendamentoProcedimentos = $this->entityManager
            ->getRDBRepository(self::AGENDAMENTO_PROCEDIMENTO_ENTITY_TYPE)
            ->select(['procedimentoTipoId', 'quantidade'])
            ->where([
                'agendamentoId' => $agendamentoLocalId,
                'deleted' => false,
            ])
            ->find();

        $total = 0.0;
        $hasAnyPricing = false;

        foreach ($agendamentoProcedimentos as $item) {
            $procedimentoTipoLocalId = $this->normalizeNullableString($item->get('procedimentoTipoId'));

            if (!$procedimentoTipoLocalId) {
                continue;
            }

            $quantidade = $item->get('quantidade');
            $quantidade = is_int($quantidade) && $quantidade > 0 ? $quantidade : 1;

            $pricingRow = $this->entityManager
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProcedimentoConvenio')
                ->select(['precoPaciente', 'precoConvenio'])
                ->where([
                    'procedimentoTipoId' => $procedimentoTipoLocalId,
                    'convenioTipoId' => $localConvenioTipoId,
                    'deleted' => false,
                    'isActive' => true,
                ])
                ->findOne();

            if (!$pricingRow) {
                continue;
            }

            $precoPaciente = $this->normalizeNumericValue($pricingRow->get('precoPaciente'));
            $precoConvenio = $this->normalizeNumericValue($pricingRow->get('precoConvenio'));

            $lineTotal = ($precoPaciente + $precoConvenio) * $quantidade;
            $total += $lineTotal;
            $hasAnyPricing = true;
        }

        if (!$hasAnyPricing) {
            return null;
        }

        return [
            'valor' => round($total, 2),
            'valorCurrency' => 'BRL',
        ];
    }

    /**
     * @param mixed $value
     */
    private function normalizeNumericValue($value): float
    {
        if ($value === null) {
            return 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    /**
     * Compute valorFinanceiro: valorFaturamentos if present, else valorProcedimentos, else 0.
     *
     * @param array{valor: float, valorCurrency: string}|null $billingSnapshot
     * @param array{valor: float, valorCurrency: string}|null $procedimentosSnapshot
     */
    private function computeAndSetValorFinanceiro(
        Entity $entity,
        ?array $billingSnapshot,
        ?array $procedimentosSnapshot,
    ): void {
        if ($billingSnapshot !== null && $billingSnapshot['valor'] > 0) {
            $entity->set('valorFinanceiro', $billingSnapshot['valor']);
            $entity->set('valorFinanceiroCurrency', $billingSnapshot['valorCurrency']);

            return;
        }

        if ($procedimentosSnapshot !== null && $procedimentosSnapshot['valor'] > 0) {
            $entity->set('valorFinanceiro', $procedimentosSnapshot['valor']);
            $entity->set('valorFinanceiroCurrency', $procedimentosSnapshot['valorCurrency']);

            return;
        }

        $entity->set('valorFinanceiro', 0.0);
        $entity->set('valorFinanceiroCurrency', 'BRL');
    }

    /**
     * Persist valorProcedimentos and valorFinanceiro after enrichment.
     *
     * @param array{valor: float, valorCurrency: string}|null $billingSnapshot
     * @param array{valor: float, valorCurrency: string}|null $procedimentosSnapshot
     */
    private function persistValorFields(
        Entity $entity,
        ?array $billingSnapshot,
        ?array $procedimentosSnapshot,
    ): void {
        $toPersist = [];

        if ($procedimentosSnapshot !== null) {
            if ($entity->getFetched('valorProcedimentos') !== $procedimentosSnapshot['valor']) {
                $toPersist['valorProcedimentos'] = $procedimentosSnapshot['valor'];
            }

            if ($entity->getFetched('valorProcedimentosCurrency') !== $procedimentosSnapshot['valorCurrency']) {
                $toPersist['valorProcedimentosCurrency'] = $procedimentosSnapshot['valorCurrency'];
            }
        }

        $currentValorFinanceiro = $entity->get('valorFinanceiro');
        $currentValorFinanceiroCurrency = $entity->get('valorFinanceiroCurrency');

        if ($entity->getFetched('valorFinanceiro') !== $currentValorFinanceiro) {
            $toPersist['valorFinanceiro'] = $currentValorFinanceiro;
        }

        if ($entity->getFetched('valorFinanceiroCurrency') !== $currentValorFinanceiroCurrency) {
            $toPersist['valorFinanceiroCurrency'] = $currentValorFinanceiroCurrency;
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
                "FeatureIntegrationClinicaNasNuvensAgendamento: failed to persist valor fields for '" .
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
}
