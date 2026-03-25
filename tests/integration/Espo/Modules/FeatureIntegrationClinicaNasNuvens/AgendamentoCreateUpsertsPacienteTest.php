<?php

namespace tests\integration\Espo\Modules\FeatureIntegrationClinicaNasNuvens;

use Espo\Core\Record\CreateParams;
use Espo\Core\Record\DeleteParams;
use Espo\Core\Record\Service;
use Espo\Core\Record\ServiceContainer;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use tests\integration\Core\BaseTestCase;

class AgendamentoCreateUpsertsPacienteTest extends BaseTestCase
{
    public function testCreateReusesExistingActivePacienteAndMergesTeams(): void
    {
        $teamA = $this->createTeam('cnn-a');
        $teamB = $this->createTeam('cnn-b');
        $credential = $this->createCredentialForTeam($teamA);

        $paciente = $this->getEntityManager()->createEntity('FeatureIntegrationClinicaNasNuvensPaciente', [
            'name' => 'Paciente Existing',
            'pacienteId' => 'remote-paciente-1',
            'credentialId' => $credential->getId(),
            'teamsIds' => [$teamA->getId()],
        ]);

        $agendamento = $this->createAgendamento([
            'agendamentoId' => 'ag-create-existing-' . uniqid(),
            'idPaciente' => 'remote-paciente-1',
            'teamsIds' => [$teamB->getId(), $teamA->getId()],
        ]);

        $reloadedAgendamento = $this->getEntityManager()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensAgendamento',
            $agendamento->getId(),
        );

        $this->assertNotNull($reloadedAgendamento);
        $this->assertSame($credential->getId(), $reloadedAgendamento->get('credentialId'));
        $this->assertSame($paciente->getId(), $reloadedAgendamento->get('pacienteId'));

        $reloadedPaciente = $this->getEntityManager()->getEntityById('FeatureIntegrationClinicaNasNuvensPaciente', $paciente->getId());
        $this->assertNotNull($reloadedPaciente);

        $teamsIds = $reloadedPaciente->get('teamsIds');

        $this->assertIsArray($teamsIds);
        $this->assertEqualsCanonicalizing([$teamA->getId(), $teamB->getId()], $teamsIds);
    }

    public function testCreateRestoresSoftDeletedPacienteAndMergesTeams(): void
    {
        $teamA = $this->createTeam('cnn-soft-a');
        $teamB = $this->createTeam('cnn-soft-b');
        $credential = $this->createCredentialForTeam($teamA);

        $paciente = $this->getEntityManager()->createEntity('FeatureIntegrationClinicaNasNuvensPaciente', [
            'name' => 'Paciente Deleted',
            'pacienteId' => 'remote-paciente-deleted',
            'credentialId' => $credential->getId(),
            'teamsIds' => [$teamA->getId()],
        ]);

        $this->getPacienteService()->delete($paciente->getId(), DeleteParams::create());

        $this->createAgendamento([
            'agendamentoId' => 'ag-create-restore-' . uniqid(),
            'idPaciente' => 'remote-paciente-deleted',
            'teamsIds' => [$teamA->getId(), $teamB->getId()],
        ]);

        $reloadedPaciente = $this->getEntityManager()->getEntityById('FeatureIntegrationClinicaNasNuvensPaciente', $paciente->getId());

        $this->assertNotNull($reloadedPaciente);
        $this->assertFalse((bool) $reloadedPaciente->get('deleted'));

        $teamsIds = $reloadedPaciente->get('teamsIds');

        $this->assertIsArray($teamsIds);
        $this->assertEqualsCanonicalizing([$teamA->getId(), $teamB->getId()], $teamsIds);
    }

    public function testCreateCreatesPartialPacienteAnchorWhenMissing(): void
    {
        $team = $this->createTeam('cnn-missing-anchor');
        $credential = $this->createCredentialForTeam($team);

        $agendamento = $this->createAgendamento([
            'agendamentoId' => 'ag-create-missing-' . uniqid(),
            'idPaciente' => 'remote-new-paciente',
            'teamsIds' => [$team->getId()],
        ]);

        $paciente = $this->findPacienteAnchorIncludingDeleted('remote-new-paciente', $credential->getId());

        $this->assertNotNull($paciente);
        $this->assertSame('pending', $paciente->get('syncStatus'));
        $this->assertSame($credential->getId(), $paciente->get('credentialId'));
        $this->assertEqualsCanonicalizing([$team->getId()], $paciente->get('teamsIds'));

        $reloadedAgendamento = $this->getEntityManager()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensAgendamento',
            $agendamento->getId(),
        );

        $this->assertNotNull($reloadedAgendamento);
        $this->assertSame($credential->getId(), $reloadedAgendamento->get('credentialId'));
        $this->assertSame($paciente->getId(), $reloadedAgendamento->get('pacienteId'));
    }

    public function testCreateWithoutAccessibleCredentialKeepsAgendamentoAndSetsSyncStatusError(): void
    {
        $team = $this->createTeam('cnn-no-credential');

        $agendamento = $this->createAgendamento([
            'agendamentoId' => 'ag-create-no-credential-' . uniqid(),
            'idPaciente' => 'remote-without-credential',
            'teamsIds' => [$team->getId()],
        ]);

        $reloadedAgendamento = $this->getEntityManager()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensAgendamento',
            $agendamento->getId(),
        );

        $this->assertNotNull($reloadedAgendamento);
        $this->assertSame('error', $reloadedAgendamento->get('syncStatus'));
        $this->assertNull($reloadedAgendamento->get('credentialId'));
        $this->assertNull($reloadedAgendamento->get('pacienteId'));

        $this->assertSame(0, $this->countPacienteAnchorsByRemotePacienteId('remote-without-credential'));
    }

    public function testCreateConcurrentLikeDuplicateScenarioKeepsSinglePacienteAnchor(): void
    {
        $team = $this->createTeam('cnn-race-team');
        $credential = $this->createCredentialForTeam($team);

        $agendamentoA = $this->createAgendamento([
            'agendamentoId' => 'ag-race-a-' . uniqid(),
            'idPaciente' => 'remote-race-paciente',
            'teamsIds' => [$team->getId()],
        ]);

        $agendamentoB = $this->createAgendamento([
            'agendamentoId' => 'ag-race-b-' . uniqid(),
            'idPaciente' => 'remote-race-paciente',
            'teamsIds' => [$team->getId()],
        ]);

        $pacienteRows = $this->getEntityManager()
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensPaciente')
            ->where([
                'pacienteId' => 'remote-race-paciente',
                'credentialId' => $credential->getId(),
                'deleted' => false,
            ])
            ->find();

        $paciente = null;
        $count = 0;

        foreach ($pacienteRows as $row) {
            $paciente = $row;
            $count++;
        }

        $this->assertSame(1, $count);
        $this->assertNotNull($paciente);

        $reloadedA = $this->getEntityManager()->getEntityById('FeatureIntegrationClinicaNasNuvensAgendamento', $agendamentoA->getId());
        $reloadedB = $this->getEntityManager()->getEntityById('FeatureIntegrationClinicaNasNuvensAgendamento', $agendamentoB->getId());

        $this->assertNotNull($reloadedA);
        $this->assertNotNull($reloadedB);
        $this->assertSame($paciente->getId(), $reloadedA->get('pacienteId'));
        $this->assertSame($paciente->getId(), $reloadedB->get('pacienteId'));
    }

    public function testCreatePersistsCredentialIdDuringCreateFlow(): void
    {
        $team = $this->createTeam('cnn-credential-persist');
        $credential = $this->createCredentialForTeam($team);

        $agendamento = $this->createAgendamento([
            'agendamentoId' => 'ag-credential-persist-' . uniqid(),
            'idPaciente' => 'remote-credential-persist',
            'teamsIds' => [$team->getId()],
        ]);

        $reloaded = $this->getEntityManager()->getEntityById('FeatureIntegrationClinicaNasNuvensAgendamento', $agendamento->getId());

        $this->assertNotNull($reloaded);
        $this->assertSame($credential->getId(), $reloaded->get('credentialId'));
    }

    private function getEntityManagerInstance(): EntityManager
    {
        return $this->getEntityManager();
    }

    private function getAgendamentoService(): Service
    {
        return $this->getContainer()
            ->getByClass(ServiceContainer::class)
            ->get('FeatureIntegrationClinicaNasNuvensAgendamento');
    }

    private function getPacienteService(): Service
    {
        return $this->getContainer()
            ->getByClass(ServiceContainer::class)
            ->get('FeatureIntegrationClinicaNasNuvensPaciente');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createAgendamento(array $data): Entity
    {
        return $this->getAgendamentoService()->create((object) $data, CreateParams::create());
    }

    private function createTeam(string $name): Entity
    {
        return $this->getEntityManagerInstance()->createEntity('Team', ['name' => $name]);
    }

    private function createCredentialForTeam(Entity $team): Entity
    {
        $credentialType = $this->findOrCreateCredentialType();

        return $this->getEntityManagerInstance()->createEntity('Credential', [
            'name' => 'CNN Credential ' . uniqid(),
            'credentialTypeId' => $credentialType->getId(),
            'isActive' => true,
            'config' => '{}',
            'teamsIds' => [$team->getId()],
        ]);
    }

    private function findOrCreateCredentialType(): Entity
    {
        $repository = $this->getEntityManagerInstance()->getRDBRepository('CredentialType');

        $credentialType = $repository
            ->where(['code' => ['cnn', 'clinicaNasNuvens']])
            ->order('createdAt', 'ASC')
            ->findOne();

        if ($credentialType) {
            return $credentialType;
        }

        return $this->getEntityManagerInstance()->createEntity('CredentialType', [
            'name' => 'CNN Test Type',
            'code' => 'cnn',
            'category' => 'basicAuth',
            'schema' => '{}',
            'encryptionFields' => [],
        ]);
    }

    private function findPacienteAnchorIncludingDeleted(string $remotePacienteId, string $credentialId): ?Entity
    {
        $query = $this->getEntityManagerInstance()
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensPaciente')
            ->where([
                'pacienteId' => $remotePacienteId,
                'credentialId' => $credentialId,
            ])
            ->withDeleted()
            ->build();

        return $this->getEntityManagerInstance()
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensPaciente')
            ->clone($query)
            ->findOne();
    }

    private function countPacienteAnchorsByRemotePacienteId(string $remotePacienteId): int
    {
        $query = $this->getEntityManagerInstance()
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensPaciente')
            ->where(['pacienteId' => $remotePacienteId])
            ->withDeleted()
            ->build();

        $collection = $this->getEntityManagerInstance()
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensPaciente')
            ->clone($query)
            ->find();

        $count = 0;

        foreach ($collection as $unused) {
            $count++;
        }

        return $count;
    }
}
