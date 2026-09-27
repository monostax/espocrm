<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureCatchUp\Services\Rollout;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class RolloutTest extends TestCase
{
    private function check(array $remote): array
    {
        $account = $this->createMock(Entity::class);
        $account->method('get')->willReturnMap([['platformId', 'platform'], ['chatwootAccountId', 9]]);
        $platform = $this->createMock(Entity::class);
        $platform->method('get')->willReturnMap([
            ['backendUrl', 'http://chatwoot'], ['frontendUrl', 'https://chat.example.test'], ['accessToken', 'test-token'],
        ]);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->with('ChatwootPlatform', 'platform')->willReturn($platform);
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('getAccount')->with('http://chatwoot', 'test-token', 9)->willReturn($remote);
        return (new Rollout($em, $this->createMock(SelectBuilderFactory::class), $api))->forAccount($account);
    }

    public function testExistingAccountsAndOldChatwootVersionsDefaultToDisabled(): void
    {
        $this->expectException(Forbidden::class);
        $this->check(['status' => 'active']);
    }

    public function testDisabledAccountCannotAccessCatchUp(): void
    {
        $this->expectException(Forbidden::class);
        $this->check(['status' => 'active', 'catch_up' => ['enabled' => false]]);
    }

    public function testSuspendedAccountsRemainDisabledEvenWithOptIn(): void
    {
        $this->expectException(Forbidden::class);
        $this->check(['status' => 'suspended', 'catch_up' => ['enabled' => true]]);
    }

    public function testEnabledAccountSharesTheChatwootBudgetScopeAndCaps(): void
    {
        $policy = $this->check(['status' => 'active', 'catch_up' => [
            'enabled' => true, 'instance' => 'https://chat.example.test/', 'dailyLimit' => 999,
        ]]);
        self::assertSame(['enabled' => true, 'instance' => 'https://chat.example.test', 'accountId' => '9', 'dailyLimit' => 50], $policy);
    }
}
