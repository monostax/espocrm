<?php

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootAccountMembershipOrchestrator;
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ChatwootAccountMembershipOrchestratorTest extends TestCase
{
    public function testResolvesHumanIdentityWithIntegrationUsersExcluded(): void
    {
        $human = $this->createMock(Entity::class);

        $this->assertSame($human, $this->resolve([$human]));
    }

    public function testDoesNotChooseBetweenAmbiguousHumanIdentities(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('linked to multiple Chatwoot users');

        $this->resolve([
            $this->createMock(Entity::class),
            $this->createMock(Entity::class),
        ]);
    }

    public function testAllowsProvisioningWhenNoHumanIdentityIsLinked(): void
    {
        $this->assertNull($this->resolve([]));
    }

    /** @param Entity[] $humanIdentities */
    private function resolve(array $humanIdentities): ?Entity
    {
        $builder = $this->createMock(RDBSelectBuilder::class);
        // The concierge exclusion must happen before applying the limit, so a
        // newer integration identity cannot displace a legitimate human user.
        $builder->expects($this->once())->method('where')->with([
            'platformId' => 'platform',
            'assignedUserId' => 'crm-user',
            'conciergeForAccount.id' => null,
        ])->willReturnSelf();
        $builder->method('distinct')->willReturnSelf();
        $builder->method('limit')->willReturnSelf();
        $builder->method('find')->willReturn(new EntityCollection($humanIdentities));

        $repository = $this->createMock(RDBRepository::class);
        $repository->expects($this->once())->method('leftJoin')
            ->with('conciergeForAccount')->willReturn($builder);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepository')->with('ChatwootUser')->willReturn($repository);
        $entityManager->expects($this->never())->method('saveEntity');

        $api = $this->createMock(ChatwootApiClient::class);
        $api->expects($this->never())->method('createUser');
        $api->expects($this->never())->method('attachUserToAccount');

        $service = new ChatwootAccountMembershipOrchestrator(
            $entityManager,
            $api,
            $this->createMock(ChatwootAccountUserMembershipService::class),
            $this->createMock(Log::class),
            $this->createMock(Acl::class),
        );

        return (new ReflectionMethod($service, 'findChatwootUser'))
            ->invoke($service, 'platform', 'crm-user');
    }
}
