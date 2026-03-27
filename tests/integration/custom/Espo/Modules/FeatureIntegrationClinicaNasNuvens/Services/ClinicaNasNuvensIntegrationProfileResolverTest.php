<?php

namespace tests\integration\custom\Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensIntegrationProfileResolver;
use Espo\ORM\Entity;
use tests\integration\Core\BaseTestCase;

class ClinicaNasNuvensIntegrationProfileResolverTest extends BaseTestCase
{
    public function testResolveSingleActiveProfileForTeam(): void
    {
        $team = $this->createTeam('resolver-team-single');
        $tenant = $this->createTenant('resolver-tenant-single', $team);
        $apiCredential = $this->createApiCredential($team, 'cnn');
        $webCredential = $this->createWebCredential($team);
        $profile = $this->createProfile($tenant, $team, $apiCredential, $webCredential, 'Profile A');

        $resolved = $this->getResolver()->resolveForTeamIds([$team->getId()]);

        $this->assertNotNull($resolved);
        $this->assertSame($profile->getId(), $resolved['profile']->getId());
        $this->assertSame($apiCredential->getId(), $resolved['apiCredential']->getId());
        $this->assertSame($webCredential->getId(), $resolved['webCredential']->getId());
    }

    public function testResolveReturnsNullWhenNoActiveProfileMatches(): void
    {
        $team = $this->createTeam('resolver-team-none');

        $resolved = $this->getResolver()->resolveForTeamIds([$team->getId()]);

        $this->assertNull($resolved);
    }

    public function testResolveFailsWhenMultipleActiveProfilesMatchTeam(): void
    {
        $team = $this->createTeam('resolver-team-dup');
        $tenant = $this->createTenant('resolver-tenant-dup', $team);
        $apiCredentialA = $this->createApiCredential($team, 'cnn');
        $webCredentialA = $this->createWebCredential($team);
        $apiCredentialB = $this->createApiCredential($team, 'clinicaNasNuvens');
        $webCredentialB = $this->createWebCredential($team);

        $this->createProfile($tenant, $team, $apiCredentialA, $webCredentialA, 'Profile A', false);
        $this->createProfile($tenant, $team, $apiCredentialB, $webCredentialB, 'Profile B', false);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Multiple active Clinica Nas Nuvens integration profiles');

        $this->getResolver()->resolveForTeamIds([$team->getId()]);
    }

    public function testResolveBlocksWhenProfileMigrationIsInProgress(): void
    {
        $team = $this->createTeam('resolver-team-migration');
        $tenant = $this->createTenant('resolver-tenant-migration', $team);
        $apiCredential = $this->createApiCredential($team, 'cnn');
        $webCredential = $this->createWebCredential($team);

        $this->createProfile(
            $tenant,
            $team,
            $apiCredential,
            $webCredential,
            'Profile Migration',
            true,
            'inProgress',
        );

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('profile migration is in progress');

        $this->getResolver()->resolveForTeamIds([$team->getId()]);
    }

    public function testResolveForProfileIdHappyPath(): void
    {
        $team = $this->createTeam('resolver-profile-happy-team');
        $tenant = $this->createTenant('resolver-profile-happy-tenant', $team);
        $apiCredential = $this->createApiCredential($team, 'cnn');
        $webCredential = $this->createWebCredential($team);
        $profile = $this->createProfile($tenant, $team, $apiCredential, $webCredential, 'Profile Happy');

        $resolved = $this->getResolver()->resolveForProfileId($profile->getId());

        $this->assertSame($profile->getId(), $resolved['profile']->getId());
        $this->assertSame($apiCredential->getId(), $resolved['apiCredential']->getId());
        $this->assertSame($webCredential->getId(), $resolved['webCredential']->getId());
    }

    public function testResolveForProfileIdFailsWhenProfileNotFound(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('profile not found');

        $this->getResolver()->resolveForProfileId('missing-profile-id');
    }

    public function testResolveForProfileIdFailsWhenProfileIsInactive(): void
    {
        $team = $this->createTeam('resolver-profile-inactive-team');
        $tenant = $this->createTenant('resolver-profile-inactive-tenant', $team);
        $apiCredential = $this->createApiCredential($team, 'cnn');
        $webCredential = $this->createWebCredential($team);
        $profile = $this->createProfile($tenant, $team, $apiCredential, $webCredential, 'Profile Inactive');

        $profile->set('isActive', false);
        $this->getEntityManager()->saveEntity($profile);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('inactive');

        $this->getResolver()->resolveForProfileId($profile->getId());
    }

    public function testResolveForProfileIdFailsWhenMigrationIsInProgress(): void
    {
        $team = $this->createTeam('resolver-profile-migration-team');
        $tenant = $this->createTenant('resolver-profile-migration-tenant', $team);
        $apiCredential = $this->createApiCredential($team, 'cnn');
        $webCredential = $this->createWebCredential($team);
        $profile = $this->createProfile(
            $tenant,
            $team,
            $apiCredential,
            $webCredential,
            'Profile Migration In Progress',
            true,
            'inProgress',
        );

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('profile migration is in progress');

        $this->getResolver()->resolveForProfileId($profile->getId());
    }

    public function testResolveForProfileIdFailsWhenApiCredentialIsInactive(): void
    {
        $team = $this->createTeam('resolver-profile-api-inactive-team');
        $tenant = $this->createTenant('resolver-profile-api-inactive-tenant', $team);
        $apiCredential = $this->createApiCredential($team, 'cnn');
        $webCredential = $this->createWebCredential($team);
        $profile = $this->createProfile($tenant, $team, $apiCredential, $webCredential, 'Profile API Inactive');

        $apiCredential->set('isActive', false);
        $this->getEntityManager()->saveEntity($apiCredential);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('API credential');

        $this->getResolver()->resolveForProfileId($profile->getId());
    }

    public function testResolveForProfileIdFailsWhenWebCredentialIsInactive(): void
    {
        $team = $this->createTeam('resolver-profile-web-inactive-team');
        $tenant = $this->createTenant('resolver-profile-web-inactive-tenant', $team);
        $apiCredential = $this->createApiCredential($team, 'cnn');
        $webCredential = $this->createWebCredential($team);
        $profile = $this->createProfile($tenant, $team, $apiCredential, $webCredential, 'Profile Web Inactive');

        $webCredential->set('isActive', false);
        $this->getEntityManager()->saveEntity($webCredential);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Web credential');

        $this->getResolver()->resolveForProfileId($profile->getId());
    }

    public function testResolveForProfileIdFailsWhenAclDeniesReadAccess(): void
    {
        $team = $this->createTeam('resolver-profile-acl-team');
        $tenant = $this->createTenant('resolver-profile-acl-tenant', $team);
        $apiCredential = $this->createApiCredential($team, 'cnn');
        $webCredential = $this->createWebCredential($team);
        $profile = $this->createProfile($tenant, $team, $apiCredential, $webCredential, 'Profile ACL');

        $acl = $this->createMock(Acl::class);
        $acl->method('check')->willReturn(false);

        $resolver = new ClinicaNasNuvensIntegrationProfileResolver($this->getEntityManager(), $acl);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('access');

        $resolver->resolveForProfileId($profile->getId());
    }

    private function getResolver(): ClinicaNasNuvensIntegrationProfileResolver
    {
        return $this->getContainer()->getByClass(ClinicaNasNuvensIntegrationProfileResolver::class);
    }

    private function createTeam(string $name): Entity
    {
        return $this->getEntityManager()->createEntity('Team', ['name' => $name]);
    }

    private function createTenant(string $name, Entity $baseTeam): Entity
    {
        return $this->getEntityManager()->createEntity('Tenant', [
            'name' => $name,
            'baseUserTeamId' => $baseTeam->getId(),
        ]);
    }

    private function createApiCredential(Entity $team, string $code): Entity
    {
        $credentialType = $this->findOrCreateCredentialType($code, 'basicAuth');

        return $this->getEntityManager()->createEntity('Credential', [
            'name' => 'API ' . $code . ' ' . uniqid(),
            'credentialTypeId' => $credentialType->getId(),
            'isActive' => true,
            'config' => json_encode(['clinicCid' => '123']),
            'teamsIds' => [$team->getId()],
        ]);
    }

    private function createWebCredential(Entity $team): Entity
    {
        $credentialType = $this->findOrCreateCredentialType('clinicaNasNuvens-web', 'formAuth');

        return $this->getEntityManager()->createEntity('Credential', [
            'name' => 'WEB ' . uniqid(),
            'credentialTypeId' => $credentialType->getId(),
            'isActive' => true,
            'config' => '{}',
            'teamsIds' => [$team->getId()],
        ]);
    }

    private function createProfile(
        Entity $tenant,
        Entity $team,
        Entity $apiCredential,
        Entity $webCredential,
        string $name,
        bool $useHooks = true,
        string $migrationStatus = 'idle',
    ): Entity {
        $profile = $this->getEntityManager()->getNewEntity('FeatureIntegrationClinicaNasNuvensSettings');
        $profile->set([
            'name' => $name,
            'tenantId' => $tenant->getId(),
            'teamsIds' => [$team->getId()],
            'apiCredentialId' => $apiCredential->getId(),
            'webCredentialId' => $webCredential->getId(),
            'isActive' => true,
            'migrationStatus' => $migrationStatus,
        ]);

        $options = $useHooks ? [] : [SaveOption::SKIP_HOOKS => true];
        $this->getEntityManager()->saveEntity($profile, $options);

        return $profile;
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
