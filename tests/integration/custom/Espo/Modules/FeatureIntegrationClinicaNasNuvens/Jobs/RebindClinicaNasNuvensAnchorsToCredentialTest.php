<?php

namespace tests\integration\custom\Espo\Modules\FeatureIntegrationClinicaNasNuvens\Jobs;

use Espo\Core\Job\Job\Data as JobData;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Jobs\RebindClinicaNasNuvensAnchorsToCredential;
use Espo\ORM\Entity;
use tests\integration\Core\BaseTestCase;

class RebindClinicaNasNuvensAnchorsToCredentialTest extends BaseTestCase
{
    public function testRunIsIdempotentAndHandlesDuplicateConflictRebind(): void
    {
        $team = $this->createTeam('job-team');
        $tenant = $this->createTenant('job-tenant', $team);

        $oldApi = $this->createApiCredential($team, 'old-api', 'clinic-123');
        $newApi = $this->createApiCredential($team, 'new-api', 'clinic-123');
        $oldWeb = $this->createWebCredential($team, 'old-web');
        $newWeb = $this->createWebCredential($team, 'new-web');

        $profile = $this->createProfile($tenant, $team, $newApi, $newWeb, 'inProgress');

        $oldPaciente = $this->createPacienteAnchor($oldApi, $team, 'remote-paciente-1');
        $duplicatePaciente = $this->createPacienteAnchor($newApi, $team, 'remote-paciente-1');

        $oldAgendamento = $this->createAgendamentoAnchor($oldApi, $team, 'remote-agendamento-1', $duplicatePaciente);
        $duplicateAgendamento = $this->createAgendamentoAnchor($newApi, $team, 'remote-agendamento-1', $duplicatePaciente);

        $oldFaturamento = $this->createFaturamentoAnchor(
            $oldWeb,
            $team,
            'remote-faturamento-1',
            $duplicateAgendamento,
            $duplicatePaciente,
        );

        $duplicateFaturamento = $this->createFaturamentoAnchor(
            $newWeb,
            $team,
            'remote-faturamento-1',
            $duplicateAgendamento,
            $duplicatePaciente,
        );

        $this->runJob($profile, $oldApi, $newApi, $oldWeb, $newWeb);
        $this->runJob($profile, $oldApi, $newApi, $oldWeb, $newWeb);

        $reloadedProfile = $this->getEntityManager()->getEntityById('FeatureIntegrationClinicaNasNuvensSettings', $profile->getId());
        $this->assertNotNull($reloadedProfile);
        $this->assertSame('completed', $reloadedProfile->get('migrationStatus'));

        $reloadedOldApi = $this->getEntityManager()->getEntityById('Credential', $oldApi->getId());
        $reloadedOldWeb = $this->getEntityManager()->getEntityById('Credential', $oldWeb->getId());
        $this->assertNotNull($reloadedOldApi);
        $this->assertNotNull($reloadedOldWeb);
        $this->assertFalse((bool) $reloadedOldApi->get('isActive'));
        $this->assertFalse((bool) $reloadedOldWeb->get('isActive'));

        $reloadedOldPaciente = $this->getEntityManager()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensPaciente',
            $oldPaciente->getId(),
        );

        $reloadedDuplicatePaciente = $this->getEntityManager()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensPaciente',
            $duplicatePaciente->getId(),
        );

        $this->assertNotNull($reloadedOldPaciente);
        $this->assertNotNull($reloadedDuplicatePaciente);
        $this->assertSame($newApi->getId(), $reloadedOldPaciente->get('credentialId'));
        $this->assertTrue((bool) $reloadedDuplicatePaciente->get('deleted'));

        $reloadedOldAgendamento = $this->getEntityManager()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensAgendamento',
            $oldAgendamento->getId(),
        );

        $reloadedDuplicateAgendamento = $this->getEntityManager()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensAgendamento',
            $duplicateAgendamento->getId(),
        );

        $this->assertNotNull($reloadedOldAgendamento);
        $this->assertNotNull($reloadedDuplicateAgendamento);
        $this->assertSame($newApi->getId(), $reloadedOldAgendamento->get('credentialId'));
        $this->assertSame($oldPaciente->getId(), $reloadedOldAgendamento->get('pacienteId'));
        $this->assertTrue((bool) $reloadedDuplicateAgendamento->get('deleted'));

        $reloadedOldFaturamento = $this->getEntityManager()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensFaturamento',
            $oldFaturamento->getId(),
        );

        $reloadedDuplicateFaturamento = $this->getEntityManager()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensFaturamento',
            $duplicateFaturamento->getId(),
        );

        $this->assertNotNull($reloadedOldFaturamento);
        $this->assertNotNull($reloadedDuplicateFaturamento);
        $this->assertSame($newWeb->getId(), $reloadedOldFaturamento->get('credentialId'));
        $this->assertSame($oldAgendamento->getId(), $reloadedOldFaturamento->get('agendamentoId'));
        $this->assertSame($oldPaciente->getId(), $reloadedOldFaturamento->get('pacienteId'));
        $this->assertTrue((bool) $reloadedDuplicateFaturamento->get('deleted'));
    }

    private function runJob(Entity $profile, Entity $oldApi, Entity $newApi, Entity $oldWeb, Entity $newWeb): void
    {
        $job = $this->getContainer()->getByClass(RebindClinicaNasNuvensAnchorsToCredential::class);

        $job->run(JobData::create([
            'profileId' => $profile->getId(),
            'oldApiCredentialId' => $oldApi->getId(),
            'newApiCredentialId' => $newApi->getId(),
            'oldWebCredentialId' => $oldWeb->getId(),
            'newWebCredentialId' => $newWeb->getId(),
        ]));
    }

    private function createTeam(string $name): Entity
    {
        return $this->getEntityManager()->createEntity('Team', ['name' => $name]);
    }

    private function createTenant(string $name, Entity $team): Entity
    {
        return $this->getEntityManager()->createEntity('Tenant', [
            'name' => $name,
            'baseUserTeamId' => $team->getId(),
        ]);
    }

    private function createProfile(Entity $tenant, Entity $team, Entity $apiCredential, Entity $webCredential, string $status): Entity
    {
        return $this->getEntityManager()->createEntity('FeatureIntegrationClinicaNasNuvensSettings', [
            'name' => 'Profile ' . uniqid(),
            'tenantId' => $tenant->getId(),
            'teamsIds' => [$team->getId()],
            'apiCredentialId' => $apiCredential->getId(),
            'webCredentialId' => $webCredential->getId(),
            'isActive' => true,
            'migrationStatus' => $status,
        ]);
    }

    private function createApiCredential(Entity $team, string $name, string $clinicCid): Entity
    {
        $credentialType = $this->findOrCreateCredentialType('cnn', 'basicAuth');

        return $this->getEntityManager()->createEntity('Credential', [
            'name' => $name,
            'credentialTypeId' => $credentialType->getId(),
            'isActive' => true,
            'config' => json_encode(['clinicCid' => $clinicCid]),
            'teamsIds' => [$team->getId()],
        ]);
    }

    private function createWebCredential(Entity $team, string $name): Entity
    {
        $credentialType = $this->findOrCreateCredentialType('clinicaNasNuvens-web', 'formAuth');

        return $this->getEntityManager()->createEntity('Credential', [
            'name' => $name,
            'credentialTypeId' => $credentialType->getId(),
            'isActive' => true,
            'config' => '{}',
            'teamsIds' => [$team->getId()],
        ]);
    }

    private function createPacienteAnchor(Entity $credential, Entity $team, string $remoteId): Entity
    {
        return $this->getEntityManager()->createEntity('FeatureIntegrationClinicaNasNuvensPaciente', [
            'name' => 'Paciente ' . $remoteId,
            'pacienteId' => $remoteId,
            'credentialId' => $credential->getId(),
            'teamsIds' => [$team->getId()],
        ]);
    }

    private function createAgendamentoAnchor(
        Entity $credential,
        Entity $team,
        string $remoteId,
        Entity $paciente,
    ): Entity {
        return $this->getEntityManager()->createEntity('FeatureIntegrationClinicaNasNuvensAgendamento', [
            'name' => 'Agendamento ' . $remoteId,
            'agendamentoId' => $remoteId,
            'credentialId' => $credential->getId(),
            'pacienteId' => $paciente->getId(),
            'teamsIds' => [$team->getId()],
        ]);
    }

    private function createFaturamentoAnchor(
        Entity $credential,
        Entity $team,
        string $remoteId,
        Entity $agendamento,
        Entity $paciente,
    ): Entity {
        return $this->getEntityManager()->createEntity('FeatureIntegrationClinicaNasNuvensFaturamento', [
            'name' => 'Faturamento ' . $remoteId,
            'faturamentoId' => $remoteId,
            'credentialId' => $credential->getId(),
            'agendamentoId' => $agendamento->getId(),
            'pacienteId' => $paciente->getId(),
            'teamsIds' => [$team->getId()],
        ]);
    }

    private function findOrCreateCredentialType(string $code, string $category): Entity
    {
        $repository = $this->getEntityManager()->getRDBRepository('CredentialType');
        $credentialType = $repository->where(['code' => $code])->findOne();

        if ($credentialType) {
            return $credentialType;
        }

        return $this->getEntityManager()->createEntity('CredentialType', [
            'name' => strtoupper($code) . ' Type',
            'code' => $code,
            'category' => $category,
            'schema' => '{}',
            'encryptionFields' => [],
        ]);
    }
}
