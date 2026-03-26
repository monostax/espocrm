<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer as RecordServiceContainer;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensWebClient;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensWebCredentialHelper;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Scheduled job that finds Agendamento anchors with statusFaturamento = FATURADO
 * but no linked Faturamento entities, and attempts to re-discover and create
 * the missing faturamento anchors from the remote CNN system.
 */
class RepairFaturadoAgendamentosWithoutFaturamento implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private RecordServiceContainer $recordServiceContainer,
        private ClinicaNasNuvensWebClient $webClient,
        private ClinicaNasNuvensWebCredentialHelper $webCredentialHelper,
        private Log $log,
    ) {}

    public function run(): void
    {
        $this->log->info(
            'RepairFaturadoAgendamentosWithoutFaturamento: Starting repair scan.'
        );

        $agendamentos = $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensAgendamento')
            ->where([
                'statusFaturamento' => 'FATURADO',
                'agendamentoId!=' => null,
            ])
            ->find();

        $total = 0;
        $repaired = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($agendamentos as $agendamento) {
            $entityId = $agendamento->getId();
            $agendamentoRemoteId = $agendamento->get('agendamentoId');

            if (!is_string($agendamentoRemoteId) || trim($agendamentoRemoteId) === '') {
                continue;
            }

            $linkedFaturamento = $this->entityManager
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensFaturamento')
                ->where([
                    'agendamentoId' => $entityId,
                ])
                ->findOne();

            if ($linkedFaturamento) {
                continue;
            }

            $total++;

            try {
                $teamIdList = $this->extractTeamIdList($agendamento);
                $webCredential = $this->webCredentialHelper->findAccessibleCredentialForTeamIds($teamIdList);

                if (!$webCredential) {
                    $this->log->warning(
                        "RepairFaturadoAgendamentosWithoutFaturamento: No web credential available " .
                        "for agendamento '{$entityId}' (remote '{$agendamentoRemoteId}'). Skipping."
                    );

                    $skipped++;

                    continue;
                }

                $created = $this->discoverAndCreateFaturamentos(
                    $agendamento,
                    $webCredential,
                    $teamIdList,
                );

                if ($created > 0) {
                    $repaired++;

                    $this->log->info(
                        "RepairFaturadoAgendamentosWithoutFaturamento: Created {$created} faturamento(s) " .
                        "for agendamento '{$entityId}' (remote '{$agendamentoRemoteId}')."
                    );
                } else {
                    $skipped++;

                    $this->log->info(
                        "RepairFaturadoAgendamentosWithoutFaturamento: No remote faturamentos found " .
                        "for agendamento '{$entityId}' (remote '{$agendamentoRemoteId}')."
                    );
                }
            } catch (Throwable $e) {
                $failed++;

                $this->log->error(
                    "RepairFaturadoAgendamentosWithoutFaturamento: Failed to repair agendamento " .
                    "'{$entityId}' (remote '{$agendamentoRemoteId}'): " . $e->getMessage()
                );
            }
        }

        $this->log->info(
            "RepairFaturadoAgendamentosWithoutFaturamento: Completed. " .
            "Candidates: {$total}, Repaired: {$repaired}, Skipped: {$skipped}, Failed: {$failed}"
        );
    }

    /**
     * @param string[] $teamIdList
     */
    private function discoverAndCreateFaturamentos(
        Entity $agendamento,
        Entity $webCredential,
        array $teamIdList,
    ): int {
        $agendamentoRemoteId = $agendamento->get('agendamentoId');

        $faturamentoIdList = $this->webClient->getFaturamentoIdsByAgendamentoId(
            $webCredential,
            $agendamentoRemoteId,
        );

        if ($faturamentoIdList === []) {
            return 0;
        }

        $faturamentoService = $this->recordServiceContainer->get('FeatureIntegrationClinicaNasNuvensFaturamento');
        $webCredentialId = $webCredential->getId();
        $created = 0;

        foreach ($faturamentoIdList as $faturamentoId) {
            try {
                $payload = (object) [
                    'faturamentoId' => $faturamentoId,
                    'credentialId' => $webCredentialId,
                    'teamsIds' => $teamIdList,
                ];

                $faturamentoService->create($payload, CreateParams::create());
                $created++;
            } catch (Throwable $e) {
                $this->log->warning(
                    "RepairFaturadoAgendamentosWithoutFaturamento: Failed to create faturamento anchor " .
                    "'{$faturamentoId}' for agendamento '{$agendamento->getId()}': " . $e->getMessage()
                );
            }
        }

        return $created;
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

        $entityId = $entity->getId();

        if (!is_string($entityId) || $entityId === '') {
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
            $teamId = $row->get('teamId');

            if (is_string($teamId) && $teamId !== '') {
                $resolved[] = $teamId;
            }
        }

        return array_values(array_unique($resolved));
    }
}
